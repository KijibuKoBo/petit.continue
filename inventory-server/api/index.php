<?php
require_once __DIR__ . '/lib.php';

/* セッション（HTTPS では Secure 付与） */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
session_start();

$VALID_STATUS = ['planned', 'kiji', 'painted', 'shipped'];
$action = $_GET['action'] ?? '';

try {
  $pdo = db();
} catch (Throwable $e) {
  fail('データベースに接続できません: ' . $e->getMessage(), 500);
}

try {
  switch ($action) {

    /* ── 認証 ── */
    case 'me': {
      $count = (int)$pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
      json_out(['user' => current_user(), 'needsSetup' => $count === 0]);
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
      $_SESSION['uid'] = $id;
      $u = current_user();
      audit($u, 'create', 'user', $id, '初期管理者を作成: ' . $email);
      json_out(['user' => $u]);
    }

    case 'login': {
      $b = body();
      $email = strtolower(trim($b['email'] ?? ''));
      $pw = (string)($b['password'] ?? '');
      $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = ?');
      $st->execute([$email]);
      $u = $st->fetch();
      if (!$u || !password_verify($pw, $u['pass_hash'])) fail('メールアドレスまたはパスワードが違います。', 401);
      session_regenerate_id(true);
      $_SESSION['uid'] = $u['id'];
      json_out(['user' => ['id' => $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'role' => $u['role']]]);
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
      json_out(['state' => get_state($u)]);
    }

    case 'delete_product': {
      $u = require_editor();
      $id = body()['id'] ?? '';
      $p = product_by_id($id);
      if (!$p) fail('対象の製品が見つかりません。');
      $pdo->prepare('DELETE FROM lots WHERE product_id = ?')->execute([$id]);
      $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
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
      if ($dest !== '') ensure_destination($dest);
      $id = $b['id'] ?? '';
      $lot = $id ? lot_by_id($id) : null;
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
      if ($id && $lot) {
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
      $lot['status'] = $status;
      stamp_dates($lot);
      $pdo->prepare('UPDATE lots SET status=?, kiji_date=?, painted_date=?, shipped_date=?, updated_at=? WHERE id=?')
          ->execute([$status, $lot['kiji_date'], $lot['painted_date'], $lot['shipped_date'], now(), $id]);
      $p = product_by_id($lot['product_id']);
      audit($u, 'status', 'lot', $id, '状態変更: ' . ($p['name'] ?? '') . " / {$lot['lot_no']} → " . status_label($status));
      json_out(['state' => get_state($u)]);
    }

    case 'set_color': {
      $u = require_editor();
      $b = body();
      $id = $b['id'] ?? '';
      $color = trim($b['color'] ?? '');
      $lot = lot_by_id($id);
      if (!$lot) fail('対象のロットが見つかりません。');
      $pdo->prepare('UPDATE lots SET color=?, updated_at=? WHERE id=?')->execute([$color, now(), $id]);
      $p = product_by_id($lot['product_id']);
      audit($u, 'update', 'lot', $id, 'カラー設定: ' . ($p['name'] ?? '') . " / {$lot['lot_no']} → " . ($color ?: '（無）'));
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
      $pdo->exec('DELETE FROM lots');
      $pdo->exec('DELETE FROM products');
      $pdo->exec('DELETE FROM destinations');
      seed_sample($u);
      json_out(['state' => get_state($u)]);
    }

    case 'clear': {
      $u = require_editor();
      if ((body()['pin'] ?? '') !== RESET_PIN) fail('パスワードが違います。', 403);
      $pdo->exec('DELETE FROM lots');
      $pdo->exec('DELETE FROM products');
      $pdo->exec('DELETE FROM destinations');
      audit($u, 'clear', 'system', '', '在庫データを全消去');
      json_out(['state' => get_state($u)]);
    }

    /* ── バックアップ取り込み（製品・ロット・出荷先を置換）── */
    case 'import': {
      $u = require_editor();
      $d = body()['data'] ?? null;
      if (!is_array($d) || !isset($d['products']) || !is_array($d['products'])) fail('形式が不正です。');
      $pdo->beginTransaction();
      $pdo->exec('DELETE FROM lots');
      $pdo->exec('DELETE FROM products');
      $pdo->exec('DELETE FROM destinations');
      $ip = $pdo->prepare('INSERT INTO products (id, name, category, safety_stock, created_at, updated_at) VALUES (?,?,?,?,?,?)');
      $validIds = [];
      foreach ($d['products'] as $p) {
        if (empty($p['name'])) continue;
        $pid = !empty($p['id']) ? (string)$p['id'] : uuid();
        $validIds[$pid] = true;
        $ip->execute([$pid, $p['name'], $p['category'] ?? '', max(0, (int)($p['safetyStock'] ?? 0)), now(), now()]);
      }
      $il = $pdo->prepare('INSERT INTO lots (id, product_id, lot_no, qty, due_date, status, color, dest, note, kiji_date, painted_date, shipped_date, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      foreach (($d['lots'] ?? []) as $l) {
        if (empty($l['productId']) || !isset($validIds[$l['productId']])) continue;
        $status = in_array($l['status'] ?? '', $VALID_STATUS, true) ? $l['status'] : 'planned';
        $il->execute([!empty($l['id']) ? (string)$l['id'] : uuid(), $l['productId'], $l['lotNo'] ?? '', max(0, (int)($l['qty'] ?? 0)), $l['dueDate'] ?? '', $status, $l['color'] ?? '', $l['dest'] ?? '', $l['note'] ?? '', $l['kijiDate'] ?? '', $l['paintedDate'] ?? '', $l['shippedDate'] ?? '', now(), now()]);
      }
      foreach (($d['destinations'] ?? []) as $name) ensure_destination((string)$name);
      $pdo->commit();
      audit($u, 'import', 'system', '', 'バックアップJSONを取り込み');
      json_out(['state' => get_state($u)]);
    }

    default:
      fail('不明な操作です: ' . $action, 404);
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fail('サーバーエラー: ' . $e->getMessage(), 500);
}

function status_label(string $s): string {
  return ['planned' => '予定', 'kiji' => '木地完成', 'painted' => '塗装済', 'shipped' => '出荷済'][$s] ?? $s;
}
