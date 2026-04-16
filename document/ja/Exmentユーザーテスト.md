# ユーザーテストケース（アプリ側手動テスト）
本章は、Exmentの画面操作のみで実施できるUAT（受け入れテスト）ケース一覧です。  

### 前提
- 実施環境:
  - 管理画面URL: `/admin`
  - テストデータ: `exment:inittest` 実行済み
- 主な利用ユーザー:
  - `admin / adminadmin`
  - `user1 / user1user1`（Can access all data）
  - `user2 / user2user2`（user_group所属）
  - `user3 / user3user3`（比較用）
- 主な検証テーブル:
  - `custom_value_edit_all`（全編集）
  - `custom_value_view_all`（全閲覧）
  - `custom_value_access_all`（全アクセス）
  - `custom_value_edit`（担当者編集 + ワークフロー）
  - `custom_value_view`（担当者閲覧）
  - `no_permission`（アクセス不可）
  - `parent_table / child_table / pivot_table`
  - `parent_table_n_n / child_table_n_n / pivot_table_n_n`
  - `parent_table_select / child_table_select / pivot_table_select`
  - `all_columns_table`

### 実施結果記録テンプレート
| 実施日 | 実施者 | ケースID | 結果 (OK/NG) | 証跡URL/スクショ | 備考 |
| --- | --- | --- | --- | --- | --- |
| YYYY-MM-DD | name | UAT-XXX | OK | - | - |

