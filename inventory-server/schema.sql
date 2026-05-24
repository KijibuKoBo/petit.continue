-- MySQL（エックスサーバー）用 参照スキーマ
-- 通常はアプリが初回アクセス時に自動でテーブルを作成するため、
-- このファイルを手動で流し込む必要はありません。
-- 内容を確認したい・手動で作りたい場合の参考用です。

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id VARCHAR(36) PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  name VARCHAR(255) DEFAULT '',
  role VARCHAR(16) NOT NULL DEFAULT 'viewer',
  pass_hash VARCHAR(255) NOT NULL,
  created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(64) DEFAULT '',
  safety_stock INT NOT NULL DEFAULT 0,
  created_at DATETIME,
  updated_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lots (
  id VARCHAR(36) PRIMARY KEY,
  product_id VARCHAR(36) NOT NULL,
  lot_no VARCHAR(64) DEFAULT '',
  qty INT NOT NULL DEFAULT 0,
  due_date VARCHAR(10) DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'planned',
  color VARCHAR(64) DEFAULT '',
  dest VARCHAR(255) DEFAULT '',
  kiji_date VARCHAR(10) DEFAULT '',
  painted_date VARCHAR(10) DEFAULT '',
  shipped_date VARCHAR(10) DEFAULT '',
  note TEXT,
  created_at DATETIME,
  updated_at DATETIME,
  INDEX idx_lots_product (product_id),
  INDEX idx_lots_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS destinations (
  id VARCHAR(36) PRIMARY KEY,
  name VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id VARCHAR(36) PRIMARY KEY,
  user_id VARCHAR(36) DEFAULT '',
  user_email VARCHAR(255) DEFAULT '',
  action VARCHAR(32) NOT NULL,
  entity VARCHAR(32) DEFAULT '',
  entity_id VARCHAR(36) DEFAULT '',
  summary VARCHAR(500) DEFAULT '',
  detail TEXT,
  created_at DATETIME,
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
