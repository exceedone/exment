# 機能別ファイルマッピング仕様書
このドキュメントは、Exment の各機能が「どのファイル/フォルダに紐づき」「どの順番で処理されるか」を保守者向けに整理したものです。  
対象は `packages/takei5404/exment` を中心に、`exment-dev` 側との接続点も含みます。

## 1. まず押さえる全体像
### 1.1 起動時の結線
- 入口: `packages/takei5404/exment/composer.json`
  - `extra.laravel.providers` で `Exceedone\Exment\ExmentServiceProvider` が自動登録される。
- 中央ハブ: `packages/takei5404/exment/src/ExmentServiceProvider.php`
  - ルート系 Provider 登録
    - `src/Providers/RouteServiceProvider.php`
    - `src/Providers/Route2factorServiceProvider.php`
    - `src/Providers/RouteOAuthServiceProvider.php`
    - `src/Providers/RoutePublicFormServiceProvider.php`
    - `src/Providers/PluginServiceProvider.php`
  - Middleware alias/group 登録
  - migration/view/lang のロード
  - DBコネクション拡張（MySQL/MariaDB/SQL Server）

### 1.2 ルートの大分類
- 管理画面Web: `src/Providers/RouteServiceProvider.php`
  - 主に `middleware: ['adminweb', 'admin']`
- 管理API/WebAPI: `src/Providers/RouteServiceProvider.php`
  - `adminapi`, `adminwebapi`, `publicformapi` を使い分け
- 公開フォーム: `src/Providers/RoutePublicFormServiceProvider.php`
  - `middleware: ['adminweb', 'publicform']`
- OAuth: `src/Providers/RouteOAuthServiceProvider.php`
- 2要素認証: `src/Providers/Route2factorServiceProvider.php`
- プラグイン動的ルート: `src/Providers/PluginServiceProvider.php`

### 1.3 共通Controller層
- 汎用ベース: `src/Controllers/AdminControllerBase.php`
- テーブル単位ベース: `src/Controllers/AdminControllerTableBase.php`
  - `CustomTable` 解決、権限検証、共通CRUDフローを担当
- 共通画面ヘッダ設定: `src/Controllers/ExmentControllerTrait.php`

## 2. フォルダ責務（機能に横断）
- `src/Controllers`: HTTP入口
- `src/Model`: 永続化、業務状態、権限判定
- `src/Services`: 通知、検索、インポート/エクスポート等の業務サービス
- `src/DataItems`: Grid/Form/Show の描画ロジック（画面部品）
- `src/Middleware`: 初期化、認証、IP制御、ログ等
- `resources/views`: Bladeテンプレート
- `resources/lang`: 文言
- `database/migrations`: スキーマ
- `src/Web/ts`, `src/Web/scss`: フロントソース
- `public/vendor/exment/js`, `public/vendor/exment/css`: 配布用アセット

## 3. 機能別マッピング
## 3.1 テーブル表示（データ一覧/詳細/編集）
### 入口
- ルート定義: `src/Providers/RouteServiceProvider.php`
  - `setTableResouce($router, 'data', 'CustomValueController', true)`
  - 追加API:
    - `data/{tableKey}/import`
    - `data/{tableKey}/{id}/actionClick`
    - `data/{tableKey}/{id}/workflowHistoryModal`
    - `data/{tableKey}/{id}/fileupload` など

### 主処理ファイル
- Controller: `src/Controllers/CustomValueController.php`
  - `index`, `create`, `edit`, `show`, `store`, `update`, `destroy`
  - `firstFlow()` で権限/可否チェックと view/form の決定
- Model:
  - `src/Model/CustomTable.php`
  - `src/Model/CustomValue.php`
  - `src/Model/CustomView.php`
  - `src/Model/CustomForm.php`
- 描画:
  - `src/DataItems/Grid/*.php`（一覧系）
  - `src/DataItems/Form/*.php`（入力系）
  - `src/DataItems/Show/*.php`（詳細系）

### 画面描画の分岐点
- `CustomView::getGridItemAttribute()` が `view_kind_type` でGrid実装を切替:
  - DEFAULT -> `DataItems/Grid/DefaultGrid.php`
  - AGGREGATE -> `DataItems/Grid/SummaryGrid.php`
  - CALENDAR -> `DataItems/Grid/CalendarGrid.php`
  - FILTER -> `DataItems/Grid/FilterGrid.php`
  - PLUGIN -> `DataItems/Grid/PluginGrid.php`
  - ALLDATA -> `DataItems/Grid/AllDataGrid.php`
- 定義: `src/Enums/ViewKindType.php`

