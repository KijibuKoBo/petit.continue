<?php
/*
 * このファイルを「config.php」という名前でコピーし、環境に合わせて編集してください。
 *   cp config.sample.php config.php
 *
 * config.php はパスワードを含むため、Git では追跡しません（.gitignore 済み）。
 */

/* ── データベース種別 ──
 *  'sqlite' : 手元での動作テスト用（追加インストール不要）
 *  'mysql'  : 本番（エックスサーバー）用
 */
define('DB_DRIVER', 'sqlite');

/* ── SQLite（ローカルテスト用）── */
define('SQLITE_PATH', __DIR__ . '/../data/inventory.sqlite');

/* ── MySQL（エックスサーバー用）──
 * Xserver サーバーパネル → 「MySQL設定」で作成した値を入れます。
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'xxxxx_inventory');   // 作成したデータベース名
define('DB_USER', 'xxxxx_user');        // 作成したユーザー名
define('DB_PASS', 'パスワードをここに');
define('DB_CHARSET', 'utf8mb4');

/* ── 初期化用パスワード（「リセット」「全消去」の実行時に必要）── */
define('RESET_PIN', '7722');
