# 在庫管理（サーバー共有版）セットアップ手順

ブラウザ内保存だった在庫管理を、**サーバー上のデータベースに保存して複数端末で共有**できるようにしたものです。
データの保存先以外（画面・操作）は従来版とほぼ同じです。

- 本番：エックスサーバー（PHP + MySQL）
- 手元テスト：PHP の内蔵サーバー + SQLite（**追加インストール不要**）
- ログイン認証・編集／閲覧権限・**変更履歴（誰が・いつ・何を）** を搭載

---

## 1. まず手元でテストする（おすすめ）

PHP さえ入っていれば、MySQL も XAMPP も不要で動作確認できます。

### 1-1. PHP があるか確認
ターミナル（Mac のターミナル / Windows のコマンドプロンプト）で：
```
php -v
```
表示されれば OK。無ければ XAMPP（https://www.apachefriends.org/jp/）を入れると `php` が使えます。

### 1-2. 設定ファイルを作る
`inventory-server/api/` の中で、`config.sample.php` を `config.php` という名前でコピーします。
```
cd inventory-server/api
cp config.sample.php config.php        （Windows は: copy config.sample.php config.php）
```
中身はそのままで OK（初期設定が SQLite になっています）。

### 1-3. 起動
`inventory-server` フォルダに移動して、内蔵サーバーを起動します。
```
cd ..            （inventory-server に戻る）
php -S localhost:8000
```

### 1-4. ブラウザで開く
http://localhost:8000/index.html

初回は「初期管理者の作成」が出ます。メールとパスワードを登録すると、編集権限の管理者としてログインします。
別のブラウザ（またはスマホを同じWi-Fiで `http://パソコンのIP:8000/index.html`）から開くと、**同じデータが見える**ことを確認できます。

停止は、ターミナルで `Ctrl + C`。
（テストで作ったデータは `inventory-server/data/inventory.sqlite` に入っています。消したい時はこのファイルを削除）

---

## 2. エックスサーバーに本番公開する

### 2-1. データベースを作成
サーバーパネル → 「**MySQL設定**」で次を作成し、控えておきます。
1. MySQLデータベースを追加（例：`ユーザーID_inventory`）
2. MySQLユーザーを追加（例：`ユーザーID_user`）＋パスワード
3. 作成したユーザーを、作成したデータベースに「アクセス権所有ユーザー」として追加

### 2-2. config.php を本番用に編集
`inventory-server/api/config.php` を開き、上の値に合わせて編集します。
```php
define('DB_DRIVER', 'mysql');               // ← sqlite から mysql に変更
define('DB_HOST', 'localhost');             // Xserver は通常 localhost
define('DB_NAME', 'ユーザーID_inventory');   // 2-1 で作ったDB名
define('DB_USER', 'ユーザーID_user');        // 2-1 で作ったユーザー名
define('DB_PASS', '設定したパスワード');
define('RESET_PIN', '7722');                // リセット/全消去の実行用。変更推奨
```

### 2-3. ファイルをアップロード
FTP（FileZilla 等）またはサーバーパネルのファイルマネージャで、`inventory-server` の中身を
公開フォルダ（例：`ドメイン/public_html/inventory/`）にアップロードします。

アップロードするもの：
```
index.html
api/  （config.php, db.php, lib.php, index.php, config.sample.php）
```
- `data/` フォルダと `*.sqlite` は本番（MySQL）では不要です。
- テーブルは初回アクセス時に**自動作成**されます（`schema.sql` を手動で流す必要はありません）。

### 2-4. SSL（https）を有効化
サーバーパネル →「SSL設定」で無料SSLを有効化してください（ログインを安全にするため必須）。
反映後、`https://あなたのドメイン/inventory/index.html` で開きます。

### 2-5. 動作確認
ブラウザでアクセス → 初回は「初期管理者の作成」。
登録後、別端末からも同じURLでログインして、数字が共有されることを確認します。

---

## 3. 使い方メモ

- **権限**：「ユーザー」タブで編集／閲覧を追加。閲覧は参照のみ。編集権限は最低1人必要。
- **変更履歴**：「変更履歴」タブで、誰が・いつ・何を変更したかを確認できます（最新300件）。
- **バックアップ**：「データ管理」→「JSONを書き出し」。読み込みは製品・生産予定・出荷先を置き換えます。
- **初期化**：「リセット」「全消去」は `RESET_PIN`（初期値 7722）の入力が必要です。

## 4. セキュリティ上の注意

- `api/config.php` は DB パスワードを含みます。Git では追跡しません（`.gitignore` 済み）。第三者に渡さないでください。
- 本番は必ず **https** で運用してください。
- パスワードはサーバー側で安全にハッシュ化（bcrypt）して保存しています。