### 関連View/資産
- View:
  - `resources/views/custom-value/*`
  - `resources/views/custom-form/*`
- JS/CSS:
  - `public/vendor/exment/js/customform.js`
  - `public/vendor/exment/js/changefield.js`
  - `public/vendor/exment/js/modal.js`
  - `public/vendor/exment/css/customform.css`

### 実行シーケンス（一覧表示）
1. `/admin/data/{tableKey}` 到達（RouteServiceProvider）
2. `adminweb + admin` Middleware 通過（認証/初期化/権限/IP）
3. `CustomValueController@index`
4. `firstFlow()` で権限・テーブル状態検証
5. `custom_view->grid_item->grid()` で対象Grid生成
6. `DataItems/Grid/*` が列/フィルタ/アクションを組み立て
7. `resources/views/custom-value/*` とJS/CSSで描画

## 3.2 テーブル定義（カスタムテーブル管理）
### 入口
- ルート:
  - `setResouce($router, 'table', 'CustomTableController')`
  - 追加: `table/{id}/copyModal`, `table/{id}/copy`, `table/menuModal/{id}`

### 主処理ファイル
- `src/Controllers/CustomTableController.php`
- `src/Model/CustomTable.php`
- 関連:
  - `src/Model/CustomColumn.php`
  - `src/Model/CustomForm.php`
  - `src/Model/CustomView.php`
  - `src/Services/TableService.php`

### 役割
- テーブル本体設定、コピー、メニュー表示、QR/JANオプション制御
- テーブル定義変更は、以降のデータ表示・フォーム生成・API挙動に反映される

## 3.3 ビュー設定（表示条件・集計・フィルタ）
### 入口
- ルート:
  - `setTableResouce($router, 'view', 'CustomViewController')`
  - `view/{tableKey}/filter-condition`
  - `view/{tableKey}/summary-condition`
  - `view/{tableKey}/group-condition`

### 主処理ファイル
- `src/Controllers/CustomViewController.php`
- `src/Model/CustomView.php`
- `src/Model/CustomViewColumn.php`
- `src/Model/CustomViewFilter.php`
- `src/Model/CustomViewSummary.php`
- `src/DataItems/Grid/SummaryGrid.php`

### 役割
- 表示列、並び順、絞込条件、集計条件を構築
- `CustomValueController@index` で実際の一覧描画時に消費される

## 3.4 フォーム設定（管理画面フォーム）
### 入口
- ルート:
  - `setTableResouce($router, 'form', 'CustomFormController')`
  - `form/{tableKey}/preview`, `form/{tableKey}/settingModal` など

### 主処理ファイル
- `src/Controllers/CustomFormController.php`
- `src/Model/CustomForm.php`
- `src/Model/CustomFormBlock.php`
- `src/Model/CustomFormColumn.php`
- `src/Model/CustomFormPriority.php`
- `src/DataItems/Form/DefaultForm.php`

### 関連View
- `resources/views/custom-form/*`

## 3.5 公開フォーム
### 入口
- ルート定義: `src/Providers/RoutePublicFormServiceProvider.php`
  - `{publicform_prefix}/{form_key}/`
  - `confirm`, `create`, `files/{uuid}`, `tmpfiles/{uuid}`

### 主処理ファイル
- Controller:
  - `src/Controllers/PublicFormController.php`
  - `src/Controllers/PublicFormApiDataController.php`
- Model:
  - `src/Model/PublicForm.php`
  - `src/Model/CustomForm.php`
- 描画:
  - `src/DataItems/Form/PublicFormForm.php`
  - `src/DataItems/Show/PublicFormShow.php`
  - `resources/views/public-form/*`

### 実行シーケンス
1. 公開URLアクセス
2. `publicform` middleware で公開フォーム向け認証/セッション処理
3. `PublicFormController@index/confirm/create`
4. `PublicForm::getForm()/getShow()` で画面生成
5. 保存後は `notify_complete_admin/user` に沿って通知実行

## 3.6 ワークフロー
### 入口
- ルート:
  - `setResouce($router, 'workflow', 'WorkflowController')`
  - `workflow/beginning`
  - `workflow/{id}/activate`, `deactivate`, `modal/*`
  - データ画面側連携: `data/{tableKey}/{id}/actionClick`, `workflowHistoryModal`

### 主処理ファイル
- Controller:
  - `src/Controllers/WorkflowController.php`
  - `src/Controllers/ApiWorkflowController.php`
  - `src/Controllers/WorkflowNotifyController.php`
