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

function get_state(array $user): array {
  $pdo = db();
  $products = array_map('map_product', $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll());
  $lots = array_map('map_lot', $pdo->query('SELECT * FROM lots')->fetchAll());
  $losses = array_map('map_loss', $pdo->query('SELECT * FROM losses ORDER BY loss_date DESC, created_at DESC')->fetchAll());
  $destinations = array_map(fn($r) => $r['name'], $pdo->query('SELECT name FROM destinations ORDER BY name')->fetchAll());
  $users = [];
  if ($user['role'] === 'editor') {
    $users = $pdo->query('SELECT id, email, name, role FROM users ORDER BY created_at')->fetchAll();
  }
  return ['products' => $products, 'lots' => $lots, 'losses' => $losses, 'destinations' => $destinations, 'users' => $users];
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

/* 状態に応じて各日付を補完（未設定のものだけ今日で埋める） */
function stamp_dates(array &$lot): void {
  $t = date('Y-m-d');
  $s = $lot['status'];
  if (($s === 'kiji' || $s === 'painted' || $s === 'shipped') && empty($lot['kiji_date'])) $lot['kiji_date'] = $t;
  if (($s === 'painted' || $s === 'shipped') && empty($lot['painted_date'])) $lot['painted_date'] = $t;
  if ($s === 'shipped' && empty($lot['shipped_date'])) $lot['shipped_date'] = $t;
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