### 必須テストケース（常時実施）
| ケースID | 機能 | 実施ユーザー | 手順（要約） | 期待結果 |
| --- | --- | --- | --- | --- |
| UAT-AUTH-001 | ログイン成功 | admin | `/admin/auth/login` で正しいID/PWを入力 | ダッシュボードへ遷移し、ログイン状態になる |
| UAT-AUTH-002 | ログイン失敗 | admin | 誤ったPWでログイン | エラー表示されログインできない |
| UAT-AUTH-003 | ログアウト | admin | 右上メニューからログアウト | ログイン画面に戻り、保護画面へ直接遷移できない |
| UAT-AUTH-004 | パスワード変更 | admin | `auth/change` でPW変更後、旧PW/新PWで再ログイン確認 | 旧PWは失敗、新PWは成功 |
| UAT-NAVI-001 | ホーム表示 | admin | `/admin` へアクセス | ホーム画面が表示される |
| UAT-NAVI-002 | メニュー表示差分 | user2 | user2でログインし左メニューを確認 | 権限外メニューが表示されない |
| UAT-NAVI-003 | 直URL権限チェック | user2 | 権限外URL（例: `data/no_permission`）へ直接アクセス | 403/権限エラー相当で拒否される |
| UAT-ACL-001 | 全編集権限 | user2 | `custom_value_edit_all` の他ユーザー行を編集 | 保存成功する |
| UAT-ACL-002 | 全閲覧権限 | user2 | `custom_value_view_all` の他ユーザー行を開く | 閲覧可能、編集操作は不可 |
| UAT-ACL-003 | 全アクセス権限 | user2 | `custom_value_access_all` の一覧・詳細を確認 | アクセス可能（定義どおりの操作可否） |
| UAT-ACL-004 | 担当者編集権限 | user2 | `custom_value_edit` の user2作成行とuser3作成行を編集比較 | 自分の行のみ編集可能 |
| UAT-ACL-005 | 担当者閲覧権限 | user2 | `custom_value_view` の user2/user3行を確認 | 自分の行のみ閲覧可能 |
| UAT-ACL-006 | アクセス拒否 | user2 | `no_permission` を開く | 一覧・詳細ともアクセス不可 |
| UAT-DATA-001 | 一覧表示 | admin | `data/custom_value_edit_all` を開く | 一覧が正常表示される |
| UAT-DATA-002 | ページング | admin | 一覧のページ移動（1→2→1） | 行欠落なくページ切替できる |
| UAT-DATA-003 | ソート | admin | `index_text` など索引列で昇順/降順切替 | 並び順が反映される |
| UAT-DATA-004 | フリーワード検索 | admin | `index_text_2_3` などで検索 | 該当行のみ表示される |
| UAT-DATA-005 | ビュー切替(AND) | admin | `view and` を選択して結果確認 | 条件一致データのみ表示 |
| UAT-DATA-006 | ビュー切替(OR) | admin | `view or` を選択して結果確認 | OR条件結果が表示 |
| UAT-DATA-007 | 新規作成(正常) | admin | 必須列を入力して新規保存 | 保存成功し詳細へ遷移 |
| UAT-DATA-008 | 新規作成(必須エラー) | admin | 必須列未入力で保存 | 必須エラーが表示され保存されない |
| UAT-DATA-009 | 編集 | admin | 既存データを編集して保存 | 更新内容が反映される |
| UAT-DATA-010 | 削除 | admin | 既存データを削除 | 一覧から非表示になる |
| UAT-DATA-011 | 復元 | admin | 削除済みデータを `restore` 実行 | データが復元される |
| UAT-DATA-012 | 行コピー | admin | `copyModal` からコピー作成 | ほぼ同一内容で新規行が作成される |
| UAT-DATA-013 | 変更履歴比較 | admin | `compare` 画面で版差分を確認 | 差分が表示される |
| UAT-DATA-014 | 履歴復元 | admin | 比較画面から旧版へ復元 | 指定版の内容へ戻る |
| UAT-DATA-015 | コメント追加 | admin | 詳細画面でコメント追加 | コメント履歴に追加される |
| UAT-DATA-016 | コメント削除 | admin | 追加コメントを削除 | コメントが削除される |
| UAT-DATA-017 | 添付ファイルアップロード | admin | 編集画面でファイル列にアップロードして保存 | 添付が保存される |
| UAT-DATA-018 | 添付ファイルDL/削除 | admin | 詳細から添付DL、次に削除 | DL可能、削除後は参照不可 |
| UAT-DATA-019 | インポート | admin | `importModal` からCSV取込 | 取込件数どおりにデータ追加/更新される |
| UAT-DATA-020 | 一括操作 | admin | `operationModal` から対象行に一括処理 | 指定した処理結果が反映される |
| UAT-REL-001 | 1:n 関連登録 | admin | `parent_table` と `child_table` 関連データを作成 | 親子関係が保存・表示される |
| UAT-REL-002 | n:n 関連登録 | admin | `parent_table_n_n` と `child_table_n_n` を `pivot_table_n_n` で紐付け | 多対多の関連が表示される |
| UAT-REL-003 | select_table 単一選択 | admin | `pivot_table_select` で参照先を選択保存 | 選択値が保存される |
| UAT-REL-004 | select_table 複数選択 | admin | `parent_multi` に複数値選択して保存 | 複数選択値が保持される |
| UAT-REL-005 | ターゲットビュー制限 | admin | `child_view` 系列で候補選択 | 指定ビュー条件に合う候補のみ表示 |
| UAT-REL-006 | Ajax候補取得 | admin | `child_ajax` 系列を操作 | 入力に応じて候補が非同期表示される |
| UAT-REL-007 | 関連フィルタ連動 | admin | `parent` 選択後に `child_relation_filter` 候補確認 | 親選択値に応じて候補が絞込まれる |
| UAT-REL-008 | 関連フィルタ連動(Ajax) | admin | `child_relation_filter_ajax` を操作 | Ajaxで絞込候補が表示される |
| UAT-REL-009 | 編集時の関連整合性 | admin | 既存関連データを編集保存 | 不整合なく保存される |
| UAT-REL-010 | 関連検索 | admin | `/admin/search/relation` を利用 | 関連条件で対象データを検索できる |
| UAT-WF-001 | ワークフロー起票 | user2 | `custom_value_edit` で対象データを起票アクション | ステータスが開始状態になる |
| UAT-WF-002 | ワークフロー遷移 | user2 | 実行可能アクションを選択実行 | ステータスが次状態へ遷移 |
| UAT-WF-003 | 担当者制御 | user3 | user2担当中データへアクション実行を試行 | 実行不可（権限制御される） |
| UAT-WF-004 | ワークフロー履歴 | user2 | `workflowHistoryModal` を開く | 実行履歴（日時/実行者/遷移）が表示 |
| UAT-WF-005 | ワークフロー状態ビュー | user2 | `view workflow_status_start/middle` を切替 | 状態ごとの絞込が正しく反映 |
| UAT-WF-006 | 自分担当ビュー | user2 | `view workflow_work_user` を確認 | 自分が担当中のデータのみ表示 |
| UAT-WF-007 | 完了後の制御 | user2 | 完了状態データで追加遷移を試行 | 定義外アクションは実行不可 |
| UAT-WF-008 | ワークフロー通知 | user2 | 遷移実行後の通知を確認 | 対象ユーザーへ通知が作成される |
| UAT-DSH-001 | ダッシュボード作成 | admin | `dashboard/create` で新規作成 | ダッシュボードが保存される |
| UAT-DSH-002 | ボックス追加 | admin | `dashboardbox/create` で対象テーブル/ビュー選択 | ボックスが表示される |
| UAT-DSH-003 | チャート軸設定 | admin | チャート用軸/集計条件を設定 | グラフが期待どおり描画される |
| UAT-DSH-004 | ボックス編集 | admin | 既存ボックスを編集して保存 | 変更内容が反映される |
| UAT-DSH-005 | ボックス削除 | admin | ボックス削除実行 | ダッシュボードから消える |
| UAT-DSH-006 | ダッシュボード共有 | admin | `sendShares` で共有し `shareClick` を確認 | 共有先が閲覧できる |
| UAT-DSH-007 | 共有権限境界 | user2 | 共有されていないダッシュボードURLへアクセス | 閲覧不可になる |
| UAT-SRCH-001 | ヘッダ検索候補 | admin | ヘッダ検索入力で候補表示を確認 | 候補が即時表示される |
| UAT-SRCH-002 | 全体検索結果 | admin | `/admin/search/lists` でキーワード検索 | 対象テーブルごとに結果表示 |
| UAT-SRCH-003 | 検索結果遷移 | admin | 検索結果から詳細へ遷移 | 対象データ詳細へ遷移できる |
| UAT-NOTI-001 | 通知作成 | admin | 通知設定画面で通知ルール作成 | 通知ルールが保存される |
| UAT-NOTI-002 | 通知発火 | admin | 通知対象イベント（作成/更新等）を実行 | 通知が生成される |
| UAT-NOTI-003 | 通知閲覧 | user2 | ナビゲーション通知一覧を開く | 未読通知が表示される |
| UAT-SHARE-001 | レコード共有送信 | admin | データ詳細で `sendShares` 実行 | 指定ユーザーに共有される |
| UAT-SHARE-002 | 共有リンク閲覧 | user2 | `shareClick` から共有データを開く | 対象データを閲覧できる |
| UAT-CFG-001 | カスタムテーブル作成 | admin | `table/create` でテーブル作成 | テーブルが一覧に追加される |
| UAT-CFG-002 | カスタムテーブル複製 | admin | `table/{id}/copyModal` から複製 | 定義がコピーされ新規作成される |
| UAT-CFG-003 | カスタム列作成 | admin | `column/{tableKey}/create` で列追加 | 入力画面/一覧に列が反映される |
| UAT-CFG-004 | 計算列設定 | admin | `calcModal` で計算式設定 | 計算結果が表示される |
| UAT-CFG-005 | カスタムビュー作成 | admin | `view/{tableKey}/create` で条件ビュー作成 | ビュー選択肢に追加される |
| UAT-CFG-006 | 集計ビュー作成 | admin | summary/group条件を設定 | 集計結果が表示される |
| UAT-CFG-007 | カスタムフォーム作成 | admin | `form/{tableKey}/create` でフォーム作成 | フォームが保存される |
| UAT-CFG-008 | フォームプレビュー | admin | `form/{tableKey}/preview` を開く | 設計どおりのフォームが表示される |
| UAT-CFG-009 | フォーム適用確認 | admin | 対象テーブルの作成画面を開く | 新フォーム構成が反映される |
| UAT-CFG-010 | テーブルメニュー設定 | admin | `table/menuModal/{id}` を設定 | メニュー表示が更新される |
| UAT-ADM-001 | ユーザー作成 | admin | `loginuser/create` でユーザー追加 | 新規ユーザーでログイン可能 |
| UAT-ADM-002 | ユーザー編集 | admin | 既存ユーザー情報を編集 | 編集結果が反映される |
| UAT-ADM-003 | 組織作成 | admin | `organization/create` で親子組織を作成 | 階層構造で表示される |
| UAT-ADM-004 | ロールグループ作成 | admin | `role_group/create` で権限設定 | ロールグループが保存される |
| UAT-ADM-005 | ロール割当反映 | admin | user2へロール変更後に再ログイン | 画面アクセス可否が変化する |
| UAT-ADM-006 | ロール取込 | admin | `role_group/importModal` で取込 | 取込結果どおり更新される |
| UAT-ADM-007 | メニュー編集 | admin | `auth/menu` で表示順/項目編集 | 左メニューに反映される |
| UAT-ADM-008 | 操作ログ確認 | admin | `auth/logs` で直近操作を確認 | 実行操作がログとして記録される |

