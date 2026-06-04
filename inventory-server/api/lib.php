<?php
require_once __DIR__ . '/db.php';

/* ── 出力 ── */
function json_out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}
function fail(string $msg, int $code = 400): void { json_out(['error' => $msg], $code); }

function body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) return [];
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}

function uuid(): string {
  $b = random_bytes(16);
  $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
  $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function now(): string { return date('Y-m-d H:i:s'); }

/* ── 認証 ── */
function current_user(): ?array {
  if (empty($_SESSION['uid'])) return null;
  $st = db()->prepare('SELECT id, email, name, role FROM users WHERE id = ?');
  $st->execute([$_SESSION['uid']]);
  $u = $st->fetch();
  return $u ?: null;
}
function require_login(): array {
  $u = current_user();
  if (!$u) fail('ログインが必要です。', 401);
  return $u;
}
function require_editor(): array {
  $u = require_login();
  if ($u['role'] !== 'editor') fail('編集権限が必要です。', 403);
  return $u;
}

/* ── 監査ログ ── */
function audit(array $user, string $action, string $entity, string $entityId, string $summary, $detail = null): void {
  $st = db()->prepare('INSERT INTO audit_log (id, user_id, user_email, action, entity, entity_id, summary, detail, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?)');
  $st->execute([uuid(), $user['id'], $user['email'], $action, $entity, $entityId, mb_substr($summary, 0, 500),
                $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE), now()]);
}

/* ── マッピング（DB → フロント形式）── */
function map_product(array $r): array {
  return ['id' => $r['id'], 'name' => $r['name'], 'category' => $r['category'] ?? '', 'safetyStock' => (int)$r['safety_stock']];
}
function map_lot(array $r): array {
  return [
    'id' => $r['id'], 'productId' => $r['product_id'], 'lotNo' => $r['lot_no'] ?? '',
    'qty' => (int)$r['qty'], 'dueDate' => $r['due_date'] ?? '', 'status' => $r['status'],
    'color' => $r['color'] ?? '', 'dest' => $r['dest'] ?? '',
    'kijiDate' => $r['kiji_date'] ?? '', 'paintedDate' => $r['painted_date'] ?? '',
    'shippedDate' => $r['shipped_date'] ?? '', 'note' => $r['note'] ?? '',
  ];
}

function map_loss(array $r): array {
  return [
    'id' => $r['id'], 'productId' => $r['product_id'], 'bucket' => $r['bucket'],
    'qty' => (int)$r['qty'], 'color' => $r['color'] ?? '', 'lossDate' => $r['loss_date'] ?? '',
    'reason' => $r['reason'] ?? '', 'createdBy' => $r['created_by'] ?? '',
  ];
}

function map_paint_instruction(array $r): array {
  return [
    'id' => $r['id'], 'productId' => $r['product_id'],
    'kijiLotNo' => $r['kiji_lot_no'] ?? '', 'kijiDate' => $r['kiji_date'] ?? '',
    'paintDate' => $r['paint_date'] ?? '', 'shipBy' => $r['ship_by'] ?? '',
    'items' => json_decode($r['items_json'] ?? '[]', true) ?: [],
    'totalQty' => (int)($r['total_qty'] ?? 0),
    'createdBy' => $r['created_by'] ?? '', 'createdAt' => $r['created_at'] ?? '',
  ];
}

