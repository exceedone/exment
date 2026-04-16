# 保守仕様書
このドキュメントは、`exment-dev` 開発環境および Exment パッケージ（`packages/takei5404/exment`）の保守運用手順を定義します。

## 関連ドキュメント
- 機能別ファイルマッピング: `document/ja/FunctionMapping.md`

## 1. 対象範囲
- 対象アプリケーション:
  - Laravel ホストアプリ（`exment-dev` ルート）
  - Exment パッケージ（`packages/takei5404/exment`）
- 対象作業:
  - 日常運用（監視、ログ確認、データ保全）
  - 不具合調査・一次切り分け
  - 改修、テスト、リリース、ロールバック

## 2. システム構成
### 2.1 レイヤ構成
- ホストアプリ層（Laravel）:
  - `app/`, `config/`, `routes/`, `database/`, `resources/` など
- パッケージ層（Exment）:
  - `packages/takei5404/exment/src`, `resources`, `database`, `public` など

### 2.2 パッケージ連携方式
- `composer.json` の `repositories` に `path` リポジトリを設定し、`symlink: true` で参照。
- `vendor/exceedone/exment` は `packages/takei5404/exment` へのシンボリック参照（Junction）。
- そのため、`packages/takei5404/exment` の編集はアプリ実行時に即時反映される。

### 2.3 Git 管理境界
- Git 管理対象: `packages/takei5404/exment`（Exment 本体リポジトリ）
- Git 管理対象外（この環境）: `exment-dev` ルート
- 改修コミットは `packages/takei5404/exment` で実施する。

## 3. 主要ディレクトリと責務
### 3.1 ホストアプリ側
- `config/`: アプリ設定（環境依存値は `.env`）
- `database/seeders`, `database/factories`: テストデータ作成
- `storage/logs`: アプリログ（障害調査の一次確認先）

### 3.2 Exment パッケージ側
- `src/`: 業務ロジック、Controller、Middleware、Model、ServiceProvider
- `database/migrations`: Exment 用マイグレーション
- `resources/views`: 画面テンプレート
- `resources/lang`: 翻訳リソース
- `public/vendor/exment`: フロント資産（JS/CSS等）
- `tests`: Unit/Feature/Browser テスト

## 4. 起動・反映・キャッシュ
### 4.1 初期セットアップ/更新時
```bash
composer update
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
php artisan passport:keys
```

### 4.2 変更反映
- PHP（`src/`）変更: 通常は即時反映
- TS/SCSS 変更: ビルド後に公開
```bash
# TS/SCSS をビルド後
php artisan exment:publish
```

### 4.3 キャッシュ関連（必要時）
```bash
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

## 5. 日常保守手順
### 5.1 定常確認
- エラーログ確認: `storage/logs/laravel.log`
- DB 接続確認: 管理画面操作、または接続確認コマンド実行
- ストレージ空き容量確認（バックアップ・アップロード失敗予防）

### 5.2 バックアップ
- DB バックアップを定期実行（運用基盤側ジョブ）
- Exment のバックアップ機能を併用する場合は世代管理を実施
- リストア手順は本番前に検証環境でリハーサルする

### 5.3 依存ライブラリ更新
- 原則として小分けで更新し、変更範囲を限定
- 更新後は最低限以下を実行:
  - 画面表示確認
  - 主要登録/更新機能確認
  - API（利用中の場合）確認

## 6. 不具合対応フロー
### 6.1 一次切り分け
1. 事象の再現条件を固定（ユーザー、入力値、時刻、URL）
2. `laravel.log` と Web サーバーログを確認
3. DB エラー、権限エラー、バリデーションエラーを分類
4. ホストアプリ起因か Exment パッケージ起因かを判定

### 6.2 典型調査ポイント
- 画面系不具合:
  - `resources/views` 変更有無
  - `public/vendor/exment` 資産反映漏れ
- 認証/権限:
  - Middleware グループ設定
  - ログイン設定（2FA、OAuth/SAMLの有効化状態）
- DB:
  - マイグレーション適用状態
  - 接続ドライバ差異（MySQL / SQL Server）

### 6.3 暫定対応
- 影響範囲が大きい場合は機能停止フラグ、または画面導線を一時遮断
- データ破損懸念がある場合は更新系処理を停止し、先にバックアップ取得

## 7. 変更管理
### 7.1 ブランチ運用
- 基本方針は `Develop.md` 記載の運用（`master`, `develop`, `feature`, `hotfix` 等）に従う。

### 7.2 コーディング規約
- コミット前に整形・静的解析・テストを実施
```bash
php-cs-fixer fix ./vendor/exceedone/exment
./vendor/bin/phpstan analyse
```

### 7.3 コミット対象
- `packages/takei5404/exment` 配下の改修をコミットする。
- `vendor/exceedone/exment` は参照先であり直接編集対象にしない。

## 8. テスト方針
### 8.1 実行対象
- Unit
- Feature
- Browser（必要時）

### 8.2 代表コマンド
```bash
.\vendor\bin\phpunit .\vendor\exceedone\exment\tests\Unit
.\vendor\bin\phpunit .\vendor\exceedone\exment\tests\Feature
.\vendor\bin\phpunit .\vendor\exceedone\exment\tests\Browser
```

### 8.3 受け入れ判定
- 致命的エラーがないこと
- 既存主要機能（ログイン、一覧、登録、更新、ファイル操作）が回帰しないこと

## 9. リリース手順（標準）
1. `feature` / `hotfix` で改修
2. 静的解析・テスト・手動確認
3. PR 作成（変更内容、影響範囲、確認結果を記載）
4. マージ後、対象環境へデプロイ
5. `composer install/update`、必要に応じて `vendor:publish` / `exment:publish`
6. キャッシュクリア
7. 主要画面の疎通確認

## 10. ロールバック手順（標準）
1. 障害バージョンのデプロイ停止
2. 直前の正常コミットへ切戻し
3. 反映コマンド再実行（必要に応じて publish / cache clear）
4. データ変更を伴う場合は DB リストア要否を判定して実施
5. 原因分析と恒久対策を issue / PR に記録

## 11. 既知の運用注意点
- `exment-dev` ルートは Git 管理されていないため、変更履歴管理の主体は `packages/takei5404/exment`。
- Windows 環境で Git の所有者警告（safe.directory）が出る場合は、Git 設定で安全ディレクトリ登録を行う。
- 開発環境では動作しても、本番環境でシンボリック参照構成が異なる可能性があるため、配備手順を分離して管理する。

## 12. 付録: 保守時の確認コマンド
```bash
# アプリ状態確認
php artisan about
php artisan route:list
php artisan migrate:status

# Exment 系
php artisan list | findstr exment
php artisan exment:checklang
```