- Model:
  - `src/Model/Workflow.php`
  - `src/Model/WorkflowStatus.php`
  - `src/Model/WorkflowAction.php`
  - `src/Model/WorkflowTable.php`
  - `src/Model/WorkflowValue.php`
  - `src/Model/WorkflowAuthority.php`
  - `src/Model/WorkflowConditionHeader.php`
- View:
  - `resources/views/workflow/beginning.blade.php`
  - `resources/views/workflow/status-selects.blade.php`
  - `resources/views/workflow/options.blade.php`

### コア処理
- 実行本体は `WorkflowAction::execute()`（`src/Model/WorkflowAction.php`）
  - 遷移前後のWorkflowValue更新
  - 次担当者（authority）算出
  - 通知連携
- データ側の利用:
  - `CustomValue::getWorkflowActions()`
  - `CustomValue::getWorkflowHistories()`
  - `CustomValue::isWorkflowCompleted()`

### 実行シーケンス（アクション実行）
1. 画面またはAPIで対象レコードに対して実行要求
2. `CustomValueController` または `ApiWorkflowController::execute()`
3. `CustomValue::getWorkflowActions()` で実行可能アクション抽出
4. `WorkflowAction::execute()` で遷移/履歴/担当更新
5. 必要に応じて通知・次アクション可否を更新

## 3.7 ダッシュボード
### 入口
- ルート:
  - `dashboard`（resource）
  - `dashboardbox`（resource）
  - `dashboardbox/table_views/{dashboard_type}`

### 主処理ファイル
- `src/Controllers/DashboardController.php`
- `src/Controllers/DashboardBoxController.php`
- `src/Model/Dashboard.php`
- `src/Model/DashboardBox.php`

### 関連View
- `resources/views/dashboard/box.blade.php`
- `resources/views/dashboard/header.blade.php`

## 3.8 ファイル/ドキュメント
### 入口
- ルート:
  - `files/{uuid}`, `files/{tableKey}/{uuid}`
  - API版: `downloadApi`, `downloadTableApi`, `deleteApi`
  - 一時ファイル: `tmpfiles`, `tmpimages`

### 主処理ファイル
- `src/Controllers/FileController.php`
- `src/Model/File.php`
- `src/Storage/Disk/*.php`

### 役割
- テーブル紐づき/公開フォーム/API でのダウンロード制御
- ストレージディスク設定は `Middleware/Initialize.php` で初期化される

## 3.9 API / WebAPI
### 入口
- ルート定義: `src/Providers/RouteServiceProvider.php`
  - `/{adminPrefix}/webapi/*`
  - `/{adminPrefix}/api/*`
  - `/{publicformapiPrefix}/{form_key}/*`

### 主処理ファイル
- `src/Controllers/ApiDataController.php`
- `src/Controllers/ApiTableController.php`
- `src/Controllers/ApiController.php`
- `src/Controllers/ApiWorkflowController.php`

### 主な業務クラス
- `src/Model/CustomTable.php`
- `src/Model/CustomView.php`
- `src/Services/DataImportExport/DataImportExportService.php`

### 特徴
- Scope制御（`ApiScope`）と middleware で機能単位権限を付与
- データ取得、作成更新削除、ドキュメント作成、ワークフロー実行までAPI化

## 3.10 認証（ログイン/2FA/OAuth/SAML）
### 入口
- 通常ログイン: `AuthController` 系（RouteServiceProvider）
- 2FA: `Route2factorServiceProvider` -> `Auth2factorController`
- OAuth2: `RouteOAuthServiceProvider`（Laravel Passport Controller）
- SAML/OAuthログイン連携:
  - `AuthSamlController.php`
  - `AuthOAuthController.php`

### 主処理ファイル
- `src/Middleware/Initialize.php`
  - Guard/provider 設定
  - 認証関連 config 初期化
- `src/Providers/LoginUserProvider.php`
- `src/Providers/PublicFormUserProvider.php`

## 3.11 プラグイン
### 入口
- `src/Providers/PluginServiceProvider.php`
  - DB上の有効プラグイン設定を読み、動的に Route 生成

### 主処理ファイル
- `src/Model/Plugin.php`
- `src/Services/Plugin/*`
- `src/Controllers/PluginController.php`
- `src/Controllers/PluginCodeController.php`

### 役割
- PAGE/VIEW/API/DASHBOARD/CRUD のプラグインタイプごとにルーティング
- プラグインJS/CSSの公開ルート追加

## 3.12 バックアップ/テンプレート/通知
- バックアップ:
  - Controller: `src/Controllers/BackupController.php`
  - Service: `src/Services/BackupRestore/*`
- テンプレート:
  - Controller: `src/Controllers/TemplateController.php`
  - データ: `templates/`, `system_template/`
