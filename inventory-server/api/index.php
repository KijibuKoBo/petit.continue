<?php
require_once __DIR__ . '/lib.php';

/* セッション（HTTPS では Secure 付与） */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();

$VALID_STATUS = ['planned', 'kiji', 'painted', 'shipped'];
$STATUS_LEVEL = ['planned' => 0, 'kiji' => 1, 'painted' => 2, 'shipped' => 3];
$action = $_GET['action'] ?? '';

try {
  $pdo = db();
} catch (Throwable $e) {
  error_log('[inventory] DB connect: ' . $e->getMessage());
  fail('データベースに接続できません。設定を確認してください。', 500);
}

/* CSRF: 公開アクション以外は X-CSRF-Token ヘッダ必須 */
csrf_token(); // ensure session token exists
$PUBLIC_ACTIONS = ['me', 'login', 'setup', 'restore'];
if (!in_array($action, $PUBLIC_ACTIONS, true)) {
  require_csrf();
}

try {
  switch ($action) {

    /* ── 認証 ── */
    case 'me': {
      $count = (int)$pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
      json_out(['user' => current_user(), 'needsSetup' => $count === 0, 'csrfToken' => csrf_token()]);
    }

    case 'setup': {
      $count = (int)$pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
      if ($count > 0) fail('既に管理者が存在します。');
      $b = body();
      $email = strtolower(trim($b['email'] ?? ''));
      $name = trim($b['name'] ?? '');
      $pw = (string)($b['password'] ?? '');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('正しいメールアドレスを入力してください。');
      if (strlen($pw) < 4) fail('パスワードは4文字以上で入力してください。');
      $id = uuid();
      $pdo->prepare('INSERT INTO users (id, email, name, role, pass_hash, created_at) VALUES (?,?,?,?,?,?)')
          ->execute([$id, $email, $name, 'editor', password_hash($pw, PASSWORD_DEFAULT), now()]);
      session_regenerate_id(true);
      $_SESSION['uid'] = $id;
      $u = current_user();
      audit($u, 'create', 'user', $id, '初期管理者を作成: ' . $email);
      json_out(['user' => $u, 'csrfToken' => csrf_token()]);
    }

    case 'login': {
      $ip = client_ip();
      check_login_rate($ip);
      $b = body();
      $email = strtolower(trim($b['email'] ?? ''));
      $pw = (string)($b['password'] ?? '');
      $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = ?');
      $st->execute([$email]);
      $u = $st->fetch();
      if (!$u || !password_verify($pw, $u['pass_hash'])) {
        record_login_fail($ip);
        fail('メールアドレスまたはパスワードが違います。', 401);
      }
      clear_login_fails($ip);
      session_regenerate_id(true);
      $_SESSION['uid'] = $u['id'];
      json_out(['user' => ['id' => $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'role' => $u['role']], 'csrfToken' => csrf_token()]);
    }

    case 'logout': {
      $_SESSION = [];
      session_destroy();
      json_out(['ok' => true]);
    }

    /* ── 状態取得 ── */
    case 'state': {
      $u = require_login();
      json_out(['state' => get_state($u)]);
    }

    /* ── 製品 ── */
    case 'save_product': {
      $u = require_editor();
      $b = body();
      $name = trim($b['name'] ?? '');
      $category = trim($b['category'] ?? '');
      $safety = max(0, (int)($b['safetyStock'] ?? 0));
      if ($name === '') fail('製品名を入力してください。');
      $id = $b['id'] ?? '';
      $dup = $pdo->prepare('SELECT id FROM products WHERE LOWER(name)=LOWER(?) AND id<>?');
      $dup->execute([$name, $id]);
      if ($dup->fetch()) fail('同じ名前の製品が既に登録されています。');
      if ($id) {
        $p = product_by_id($id);
        if (!$p) fail('対象の製品が見つかりません。');
        $pdo->prepare('UPDATE products SET name=?, category=?, safety_stock=?, updated_at=? WHERE id=?')
            ->execute([$name, $category, $safety, now(), $id]);
        audit($u, 'update', 'product', $id, "製品を編集: {$name}（安全在庫 {$safety}）");
      } else {
        $id = uuid();
        $pdo->prepare('INSERT INTO products (id, name, category, safety_stock, created_at, updated_at) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $name, $category, $safety, now(), now()]);
        audit($u, 'create', 'product', $id, "製品を追加: {$name}");
      }
      $init = $b['initStock'] ?? null;
      if (is_array($init)) {
        $itype = $init['type'] ?? '';
        $iqty = (int)($init['qty'] ?? 0);
        $icolor = $itype === 'painted' ? trim($init['color'] ?? '') : '';
        if ($iqty > 0 && in_array($itype, ['kiji', 'painted'], true)) {
          $lot = ['status' => $itype, 'kiji_date' => '', 'painted_date' => '', 'shipped_date' => ''];
          stamp_dates($lot);
          $lid = uuid();
          $pdo->prepare('INSERT INTO lots (id, product_id, lot_no, qty, due_date, status, color, dest, note, kiji_date, painted_date, shipped_date, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
              ->execute([$lid, $id, '', $iqty, date('Y-m-d'), $itype, $icolor, '', 'マスタから在庫登録', $lot['kiji_date'], $lot['painted_date'], $lot['shipped_date'], now(), now()]);
          $label = $itype === 'kiji' ? '木地' : '塗装済';
          audit($u, 'create', 'lot', $lid, "在庫を登録: {$name} {$label}×{$iqty}" . ($icolor ? "（{$icolor}）" : ''));
        }
      }
      json_out(['state' => get_state($u)]);
    }

    case 'delete_product': {
      $u = require_editor();
      $id = body()['id'] ?? '';
      $p = product_by_id($id);
      if (!$p) fail('対象の製品が見つかりません。');
      $pdo->beginTransaction();
      $pdo->prepare('DELETE FROM lots WHERE product_id = ?')->execute([$id]);
      $pdo->prepare('DELETE FROM losses WHERE product_id = ?')->execute([$id]);
      $pdo->prepare('DELETE FROM paint_instructions WHERE product_id = ?')->execute([$id]);
      $pdo->prepare('DELETE FROM shipment_plans WHERE product_id = ?')->execute([$id]);
      $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
      $pdo->commit();
      audit($u, 'delete', 'product', $id, '製品を削除: ' . $p['name']);
      json_out(['state' => get_state($u)]);
    }

    /* ── 生産ロット ── */
    case 'save_lot': {
      $u = require_editor();
      $b = body();
      $pname = trim($b['productName'] ?? '');
      $category = trim($b['category'] ?? '');
      $qty = (int)($b['qty'] ?? 0);
      $status = $b['status'] ?? 'planned';
      if ($pname === '') fail('製品名を入力してください。');
      if ($qty < 1) fail('数量は1以上で入力してください。');
      if (!in_array($status, $VALID_STATUS, true)) fail('状態の値が不正です。');
      $prod = resolve_product($pname, $category, $u);
      $dest = trim($b['dest'] ?? '');
      if ($status === 'shipped' && $dest === '') fail('出荷済にするには出荷先を入力してください。');
      if ($dest !== '') ensure_destination($dest);
      $id = $b['id'] ?? '';
      $lot = null;
      if ($id) {
        $lot = lot_by_id($id);
        if (!$lot) fail('対象のロットが見つかりません。他の人が削除した可能性があります。', 404);
      }
      $fields = [
        'product_id' => $prod['id'],
        'lot_no' => trim($b['lotNo'] ?? ''),
        'qty' => $qty,
        'due_date' => trim($b['dueDate'] ?? ''),
        'status' => $status,
        'color' => trim($b['color'] ?? ''),
        'dest' => $dest,
        'note' => trim($b['note'] ?? ''),
        'kiji_date' => $lot['kiji_date'] ?? '',
        'painted_date' => $lot['painted_date'] ?? '',
        'shipped_date' => $lot['shipped_date'] ?? '',
      ];
      stamp_dates($fields);
      if ($id) {
        $pdo->prepare('UPDATE lots SET product_id=?, lot_no=?, qty=?, due_date=?, status=?, color=?, dest=?, note=?, kiji_date=?, painted_date=?, shipped_date=?, updated_at=? WHERE id=?')
            ->execute([$fields['product_id'], $fields['lot_no'], $fields['qty'], $fields['due_date'], $fields['status'], $fields['color'], $fields['dest'], $fields['note'], $fields['kiji_date'], $fields['painted_date'], $fields['shipped_date'], now(), $id]);
        audit($u, 'update', 'lot', $id, "生産予定を編集: {$prod['name']} / {$fields['lot_no']} ×{$qty}（" . status_label($status) . '）');
      } else {
        $id = uuid();
        $pdo->prepare('INSERT INTO lots (id, product_id, lot_no, qty, due_date, status, color, dest, note, kiji_date, painted_date, shipped_date, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $fields['product_id'], $fields['lot_no'], $fields['qty'], $fields['due_date'], $fields['status'], $fields['color'], $fields['dest'], $fields['note'], $fields['kiji_date'], $fields['painted_date'], $fields['shipped_date'], now(), now()]);
        audit($u, 'create', 'lot', $id, "生産予定を追加: {$prod['name']} / {$fields['lot_no']} ×{$qty}（" . status_label($status) . '）');
      }
      json_out(['state' => get_state($u)]);
    }

    case 'set_status': {
      $u = require_editor();
      $b = body();
      $id = $b['id'] ?? '';
      $status = $b['status'] ?? '';
      if (!in_array($status, $VALID_STATUS, true)) fail('状態の値が不正です。');
      $lot = lot_by_id($id);
      if (!$lot) fail('対象のロットが見つかりません。');
      $extraLog = [];
      /* 任意の追加情報（呼び出し側のプロンプトで入る） */
      if ($status === 'kiji' && !empty($b['kijiDate'])) $lot['kiji_date'] = trim($b['kijiDate']);
      if ($status === 'painted') {
        if (!empty($b['paintedDate'])) $lot['painted_date'] = trim($b['paintedDate']);
        if (isset($b['color'])) $lot['color'] = trim($b['color']);
      }
      if ($status === 'shipped') {
        if (!empty($b['shippedDate'])) $lot['shipped_date'] = trim($b['shippedDate']);
        $dest = trim($b['dest'] ?? $lot['dest'] ?? '');
        if ($dest === '') fail('出荷先を指定してください。');
        $lot['dest'] = $dest;
        ensure_destination($dest);
        $extraLog[] = $dest;
        if (isset($b['color'])) $lot['color'] = trim($b['color']);
      }
      $lot['status'] = $status;
      stamp_dates($lot);
      $pdo->prepare('UPDATE lots SET status=?, color=?, dest=?, kiji_date=?, painted_date=?, shipped_date=?, updated_at=? WHERE id=?')
          ->execute([$status, $lot['color'], $lot['dest'], $lot['kiji_date'], $lot['painted_date'], $lot['shipped_date'], now(), $id]);
      $p = product_by_id($lot['product_id']);
      $extra = $extraLog ? '（' . implode(', ', $extraLog) . '）' : '';
      audit($u, 'status', 'lot', $id, '状態変更: ' . ($p['name'] ?? '') . " / {$lot['lot_no']} → " . status_label($status) . $extra);
      json_out(['state' => get_state($u)]);
    }

case 'delete_lot': {
      $u = require_editor();
      $id = body()['id'] ?? '';
      $lot = lot_by_id($id);
      if (!$lot) fail('対象のロットが見つかりません。');
      $p = product_by_id($lot['product_id']);
      $pdo->prepare('DELETE FROM lots WHERE id = ?')->execute([$id]);
      audit($u, 'delete', 'lot', $id, '生産予定を削除: ' . ($p['name'] ?? '') . " / {$lot['lot_no']} ×{$lot['qty']}");
      json_out(['state' => get_state($u)]);
    }

    /* ── 塗装：木地ロットをカラー別に分割して完成在庫へ ── */
    case 'paint_lot': {
      $u = require_editor();
      $b = body();
      $kid = $b['id'] ?? '';
      $items = $b['items'] ?? [];
      $paintedDate = trim($b['paintedDate'] ?? '') ?: date('Y-m-d');
      $shipBy = trim($b['shipBy'] ?? '');
      if (!is_array($items) || count($items) === 0) fail('塗装内訳を入力してください。');
      $lot = lot_by_id($kid);
      if (!$lot) fail('対象のロットが見つかりません。');
      if ($lot['status'] !== 'kiji') fail('木地完成のロットのみ塗装に進められます。');
      $total = 0; $clean = [];
      foreach ($items as $it) {
        $q = (int)($it['qty'] ?? 0);
        if ($q < 1) fail('数量は1以上で入力してください。');
        $color = trim($it['color'] ?? '');
        $total += $q;
        $clean[] = ['qty' => $q, 'color' => $color];
      }
      if ($total > (int)$lot['qty']) fail("木地在庫({$lot['qty']})を超える数量({$total})は塗装できません。");
      $p = product_by_id($lot['product_id']);
      $pdo->beginTransaction();
      $remaining = (int)$lot['qty'] - $total;
      if ($remaining <= 0) {
        $pdo->prepare('DELETE FROM lots WHERE id=?')->execute([$kid]);
      } else {
        $pdo->prepare('UPDATE lots SET qty=?, updated_at=? WHERE id=?')->execute([$remaining, now(), $kid]);
      }
      $created = [];
      foreach ($clean as $it) {
        $nid = uuid();
        $pdo->prepare('INSERT INTO lots (id, product_id, lot_no, qty, due_date, status, color, dest, note, kiji_date, painted_date, shipped_date, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$nid, $lot['product_id'], $lot['lot_no'], $it['qty'], $lot['due_date'], 'painted', $it['color'], '', $lot['note'], $lot['kiji_date'], $paintedDate, '', now(), now()]);
        $created[] = ($it['color'] !== '' ? $it['color'] : '無色') . '×' . $it['qty'];
      }
      /* 塗装指示を記録：印刷・再印刷・履歴として残す */
      $instId = uuid();
      $pdo->prepare('INSERT INTO paint_instructions (id, product_id, kiji_lot_no, kiji_date, paint_date, ship_by, items_json, total_qty, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
          ->execute([$instId, $lot['product_id'], $lot['lot_no'], $lot['kiji_date'], $paintedDate, $shipBy, json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $u['email'], now()]);
      $pdo->commit();
      $audit_msg = '塗装指示: ' . ($p['name'] ?? '') . " / {$lot['lot_no']} → " . implode('、', $created)
                 . ($remaining > 0 ? "（木地残 {$remaining}）" : '')
                 . ($shipBy !== '' ? "［出荷予定 {$shipBy}］" : '');
      audit($u, 'status', 'lot', $kid, $audit_msg);
      json_out(['state' => get_state($u), 'instructionId' => $instId]);
    }

    /* ── 出荷：完成在庫からカラー別に必要数だけ出す（部分出荷可）── */
    case 'ship_lots': {
      $u = require_editor();
      $b = body();
      $pid = $b['productId'] ?? '';
      $items = $b['items'] ?? [];
      $dest = trim($b['dest'] ?? '');
      $shippedDate = trim($b['shippedDate'] ?? '') ?: date('Y-m-d');
      $note = trim($b['note'] ?? '');
      $p = product_by_id($pid);
      if (!$p) fail('対象の製品が見つかりません。');
      if ($dest === '') fail('出荷先を入力してください。');
      if (!is_array($items) || count($items) === 0) fail('出荷する数量を入力してください。');

      /* 検証：色ごとの残数を超えていないか（破損分も差し引いた数で判定）*/
      $clean = [];
      foreach ($items as $it) {
        $q = (int)($it['qty'] ?? 0);
        if ($q < 1) continue;
        $color = trim($it['color'] ?? '');
        $avail = color_available($pid, $color);
        $label = $color !== '' ? $color : '無色';
        if ($q > $avail) fail("{$label} の在庫は {$avail} です。それを超えて出荷できません。");
        $clean[] = ['qty' => $q, 'color' => $color];
      }
      if (!$clean) fail('出荷する数量を入力してください。');

      ensure_destination($dest);
      $pdo->beginTransaction();
      $res = allocate_shipment($pdo, $pid, $clean, $dest, $shippedDate, $note);
      if (!$res['ok']) { $pdo->rollBack(); fail('在庫の引き当てに失敗しました。画面を再読み込みしてお試しください。'); }
      $pdo->commit();
      audit($u, 'status', 'lot', $pid, "出荷: {$p['name']} → {$dest} ／ " . implode('、', $res['labels']) . "（{$shippedDate}）");
      json_out(['state' => get_state($u)]);
    }

    /* ── 出荷予定（先に予約しておき、当日「出荷した」に切り替える）── */
    case 'save_shipment_plan': {
      $u = require_editor();
      $b = body();
      $id = $b['id'] ?? '';
      $pid = $b['productId'] ?? '';
      $qty = (int)($b['qty'] ?? 0);
      $color = trim($b['color'] ?? '');
      $dest = trim($b['dest'] ?? '');
      $planDate = trim($b['planDate'] ?? '');
      $note = trim($b['note'] ?? '');
      $p = product_by_id($pid);
      if (!$p) fail('対象の製品が見つかりません。');
      if ($qty < 1) fail('数量は1以上で入力してください。');
      if ($dest === '') fail('出荷先を入力してください。');
      if ($planDate === '') fail('出荷予定日を入力してください。');
      ensure_destination($dest);
      $label = $color !== '' ? $color : '無色';
      if ($id) {
        $st = $pdo->prepare("SELECT * FROM shipment_plans WHERE id=? AND status='open'");
        $st->execute([$id]);
        if (!$st->fetch()) fail('対象の出荷予定が見つかりません。');
        $pdo->prepare('UPDATE shipment_plans SET product_id=?, color=?, qty=?, dest=?, plan_date=?, note=? WHERE id=?')
            ->execute([$pid, $color, $qty, $dest, $planDate, $note, $id]);
        audit($u, 'update', 'plan', $id, "出荷予定を編集: {$p['name']} {$label}×{$qty} → {$dest}（{$planDate}）");
      } else {
        $id = uuid();
        $pdo->prepare('INSERT INTO shipment_plans (id, product_id, color, qty, dest, plan_date, note, status, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $pid, $color, $qty, $dest, $planDate, $note, 'open', $u['email'], now()]);
        audit($u, 'create', 'plan', $id, "出荷予定を追加: {$p['name']} {$label}×{$qty} → {$dest}（{$planDate}）");
      }
      json_out(['state' => get_state($u)]);
    }

    case 'delete_shipment_plan': {
      $u = require_editor();
      $id = body()['id'] ?? '';
      $st = $pdo->prepare('SELECT * FROM shipment_plans WHERE id=?');
      $st->execute([$id]);
      $plan = $st->fetch();
      if (!$plan) fail('対象の出荷予定が見つかりません。');
      $p = product_by_id($plan['product_id']);
      $pdo->prepare('DELETE FROM shipment_plans WHERE id=?')->execute([$id]);
      audit($u, 'delete', 'plan', $id, '出荷予定を取消: ' . ($p['name'] ?? '') . " ×{$plan['qty']} → {$plan['dest']}");
      json_out(['state' => get_state($u)]);
    }

    /* 予定を実際の出荷に変える */
    case 'fulfill_shipment_plan': {
      $u = require_editor();
      $b = body();
      $id = $b['id'] ?? '';
      $shippedDate = trim($b['shippedDate'] ?? '') ?: date('Y-m-d');
      $st = $pdo->prepare("SELECT * FROM shipment_plans WHERE id=? AND status='open'");
      $st->execute([$id]);
      $plan = $st->fetch();
      if (!$plan) fail('対象の出荷予定が見つかりません。');
      $pid = $plan['product_id'];
      $p = product_by_id($pid);
      if (!$p) fail('対象の製品が見つかりません。');
      $color = $plan['color'] ?? '';
      $qty = (int)$plan['qty'];
      $label = $color !== '' ? $color : '無色';
      /* 引き当ては自分の予定ぶんを除いた在庫で判定する */
      $avail = color_available($pid, $color);
      if ($qty > $avail) fail("{$label} の在庫は {$avail} です。予定数 {$qty} を出荷できません。");
      $pdo->beginTransaction();
      $res = allocate_shipment($pdo, $pid, [['qty' => $qty, 'color' => $color]], $plan['dest'], $shippedDate, $plan['note'] ?? '');
      if (!$res['ok']) { $pdo->rollBack(); fail('在庫の引き当てに失敗しました。画面を再読み込みしてお試しください。'); }
      $pdo->prepare("UPDATE shipment_plans SET status='done', done_at=? WHERE id=?")->execute([now(), $id]);
      $pdo->commit();
      audit($u, 'status', 'plan', $id, "出荷（予定から）: {$p['name']} {$label}×{$qty} → {$plan['dest']}（{$shippedDate}）");
      json_out(['state' => get_state($u)]);
    }

    /* ── 破損・ロス ── */
    case 'record_loss': {
      $u = require_editor();
      $b = body();
      $pid = $b['productId'] ?? '';
      $bucket = $b['bucket'] ?? '';
      $qty = (int)($b['qty'] ?? 0);
      $color = $bucket === 'painted' ? trim($b['color'] ?? '') : '';
      $lossDate = trim($b['lossDate'] ?? '') ?: date('Y-m-d');
      $stage = trim($b['stage'] ?? '');
      $reason = trim($b['reason'] ?? '');
      $p = product_by_id($pid);
      if (!$p) fail('対象の製品が見つかりません。');
      if (!in_array($bucket, ['kiji', 'painted'], true)) fail('在庫の種類が不正です。');
      if ($qty < 1) fail('数量は1以上で入力してください。');
      $bucketLabel = $bucket === 'kiji' ? '木地在庫' : '完成在庫';
      if ($bucket === 'painted' && $color === '') {
        $st = $pdo->prepare("SELECT COUNT(*) c FROM lots WHERE product_id=? AND status='painted' AND color<>''");
        $st->execute([$pid]);
        if ((int)$st->fetch()['c'] > 0) {
          fail('完成在庫にカラー別の在庫があるため、減らすカラーを指定してください。');
        }
      }
      if ($color !== '') {
        $avail = color_available($pid, $color);
        if ($qty > $avail) fail("完成在庫（{$color}）の残数は {$avail} です。それを超えて減らせません。");
      } else {
        $avail = bucket_available($pid, $bucket);
        if ($qty > $avail) fail("{$bucketLabel}の残数は {$avail} です。それを超えて減らせません。");
      }
      $lid = uuid();
      $pdo->prepare('INSERT INTO losses (id, product_id, bucket, qty, color, loss_date, stage, reason, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
          ->execute([$lid, $pid, $bucket, $qty, $color, $lossDate, $stage, $reason, $u['email'], now()]);
      audit($u, 'loss', 'loss', $lid, "破損/ロス: {$p['name']} {$bucketLabel}×{$qty}" . ($color ? "（{$color}）" : '') . ($stage ? " ／ {$stage}" : '') . ($reason ? " ／ {$reason}" : ''));
      json_out(['state' => get_state($u)]);
    }

    case 'delete_loss': {
      $u = require_editor();
      $id = body()['id'] ?? '';
      $st = $pdo->prepare('SELECT * FROM losses WHERE id=?');
      $st->execute([$id]);
      $loss = $st->fetch();
      if (!$loss) fail('対象の破損記録が見つかりません。');
      $p = product_by_id($loss['product_id']);
      $pdo->prepare('DELETE FROM losses WHERE id=?')->execute([$id]);
      $bucketLabel = $loss['bucket'] === 'kiji' ? '木地在庫' : '完成在庫';
      audit($u, 'delete', 'loss', $id, '破損記録を取消: ' . ($p['name'] ?? '') . " {$bucketLabel}×{$loss['qty']}");
      json_out(['state' => get_state($u)]);
    }

    /* ── ユーザー ── */
    case 'save_user': {
      $u = require_editor();
      $b = body();
      $name = trim($b['name'] ?? '');
      $email = strtolower(trim($b['email'] ?? ''));
      $role = $b['role'] ?? 'viewer';
      $pw = (string)($b['password'] ?? '');
      $id = $b['id'] ?? '';
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('正しいメールアドレスを入力してください。');
      if (!in_array($role, ['editor', 'viewer'], true)) fail('権限の値が不正です。');
      $dup = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=? AND id<>?');
      $dup->execute([$email, $id]);
      if ($dup->fetch()) fail('このメールアドレスは既に使われています。');
      $editors = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role='editor'")->fetch()['c'];
      if ($id) {
        $st = $pdo->prepare('SELECT * FROM users WHERE id=?');
        $st->execute([$id]);
        $tgt = $st->fetch();
        if (!$tgt) fail('対象のユーザーが見つかりません。');
        if ($tgt['role'] === 'editor' && $role !== 'editor' && $editors <= 1) fail('編集権限のユーザーが0人になります。最低1人必要です。');
        if ($pw !== '' && strlen($pw) < 4) fail('パスワードは4文字以上で入力してください。');
        if ($pw !== '') {
          $pdo->prepare('UPDATE users SET name=?, email=?, role=?, pass_hash=? WHERE id=?')
              ->execute([$name, $email, $role, password_hash($pw, PASSWORD_DEFAULT), $id]);
        } else {
          $pdo->prepare('UPDATE users SET name=?, email=?, role=? WHERE id=?')->execute([$name, $email, $role, $id]);
        }
        audit($u, 'update', 'user', $id, "ユーザーを編集: {$email}（" . ($role === 'editor' ? '編集' : '閲覧') . '）');
      } else {
        if (strlen($pw) < 4) fail('パスワードは4文字以上で入力してください。');
        $id = uuid();
        $pdo->prepare('INSERT INTO users (id, email, name, role, pass_hash, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$id, $email, $name, $role, password_hash($pw, PASSWORD_DEFAULT), now()]);
        audit($u, 'create', 'user', $id, "ユーザーを追加: {$email}（" . ($role === 'editor' ? '編集' : '閲覧') . '）');
      }
      json_out(['state' => get_state($u)]);
    }

    case 'delete_user': {
      $u = require_editor();
      $id = body()['id'] ?? '';
      if ($id === $u['id']) fail('自分自身は削除できません。');
      $st = $pdo->prepare('SELECT * FROM users WHERE id=?');
      $st->execute([$id]);
      $tgt = $st->fetch();
      if (!$tgt) fail('対象のユーザーが見つかりません。');
      $editors = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role='editor'")->fetch()['c'];
      if ($tgt['role'] === 'editor' && $editors <= 1) fail('編集権限のユーザーが0人になります。最低1人必要です。');
      $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
      audit($u, 'delete', 'user', $id, 'ユーザーを削除: ' . $tgt['email']);
      json_out(['state' => get_state($u)]);
    }

    /* ── 監査ログ ── */
    case 'audit': {
      $u = require_editor();
      $rows = $pdo->query('SELECT user_email, action, entity, summary, created_at FROM audit_log ORDER BY created_at DESC, id DESC LIMIT 300')->fetchAll();
      json_out(['log' => $rows]);
    }

    /* ── 初期化 ── */
    case 'reset': {
      $u = require_editor();
      if ((body()['pin'] ?? '') !== RESET_PIN) fail('パスワードが違います。', 403);
      $pdo->exec('DELETE FROM losses');
      $pdo->exec('DELETE FROM paint_instructions');
      $pdo->exec('DELETE FROM shipment_plans');
      $pdo->exec('DELETE FROM lots');
      $pdo->exec('DELETE FROM products');
      $pdo->exec('DELETE FROM destinations');
      seed_sample($u);
      json_out(['state' => get_state($u)]);
    }

    case 'clear': {
      $u = require_editor();
      if ((body()['pin'] ?? '') !== RESET_PIN) fail('パスワードが違います。', 403);
      $pdo->exec('DELETE FROM losses');
      $pdo->exec('DELETE FROM paint_instructions');
      $pdo->exec('DELETE FROM shipment_plans');
      $pdo->exec('DELETE FROM lots');
      $pdo->exec('DELETE FROM products');
      $pdo->exec('DELETE FROM destinations');
      audit($u, 'clear', 'system', '', '在庫データを全消去');
      json_out(['state' => get_state($u)]);
    }

    /* ── 完全バックアップ（移行用：ユーザーのパスワードハッシュも含む）── */
    case 'export': {
      $u = require_editor();
      $userRows = $pdo->query('SELECT id, email, name, role, pass_hash, created_at FROM users ORDER BY created_at')->fetchAll();
      $data = [
        'version' => 2,
        'exportedAt' => now(),
        'products' => array_map('map_product', $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll()),
        'lots' => array_map('map_lot', $pdo->query('SELECT * FROM lots')->fetchAll()),
        'losses' => array_map('map_loss', $pdo->query('SELECT * FROM losses ORDER BY loss_date DESC, created_at DESC')->fetchAll()),
        'paintInstructions' => array_map('map_paint_instruction', $pdo->query('SELECT * FROM paint_instructions ORDER BY created_at DESC')->fetchAll()),
        'shipmentPlans' => array_map('map_shipment_plan', $pdo->query('SELECT * FROM shipment_plans ORDER BY plan_date ASC')->fetchAll()),
        'destinations' => array_map(fn($r) => $r['name'], $pdo->query('SELECT name FROM destinations ORDER BY name')->fetchAll()),
        'users' => array_map(fn($r) => ['id' => $r['id'], 'email' => $r['email'], 'name' => $r['name'], 'role' => $r['role'], 'passHash' => $r['pass_hash'], 'createdAt' => $r['created_at']], $userRows),
      ];
      audit($u, 'export', 'system', '', '完全バックアップを書き出し');
      json_out($data);
    }

    /* ── バックアップから復元（ログイン済み・既存データを置き換え）── */
    case 'import': {
      $u = require_editor();
      $d = body()['data'] ?? null;
      if (!is_array($d) || !isset($d['products']) || !is_array($d['products'])) fail('形式が不正です。');
      import_backup($pdo, $d, $VALID_STATUS, false /* users not replaced when importing logged in */);
      audit($u, 'import', 'system', '', 'バックアップを取り込み');
      json_out(['state' => get_state($u)]);
    }

    /* ── 別サーバーへの引越し：ユーザーが未作成の状態でフルバックアップから復元 ── */
    case 'restore': {
      $count = (int)$pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
      if ($count > 0) fail('既に管理者が存在します。ログイン後「データ管理 → 取り込み」をご利用ください。', 403);
      $d = body()['data'] ?? null;
      if (!is_array($d) || empty($d['users']) || !is_array($d['users'])) fail('ユーザー情報を含むバックアップが必要です。');
      import_backup($pdo, $d, $VALID_STATUS, true /* replace users */);
      json_out(['ok' => true, 'restoredUsers' => count($d['users']), 'restoredProducts' => count($d['products'] ?? [])]);
    }

    default:
      fail('不明な操作です: ' . $action, 404);
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[inventory] ' . $action . ' failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  fail('サーバーエラーが発生しました。時間をおいて再度お試しください。', 500);
}

function status_label(string $s): string {
  return ['planned' => '予定', 'kiji' => '木地完成', 'painted' => '塗装済', 'shipped' => '出荷済'][$s] ?? $s;
}
