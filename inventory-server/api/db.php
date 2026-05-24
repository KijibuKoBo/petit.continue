<?php
$cfg = __DIR__ . '/config.php';
if (!is_file($cfg)) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'config.php がありません。config.sample.php をコピーして作成してください。'], JSON_UNESCAPED_UNICODE);
  exit;
}
require_once $cfg;

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  if (DB_DRIVER === 'sqlite') {
    $dir = dirname(SQLITE_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . SQLITE_PATH);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
  } else {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
  }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
  migrate($pdo);
  return $pdo;
}

function migrate(PDO $pdo): void {
  $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  $dt   = $mysql ? 'DATETIME' : 'TEXT';
  $int  = $mysql ? 'INT' : 'INTEGER';
  $eng  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

  $pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) DEFAULT '',
    role VARCHAR(16) NOT NULL DEFAULT 'viewer',
    pass_hash VARCHAR(255) NOT NULL,
    created_at $dt
  )$eng");

  $pdo->exec("CREATE TABLE IF NOT EXISTS products (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(64) DEFAULT '',
    safety_stock $int NOT NULL DEFAULT 0,
    created_at $dt,
    updated_at $dt
  )$eng");

  $pdo->exec("CREATE TABLE IF NOT EXISTS lots (
    id VARCHAR(36) PRIMARY KEY,
    product_id VARCHAR(36) NOT NULL,
    lot_no VARCHAR(64) DEFAULT '',
    qty $int NOT NULL DEFAULT 0,
    due_date VARCHAR(10) DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'planned',
    color VARCHAR(64) DEFAULT '',
    dest VARCHAR(255) DEFAULT '',
    kiji_date VARCHAR(10) DEFAULT '',
    painted_date VARCHAR(10) DEFAULT '',
    shipped_date VARCHAR(10) DEFAULT '',
    note TEXT,
    created_at $dt,
    updated_at $dt
  )$eng");

  $pdo->exec("CREATE TABLE IF NOT EXISTS destinations (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE
  )$eng");

  $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) DEFAULT '',
    user_email VARCHAR(255) DEFAULT '',
    action VARCHAR(32) NOT NULL,
    entity VARCHAR(32) DEFAULT '',
    entity_id VARCHAR(36) DEFAULT '',
    summary VARCHAR(500) DEFAULT '',
    detail TEXT,
    created_at $dt
  )$eng");

  $pdo->exec("CREATE TABLE IF NOT EXISTS losses (
    id VARCHAR(36) PRIMARY KEY,
    product_id VARCHAR(36) NOT NULL,
    bucket VARCHAR(16) NOT NULL,
    qty $int NOT NULL DEFAULT 0,
    color VARCHAR(64) DEFAULT '',
    loss_date VARCHAR(10) DEFAULT '',
    reason VARCHAR(255) DEFAULT '',
    created_by VARCHAR(255) DEFAULT '',
    created_at $dt
  )$eng");

  foreach ([
    'CREATE INDEX idx_lots_product ON lots (product_id)',
    'CREATE INDEX idx_lots_status ON lots (status)',
    'CREATE INDEX idx_audit_created ON audit_log (created_at)',
    'CREATE INDEX idx_losses_product ON losses (product_id)',
  ] as $sql) {
    try { $pdo->exec($sql); } catch (PDOException $e) { /* already exists */ }
  }
}