- 通知:
  - Controller: `src/Controllers/NotifyController.php`, `CustomNotifyController.php`
  - Service: `src/Services/NotifyService.php`
  - Model: `src/Model/Notify.php`, `NotifyNavbar.php`

## 3.13 ユーザー/組織/ロール管理
### 入口
- ルート:
  - `loginuser/*` -> `LoginUserController`
  - `role_group/*` -> `RoleGroupController`
  - `auth/menu/*` -> `MenuController`

### 主処理ファイル
- Controller:
  - `src/Controllers/LoginUserController.php`
  - `src/Controllers/RoleGroupController.php`
  - `src/Controllers/MenuController.php`
- Model:
  - `src/Model/LoginUser.php`
  - `src/Model/RoleGroup.php`
  - `src/Model/RoleGroupPermission.php`
  - `src/Model/Menu.php`
- Service:
  - `src/Services/DataImportExport/*`（ユーザー/ロール取り込み）

### 役割
- ユーザー情報のCRUD、CSV等インポート
- ロールグループ・権限の付与
- 管理メニュー構成（表示順・対象機能）の編集

## 3.14 ログイン設定（ローカル/OAuth/SAML/2FA）
### 入口
- ルート:
  - `login_setting/*` -> `LoginSettingController`
  - `auth/login/{provider}` -> `AuthOAuthController`
  - `saml/login/{provider}` -> `AuthSamlController`
  - `auth-2factor/*` -> `Auth2factorController`

### 主処理ファイル
- Controller:
  - `src/Controllers/LoginSettingController.php`
  - `src/Controllers/AuthController.php`
  - `src/Controllers/AuthOAuthController.php`
  - `src/Controllers/AuthSamlController.php`
  - `src/Controllers/Auth2factorController.php`
- Model:
  - `src/Model/LoginSetting.php`
  - `src/Model/LoginUser.php`
- Service:
  - `src/Services/Login/LoginService.php`
  - `src/Services/Auth2factor/Auth2factorService.php`

### 役割
- 認証方式ごとの接続設定、有効/無効切替、接続テスト
- 2要素認証の検証とログインフロー統合

## 3.15 APIクライアント管理
### 入口
- ルート:
  - `api_setting/*` -> `ApiSettingController`
  - `oauth/*` -> `RouteOAuthServiceProvider`（Passport）

### 主処理ファイル
- Controller:
  - `src/Controllers/ApiSettingController.php`
  - `src/Providers/RouteOAuthServiceProvider.php`
- Model:
  - `src/Model/ApiClient.php`
  - `src/Model/ApiClientRepository.php`

### 役割
- APIクライアント（キー/シークレット・利用種別）の管理
- OAuthトークン払い出しフローの提供

## 3.16 通知機能（運用通知・ベル通知）
### 入口
- ルート:
  - `notify/*` -> `NotifyController` / `CustomNotifyController`
  - `notify_navbar/*` -> `NotifyNavbarController`

### 主処理ファイル
- Controller:
  - `src/Controllers/NotifyController.php`
  - `src/Controllers/CustomNotifyController.php`
  - `src/Controllers/NotifyNavbarController.php`
- Model:
  - `src/Model/Notify.php`
  - `src/Model/NotifyNavbar.php`
  - `src/Model/NotifyTarget.php`
- Service:
  - `src/Services/NotifyService.php`
  - `src/Notifications/*`

### 役割
- データ更新/ワークフロー契機の通知定義
- 画面右上ベル通知（既読/未読、一括既読）
- メール・Slack・Teams など配信チャネル連携

## 3.17 検索機能（全体検索・関連検索）
### 入口
- ルート:
  - `search`, `search/lists`, `search/list`, `search/header`, `search/relation`
  - Controller: `src/Controllers/SearchController.php`

### 主処理ファイル
- Controller:
  - `src/Controllers/SearchController.php`
- Model:
  - `src/Model/CustomTable.php`（`searchValue`, `searchRelationValue` を呼び出し）
  - `src/Model/CustomView.php`（結果テーブル表示）
- View:
  - `resources/views/search/*`
  - `resources/views/dashboard/list/header.blade.php`（ヘッダ部品再利用）

### 実行シーケンス
1. 検索バー入力で `search/header` が候補生成
2. `search/list(s)` で対象テーブル別に結果取得
3. `CustomView` で表示列整形し、結果テーブルを描画

## 3.18 システム設定・更新管理
### 入口
- ルート:
  - `system`, `system/update`, `system/call_update`, `system/version`
  - Controller: `src/Controllers/SystemController.php`