### 条件付きテストケース（機能有効時に実施）
| ケースID | 機能 | 実施ユーザー | 手順（要約） | 期待結果 |
| --- | --- | --- | --- | --- |
| UAT-OPT-2FA-001 | 2要素認証登録 | admin | `auth-2factor/google/register` で登録 | 2FAシークレットが登録される |
| UAT-OPT-2FA-002 | 2要素認証ログイン | admin | 2FA有効ユーザーでログインし認証コード入力 | コード一致時のみログイン成功 |
| UAT-OPT-MAIL-001 | パスワード再設定メール | admin | `auth/forget` から再設定要求 | メール送信され再設定リンク発行 |
| UAT-OPT-MAIL-002 | システムテストメール | admin | `system/send_testmail` 実行 | テストメール送信成功 |
| UAT-OPT-API-001 | APIクライアント作成 | admin | `api_setting/create` でクライアント作成 | クライアント情報が保存される |
| UAT-OPT-API-002 | OAuth認可画面 | admin | `/oauth/authorize` で認可フロー実行 | 同意後トークン払い出し可能 |
| UAT-OPT-BKP-001 | バックアップ作成 | admin | `backup/save` を実行 | バックアップファイルが作成される |
| UAT-OPT-BKP-002 | バックアップDL | admin | `backup/download/{ymdhms}` 実行 | ファイルをダウンロードできる |
| UAT-OPT-BKP-003 | バックアップ復元 | admin | `backup/restore` 実行（検証環境のみ） | 復元完了しデータが反映される |
| UAT-OPT-TPL-001 | テンプレートエクスポート | admin | `template/export` 実行 | テンプレートが出力される |
| UAT-OPT-TPL-002 | テンプレートインポート | admin | `template/import` 実行 | テンプレートが取り込まれる |
| UAT-OPT-PFORM-001 | 公開フォーム表示 | 一般利用者 | 公開フォームURLを開く | フォームが表示される |
| UAT-OPT-PFORM-002 | 公開フォーム送信 | 一般利用者 | 入力→確認→送信 | データ登録と完了画面表示 |
| UAT-OPT-PFORM-003 | 公開フォーム添付 | 一般利用者 | ファイル添付して送信 | 添付ファイルが保存される |
| UAT-OPT-PLUGIN-001 | プラグイン有効化 | admin | プラグイン管理で有効化 | 対応画面/メニューが追加される |
| UAT-OPT-PLUGIN-002 | プラグイン画面表示 | admin | 追加されたプラグインページを開く | エラーなく表示される |
| UAT-OPT-PLUGIN-003 | プラグインコード編集 | admin | `plugin/edit_code` で保存 | 保存内容が反映される |
| UAT-OPT-QR-001 | QR有効化 | admin | テーブル設定でQR有効化 | QR関連操作が利用可能になる |
| UAT-OPT-QR-002 | QR生成/DL | admin | `create_qrcode` / `qrcode_download` 実行 | QR画像を生成・DLできる |
| UAT-OPT-QR-003 | QRスキャン遷移 | 一般利用者 | `qr-code/{table}/{id}` を開く | 対象データ画面へリダイレクト |
| UAT-OPT-JAN-001 | JAN有効化 | admin | テーブル設定でJAN有効化 | JAN関連メニューが有効になる |
| UAT-OPT-JAN-002 | JAN採番 | admin | `assign-jan-code` 実行 | JANコードが重複なく採番される |

### 実施順（推奨）
1. `UAT-AUTH-*` と `UAT-NAVI-*` を先に実施し、ログイン・導線・権限の前提を確定する
2. `UAT-ACL-*` から `UAT-REL-*` までを実施し、データ操作の基盤品質を確認する
3. `UAT-WF-*` と `UAT-DSH-*` を実施し、業務フロー・分析画面の品質を確認する
4. `UAT-CFG-*` と `UAT-ADM-*` を実施し、管理機能の回帰を確認する
5. 最後に `UAT-OPT-*` を環境に応じて選択実施する