/* バックアップJSONから全テーブルを復元。$replaceUsers=true のときはユーザーも置換（移行用）。 */
function import_backup(PDO $pdo, array $d, array $validStatus, bool $replaceUsers): void {
  $pdo->beginTransaction();
  $pdo->exec('DELETE FROM losses');
  $pdo->exec('DELETE FROM paint_instructions');
  $pdo->exec('DELETE FROM lots');
  $pdo->exec('DELETE FROM products');
  $pdo->exec('DELETE FROM destinations');
  if ($replaceUsers) {
    $pdo->exec('DELETE FROM users');
    $iu = $pdo->prepare('INSERT INTO users (id, email, name, role, pass_hash, created_at) VALUES (?,?,?,?,?,?)');
    foreach (($d['users'] ?? []) as $u) {
      if (empty($u['email']) || empty($u['passHash'])) continue;
      $role = in_array($u['role'] ?? '', ['editor', 'viewer'], true) ? $u['role'] : 'viewer';
      $iu->execute([!empty($u['id']) ? (string)$u['id'] : uuid(), strtolower($u['email']), $u['name'] ?? '', $role, $u['passHash'], $u['createdAt'] ?? now()]);
    }
  }
  $ip = $pdo->prepare('INSERT INTO products (id, name, category, safety_stock, created_at, updated_at) VALUES (?,?,?,?,?,?)');
  $validIds = [];
  foreach (($d['products'] ?? []) as $p) {
    if (empty($p['name'])) continue;
    $pid = !empty($p['id']) ? (string)$p['id'] : uuid();
    $validIds[$pid] = true;
    $ip->execute([$pid, $p['name'], $p['category'] ?? '', max(0, (int)($p['safetyStock'] ?? 0)), now(), now()]);
  }
  $il = $pdo->prepare('INSERT INTO lots (id, product_id, lot_no, qty, due_date, status, color, dest, note, kiji_date, painted_date, shipped_date, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  foreach (($d['lots'] ?? []) as $l) {
    if (empty($l['productId']) || !isset($validIds[$l['productId']])) continue;
    $status = in_array($l['status'] ?? '', $validStatus, true) ? $l['status'] : 'planned';
    $il->execute([!empty($l['id']) ? (string)$l['id'] : uuid(), $l['productId'], $l['lotNo'] ?? '', max(0, (int)($l['qty'] ?? 0)), $l['dueDate'] ?? '', $status, $l['color'] ?? '', $l['dest'] ?? '', $l['note'] ?? '', $l['kijiDate'] ?? '', $l['paintedDate'] ?? '', $l['shippedDate'] ?? '', now(), now()]);
  }
  $iloss = $pdo->prepare('INSERT INTO losses (id, product_id, bucket, qty, color, loss_date, reason, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
  foreach (($d['losses'] ?? []) as $x) {
    if (empty($x['productId']) || !isset($validIds[$x['productId']])) continue;
    $bucket = in_array($x['bucket'] ?? '', ['kiji', 'painted'], true) ? $x['bucket'] : 'kiji';
    $iloss->execute([!empty($x['id']) ? (string)$x['id'] : uuid(), $x['productId'], $bucket, max(0, (int)($x['qty'] ?? 0)), $x['color'] ?? '', $x['lossDate'] ?? '', $x['reason'] ?? '', $x['createdBy'] ?? '', now()]);
  }
  $ipi = $pdo->prepare('INSERT INTO paint_instructions (id, product_id, kiji_lot_no, kiji_date, paint_date, ship_by, items_json, total_qty, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
  foreach (($d['paintInstructions'] ?? []) as $pi) {
    if (empty($pi['productId']) || !isset($validIds[$pi['productId']])) continue;
    $itemsJ = is_array($pi['items'] ?? null) ? json_encode($pi['items'], JSON_UNESCAPED_UNICODE) : '[]';
    $ipi->execute([!empty($pi['id']) ? (string)$pi['id'] : uuid(), $pi['productId'], $pi['kijiLotNo'] ?? '', $pi['kijiDate'] ?? '', $pi['paintDate'] ?? '', $pi['shipBy'] ?? '', $itemsJ, max(0, (int)($pi['totalQty'] ?? 0)), $pi['createdBy'] ?? '', $pi['createdAt'] ?? now()]);
  }
  foreach (($d['destinations'] ?? []) as $name) ensure_destination((string)$name);
  $pdo->commit();
}

function get_state(array $user): array {
  $pdo = db();
  $products = array_map('map_product', $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll());
  $lots = array_map('map_lot', $pdo->query('SELECT * FROM lots')->fetchAll());
  $losses = array_map('map_loss', $pdo->query('SELECT * FROM losses ORDER BY loss_date DESC, created_at DESC')->fetchAll());
  $paintInstructions = array_map('map_paint_instruction', $pdo->query('SELECT * FROM paint_instructions ORDER BY created_at DESC LIMIT 500')->fetchAll());
  $destinations = array_map(fn($r) => $r['name'], $pdo->query('SELECT name FROM destinations ORDER BY name')->fetchAll());
  $users = [];
  if ($user['role'] === 'editor') {
    $users = $pdo->query('SELECT id, email, name, role FROM users ORDER BY created_at')->fetchAll();
  }
  return ['products' => $products, 'lots' => $lots, 'losses' => $losses, 'paintInstructions' => $paintInstructions, 'destinations' => $destinations, 'users' => $users];
}

/* バケット（kiji/painted）の現在の利用可能在庫 = ロット合計 − 破損合計 */
function bucket_available(string $pid, string $bucket): int {
  $pdo = db();
  $a = $pdo->prepare('SELECT COALESCE(SUM(qty),0) s FROM lots WHERE product_id=? AND status=?');
  $a->execute([$pid, $bucket]);
  $b = $pdo->prepare('SELECT COALESCE(SUM(qty),0) s FROM losses WHERE product_id=? AND bucket=?');
  $b->execute([$pid, $bucket]);
  return (int)$a->fetch()['s'] - (int)$b->fetch()['s'];
}
/* 完成在庫の特定カラーの利用可能数 */
function color_available(string $pid, string $color): int {
  $pdo = db();
  $a = $pdo->prepare("SELECT COALESCE(SUM(qty),0) s FROM lots WHERE product_id=? AND status='painted' AND color=?");
  $a->execute([$pid, $color]);
  $b = $pdo->prepare("SELECT COALESCE(SUM(qty),0) s FROM losses WHERE product_id=? AND bucket='painted' AND color=?");
  $b->execute([$pid, $color]);
  return (int)$a->fetch()['s'] - (int)$b->fetch()['s'];
}

/* ── ヘルパ ── */
function product_by_id(string $id): ?array {
  $st = db()->prepare('SELECT * FROM products WHERE id = ?');
  $st->execute([$id]);
  $r = $st->fetch();
  return $r ?: null;
}
function lot_by_id(string $id): ?array {
  $st = db()->prepare('SELECT * FROM lots WHERE id = ?');
  $st->execute([$id]);
  $r = $st->fetch();
  return $r ?: null;
}

/* 製品名から既存を探し、無ければ作成。製品行を返す */
function resolve_product(string $name, string $category, array $user): array {
  $pdo = db();
  $name = trim($name);
  $st = $pdo->prepare('SELECT * FROM products WHERE LOWER(name) = LOWER(?)');
  $st->execute([$name]);
  $p = $st->fetch();
  if ($p) {
    if ($category !== '' && $category !== ($p['category'] ?? '')) {
      $pdo->prepare('UPDATE products SET category = ?, updated_at = ? WHERE id = ?')
          ->execute([$category, now(), $p['id']]);
      $p['category'] = $category;
    }
    return $p;
  }
  $id = uuid();
  $pdo->prepare('INSERT INTO products (id, name, category, safety_stock, created_at, updated_at) VALUES (?,?,?,?,?,?)')
      ->execute([$id, $name, $category, 0, now(), now()]);
  audit($user, 'create', 'product', $id, '製品を追加（生産予定から）: ' . $name);
  return product_by_id($id);
}

function ensure_destination(string $name): void {
  $name = trim($name);
  if ($name === '') return;
  $pdo = db();
  $st = $pdo->prepare('SELECT id FROM destinations WHERE name = ?');
  $st->execute([$name]);
  if (!$st->fetch()) {
    $pdo->prepare('INSERT INTO destinations (id, name) VALUES (?,?)')->execute([uuid(), $name]);
  }
}

/* 状態に応じて各日付を整理：必要な工程日が無ければ今日で埋め、当該状態より「先」の日付はクリアする */
function stamp_dates(array &$lot): void {
  $t = date('Y-m-d');
  $level = ['planned' => 0, 'kiji' => 1, 'painted' => 2, 'shipped' => 3][$lot['status']] ?? 0;
  $lot['kiji_date']    = $level >= 1 ? (empty($lot['kiji_date'])    ? $t : $lot['kiji_date'])    : '';
  $lot['painted_date'] = $level >= 2 ? (empty($lot['painted_date']) ? $t : $lot['painted_date']) : '';
  $lot['shipped_date'] = $level >= 3 ? (empty($lot['shipped_date']) ? $t : $lot['shipped_date']) : '';
}

/* CSRF & ログイン試行制限 */
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function require_csrf(): void {
  $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  $sess = $_SESSION['csrf'] ?? '';
  if ($sent === '' || $sess === '' || !hash_equals($sess, $sent)) {
    fail('セッションが無効です。ページを再読み込みしてください。', 403);
  }
}
function client_ip(): string {
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function check_login_rate(string $ip): void {
  $pdo = db();
  $st = $pdo->prepare('SELECT fails, locked_until FROM login_attempts WHERE ip=?');
  $st->execute([$ip]);
  $r = $st->fetch();
  if (!$r || empty($r['locked_until'])) return;
  $until = strtotime($r['locked_until']);
  if ($until && $until > time()) {
    $sec = $until - time();
    fail("ログイン試行が多すぎます。約{$sec}秒お待ちください。", 429);
  }
}
function record_login_fail(string $ip): void {
  $pdo = db();
  $st = $pdo->prepare('SELECT fails, updated_at FROM login_attempts WHERE ip=?');
  $st->execute([$ip]);
  $r = $st->fetch();
  $now = now();
  if (!$r) {
    $pdo->prepare('INSERT INTO login_attempts (ip, fails, locked_until, updated_at) VALUES (?,1,NULL,?)')->execute([$ip, $now]);
    return;
  }
  $fails = (int)$r['fails'] + 1;
  if (strtotime($r['updated_at']) < time() - 900) $fails = 1;
  $locked = ($fails >= 5) ? date('Y-m-d H:i:s', time() + 900) : null;
  $pdo->prepare('UPDATE login_attempts SET fails=?, locked_until=?, updated_at=? WHERE ip=?')
      ->execute([$fails, $locked, $now, $ip]);
}
function clear_login_fails(string $ip): void {
  db()->prepare('DELETE FROM login_attempts WHERE ip=?')->execute([$ip]);
}

/* サンプル初期データ投入（既存の製品/ロット/出荷先は呼び出し側で削除済み前提） */
function seed_sample(array $user): void {
  $pdo = db();
  $mk = function ($name, $cat, $safety) use ($pdo, $user) {
    $id = uuid();
    $pdo->prepare('INSERT INTO products (id, name, category, safety_stock, created_at, updated_at) VALUES (?,?,?,?,?,?)')
        ->execute([$id, $name, $cat, $safety, now(), now()]);
    return $id;
  };
  $p = [
    $mk('Caシリーズ', 'Ca', 3),
    $mk('MPシリーズ', 'MP', 3),
    $mk('仏壇 小', '仏壇', 2),
    $mk('リリー L', 'リリー', 2),
    $mk('PCケース', 'PC', 2),
    $mk('特注品（サンプル）', '特注', 0),
  ];
  $day = fn($o) => date('Y-m-d', strtotime("$o day"));
  $lots = [
    [$p[0], 'C-2405A', 6, $day(-20), 'shipped', 'アンティーク', 'Etsy', $day(-22), $day(-20), $day(-14), '春ロット'],
    [$p[0], 'C-2405B', 4, $day(-8),  'painted', 'ナチュラル',   '',     $day(-10), $day(-8),  '',         ''],
    [$p[1], 'M-2405A', 5, $day(-6),  'painted', '白',          '',     $day(-9),  $day(-6),  '',         ''],
    [$p[2], 'B-2404',  3, $day(-4),  'kiji',    '',            '',     $day(-4),  '',        '',         '塗装待ち'],
    [$p[3], 'L-2405',  2, $day(8),   'planned', '',            '',     '',        '',        '',         ''],
    [$p[4], 'P-2405',  4, $day(12),  'planned', '',            '',     '',        '',        '',         ''],
    [$p[5], '',        1, $day(20),  'planned', '',            '',     '',        '',        '',         '受注品・特注'],
  ];
  $ins = $pdo->prepare('INSERT INTO lots (id, product_id, lot_no, qty, due_date, status, color, dest, kiji_date, painted_date, shipped_date, note, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  foreach ($lots as $l) {
    $ins->execute([uuid(), $l[0], $l[1], $l[2], $l[3], $l[4], $l[5], $l[6], $l[7], $l[8], $l[9], $l[10], now(), now()]);
  }
  foreach (['Etsy', 'オンラインショップ', '店頭', '卸'] as $d) ensure_destination($d);
  audit($user, 'reset', 'system', '', 'サンプル初期状態にリセット');
}