### 主処理ファイル
- Controller:
  - `src/Controllers/SystemController.php`
- Model:
  - `src/Model/System.php`
- Service:
  - `src/Services/Update/UpdateService.php`
  - `src/Services/SystemRequire/*`

### 役割
- サイト設定、メール設定、全体動作設定
- 要件チェック（実行環境の必須条件確認）
- バージョン確認と更新処理の実行

## 3.19 初期化・インストール
### 入口
- ルート:
  - `install/*` -> `InstallController`
  - `initialize/*` -> `InitializeController`

### 主処理ファイル
- Controller:
  - `src/Controllers/InstallController.php`
  - `src/Controllers/InitializeController.php`
- Service:
  - `src/Services/Installer/InstallService.php`
  - `src/Services/Installer/InitializeFormTrait.php`
- Middleware:
  - `src/Middleware/Initialize.php`（初期化状態判定と遷移制御）

### 役割
- 未初期化環境のセットアップ
- 既存環境の初期化状態に応じた画面遷移（install/initialize/login）

## 3.20 監査ログ・操作ログ
### 入口
- ルート:
  - `auth/logs/*` -> `LogController`

### 主処理ファイル
- Controller:
  - `src/Controllers/LogController.php`
- Model:
  - `src/Model/OperationLog.php`
- Middleware:
  - `src/Middleware/LogOperation.php`
  - `src/Middleware/LogRouteExecutionTime.php`

### 役割
- 操作履歴の記録/閲覧
- 性能計測ログ・監査用途の追跡

## 3.21 プラグイン管理・プラグインコード編集
### 入口
- ルート:
  - `plugin/*` -> `PluginController`
  - `plugin/edit_code/*` -> `PluginCodeController`

### 主処理ファイル
- Controller:
  - `src/Controllers/PluginController.php`
  - `src/Controllers/PluginCodeController.php`
- Model:
  - `src/Model/Plugin.php`
- Service:
  - `src/Services/Plugin/*`
  - `src/Services/Plugin/PluginInstaller.php`

### 役割
- プラグインの導入/有効化/実行（バッチ含む）
- プラグイン配下ファイルの参照・編集

## 3.22 QRコード/JANコード連携
### 入口
- ルート:
  - `qr-code/{tableName}/{id}` -> `QrCodeController@scanRedirect`
  - `jan-code/{id}`, `assign-jan-code` -> `JanCodeController`

### 主処理ファイル
- Controller:
  - `src/Controllers/QrCodeController.php`
  - `src/Controllers/JanCodeController.php`
  - `src/Controllers/CustomValueController.php`（QR生成/ダウンロード）
- Model:
  - `src/Model/CustomTable.php`
  - `src/Model/CustomForm.php`

### 役割
- スキャン結果を対象テーブルの登録/編集画面へ遷移
- ラベル作成、採番、帳票出力（PDF）連携

## 4. フロント資産の流れ
### 4.1 ソース
- TS: `src/Web/ts/*.ts`
- SCSS: `src/Web/scss/*.scss`

### 4.2 出力先
- JS: `public/vendor/exment/js/*.js`
- CSS: `public/vendor/exment/css/*.css`

### 4.3 開発環境接続
- `exment-dev/tsconfig.json` で `outDir` が `packages/takei5404/exment/public/vendor/exment/js` に設定済み
- 反映時は `php artisan exment:publish` で公開資産を更新

## 5. 保守で最初に見るべき順番
1. 機能の入口URLを `RouteServiceProvider` で確認
2. 対応Controllerの `index/create/edit/show` を確認
3. 参照Modelと `DataItems`（Grid/Form/Show）を確認
4. `resources/views` と `public/vendor/exment/*` の描画資産を確認
5. 認証/権限問題なら `ExmentServiceProvider` の middleware group と `Initialize` を確認
6. ワークフロー問題なら `WorkflowAction::execute()` と `CustomValue::getWorkflowActions()` を確認

## 6. 変更影響の見積もり指針
- `src/Providers/*` を変更した場合:
  - ルーティング/認証全体へ波及
- `src/Middleware/Initialize.php` を変更した場合:
  - セッション、認証、ストレージ、タイムゾーン等に広範囲波及
- `src/Model/CustomTable|CustomView|CustomForm|CustomValue` を変更した場合:
  - テーブル表示、入力、API、ワークフローの複数機能へ波及
- `src/DataItems/Grid/*` を変更した場合:
  - 一覧画面全般に波及
- `resources/views/*` のみ変更:
  - 主に表示層（ただしフォーム名やJS連携IDを壊すと機能不全化）
