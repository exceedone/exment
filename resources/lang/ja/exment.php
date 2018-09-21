<?php

return [    
    'common' => [
        'home' => 'HOME',
        'error' => 'エラー',
        'import' => 'インポート',
        'reqired' => '必須',
        'input' => '入力',
        'available_true' => '有効',
        'available_false' => '無効',
        'help_code' => '保存後、変更はできません。半角英数字、"-"または"_"で記入してください。',
        'created_at' => '作成日時',
        'updated_at' => '更新日時',
        'separate_word' => '、',
        'yes' => 'はい',
        'no' => 'いいえ',
        'message' => [
            'import_success' => 'インポート完了しました！',
            'import_error' => 'インポート失敗しました。CSVファイルをご確認ください。',
            'notfound' => 'データが存在しません。',
            'wrongdata' => 'データが不正です。URLをご確認ください。',
        ],

        'help' =>[
            'input_available_characters' => '%sで記入してください。',
        ],
    ],

    'system' => [
        'system_header' => 'システム設定',
        'system_description' => 'システム設定を変更します。',
        'header' => 'サイト基本情報',
        'administrator' => '管理者情報',
        'initialize_header' => 'Exmentインストール',
        'initialize_description' => 'Exmentの初期設定を画面から登録し、インストールします。',
        'site_name' => 'サイト名',
        'site_name_short' => 'サイト名(略)',
        'site_logo' => 'サイトロゴ',
        'site_logo_mini' => 'サイトロゴ(小)',
        'site_skin' => 'サイトスキン',
        'site_layout' => 'サイトメニューレイアウト',
        'authority_available' => '権限管理を使用する',
        'organization_available' => '組織管理を使用する',
        'system_mail_from' => 'システムメール送信元',
        'template' => 'インストールテンプレート',
        
        'site_skin_options' => [
            "skin-blue" => "ヘッダー：青&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：黒",
            "skin-blue-light" => "ヘッダー：青&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：白",
            "skin-yellow" => "ヘッダー：黃&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：黒",
            "skin-yellow-light" => "ヘッダー：黃&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：白",
            "skin-green" => "ヘッダー：緑&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：黒",
            "skin-green-light" => "ヘッダー：緑&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：白",
            "skin-purple" => "ヘッダー：紫&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：黒",
            "skin-purple-light" => "ヘッダー：紫&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：白",
            "skin-red" => "ヘッダー：赤&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：黒",
            "skin-red-light" => "ヘッダー：赤&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：白",
            "skin-black" => "ヘッダー：白&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：黒",
            "skin-black-light" => "ヘッダー：白&nbsp;&nbsp;&nbsp;&nbsp;サイドバー：白",
        ],
        
        'site_layout_options' => [
            "layout_default" => "標準",
            "layout_mini" => "小アイコン",
        ],
        
        'help' =>[
            'site_name' => 'ページの左上に表示するサイト名です。',
            'site_name_short' => 'メニューを折りたたんだ時に表示する、サイト名の短縮語です。',
            'site_logo' => 'サイトのロゴです。推奨サイズ：200px * 40px',
            'site_logo_mini' => 'サイトのロゴ(小アイコン)です。推奨サイズ：40px * 40px',
            'site_skin' => 'サイトのテーマ色を選択します。※保存後、再読込で反映されます。',
            'site_layout' => 'ページ左の、サイトメニューのレイアウトを選択します。※保存後、再読込で反映されます。',
            'authority_available' => 'YESの場合、ユーザーや役割によって、アクセスできる項目を管理します。',
            'organization_available' => 'YESの場合、ユーザーが所属する組織や部署を作成します。',
            'system_mail_from' => 'システムからメールを送付する際の送信元です。このメールアドレスをFromとして、メールが送付されます。',
            'template' => 'テンプレートを選択することで、テーブルや列、フォームが自動的にインストールされます。',
        ]
    ],

    'dashboard' => [
        'header' => 'ダッシュボード',
        'dashboard_name' => 'ダッシュボード名',
        'dashboard_view_name' => 'ダッシュボード表示名',
        'row1' => 'ダッシュボード1行目',
        'row2' => 'ダッシュボード2行目',
        'description_row1' => 'ダッシュボードの1行目に表示する列数です。',
        'description_row2' => 'ダッシュボードの2行目に表示する列数です。※「なし」を選択すると、2行目は表示されません。',
        'default_dashboard_name' => '既定のダッシュボード',
        'not_registered' => '未登録',
        'dashboard_type_options' => [
            'system' => 'システムダッシュボード',
            'user' => 'ユーザーダッシュボード',
        ],
        'row_options0' => 'なし',
        'row_optionsX' => '列',

        'row_no' => '行番号',
        'column_no' => '列番号',
        'dashboard_box_type' => 'アイテム種類',
        'dashboard_box_view_name' => 'アイテム表示名',
        'dashboard_box_type_options' => [
            'list' => 'データ一覧',
            'system' => 'システム',
        ],
        
        'dashboard_box_options' => [
            'target_table_id' => '対象のテーブル',
            'target_view_id' => '対象のビュー',
            'target_system_id' => '表示アイテム',
        ],

        'dashboard_box_system_pages' => [
            'guideline' => 'ガイドライン',
        ],

        'dashboard_menulist' => [
            'current_dashboard_edit' => '現在のダッシュボード設定変更',
            'create' => 'ダッシュボード新規作成',
        ],
    ],

    'plugin' => [
        'header' => 'プラグイン管理',
        'description' => 'インストールされているプラグインの管理や、新規にプラグインをアップロードすることができます。',
        'upload_header' => 'プラグインアップロード',
        'extension' => 'ファイル形式：zip',
        'uuid' => 'プラグインID',
        'plugin_name' => 'プラグイン名',
        'plugin_view_name' => '表示名',
        'plugin_type' => '種類',
        'author' => '作者',
        'version' => 'バージョン',
        'active_flg' => '有効フラグ',
        'select_plugin_file' => 'プラグインを選択',
        'options' => [
            'header' => 'オプション設定',
            'target_tables' => '対象テーブル',
            'event_triggers' => '実施トリガー',
            'label' => 'ボタンの見出し',
            'button_class' => 'ボタンのHTML class',
            'icon' => 'ボタンのアイコン',
            'uri' => 'URL',

            'event_trigger_options' => [
                'saving' => '保存直前',
                'saved' => '保存後',
                'loading' => '画面読み込み前',
                'loaded' => '画面読み込み後',
                'grid_menubutton' => '一覧画面のメニューボタン',
                'form_menubutton_create' => 'フォームのメニューボタン（新規作成時）',
                'form_menubutton_edit' => 'フォームのメニューボタン（更新時）',
            ]
        ],

        'help' => [
            'target_tables' => 'プラグインを実行する対象のテーブルです。',
            'event_triggers' => 'どの動作を行ったときに、プラグインを実行するかどうかを設定します。',
            'icon' => 'ボタンのHTMLに付加するアイコンです。',
            'button_class' => 'ボタンのHTMLに付加するclassです。',
            'errorMess' => 'プラグインファイルを選択してください',
        ],

        'plugin_type_options' => [
            'page' => '画面',
            'trigger' => '機能',
        ],
    ],

    'user' => [
        'header' => 'ログインユーザー設定',
        'description' => 'ユーザーの中から、このシステムにログインを行うユーザーを選択し、パスワード設定や、パスワード初期化などを行うこともできます。',
        'user_code' => 'ユーザーコード',
        'user_name' => 'ユーザー名',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'password_confirmation' => 'パスワード(再入力)',
        'new_password' => '新しいパスワード',
        'new_password_confirmation' => '新しいパスワード(再入力)',
        'login_user' => 'ログインユーザー設定',
        'login' => 'ログイン設定',
        'use_loginuser' => 'ログイン権限付与',
        'reset_password' => 'パスワードをリセットする',
        'create_password_auto' => 'パスワードを自動生成する',
        'avatar' => 'アバター',
        'default_table_name' => 'ユーザー',
        'help' =>[
            'user_name' => '画面に表示する名前です。',
            'email' => 'システム通知を受信できるメールアドレスを入力してください。',
            'password' => '英数記号で8文字以上記入してください。',
            'change_only' => '変更を行う場合のみ入力します。',
            
            'use_loginuser' => 'チェックすることで、このユーザーがシステムにログインすることができるようになります。',
            'reset_password' => 'チェックして保存することで、パスワードが再設定されます。',
            'create_password_auto' => 'チェックして保存することで、パスワードが自動生成されます。',
        ]
    ],

    'organization' => [
        'default_table_name' => '組織',
    ],

    'login' => [
        'email_or_usercode' => 'メールアドレスorユーザーコード',
        'forget_password' => 'パスワードを忘れた',
        'password_reset' => 'パスワードリセット',
        'back_login_page' => 'ログインページに戻る',
    ],

    'change_page_menu' =>[
        'change_page_label' => 'ページ移動',
        'custom_table' => 'テーブル設定',
        'custom_column' => '列の詳細設定',
        'custom_view' => 'ビュー設定',
        'custom_form' => 'フォーム設定',
        'custom_relation' => '関連テーブル設定',
        'custom_value' => 'データ一覧',
        'error_select' => '行を1行のみ選択してください',
    ],

    'custom_table' => [
        'header' => 'カスタムテーブル設定',
        'description' => '独自に変更できるカスタムテーブルの設定を行います。',
        'table_name' => 'テーブル名',
        'table_view_name' => 'テーブル表示名',
        'field_description' => '説明',
        'color' => '色',
        'icon' => 'アイコン',
        'search_enabled' => '検索可能',
        'one_record_flg' => '1件のみ登録可能',
        'custom_columns' => '列一覧',
        'help' => [
            'color' => '検索などで使用する、テーブルの色を設定します。',
            'icon' => 'メニューなどに表示するアイコンを選択してください。',
            'search_enabled' => 'YESにした場合、検索画面から検索可能になります。',
            'one_record_flg' =>'データを1件のみ登録可能かどうかの設定です。自社情報など、データが1件しか存在しないテーブルの場合、YESにしてください。',
        ],
        
        'system_definitions' => [
            'user' => 'ユーザー',
            'organization' => '組織',
            'document' => 'ドキュメント',
            'base_info' => '基本情報',
        ],
    ],
    
    'custom_column' => [
        'header' => 'カスタム列詳細設定',
        'description' => 'カスタム列ごとの詳細設定を行います。列の必須項目、検索可能フィールドなどを定義します。',
        'column_name' => '列名',
        'column_view_name' => '列表示名',
        'column_type' => '列種類',
        'options' => [
            'header' => '詳細オプション',
            'search_enabled' => '検索インデックス',
            'placeholder' => 'プレースホルダー',
            'help' => 'ヘルプ',
            'string_length' => '最大文字数',
            'available_characters' => '使用可能文字',
            'number_min' => '最小値',
            'number_max' => '最大値',
            'number_format' => '数値 カンマ文字列',
            'updown_button' => '+-ボタン表示',
            'select_item' => '選択肢',
            "select_valtext" => "選択肢(値とテキスト)",
            'select_target_table' => '対象テーブル',
            'true_value' => '選択肢1のときの値',
            'true_label' => '選択肢1のときの表示',
            'true_label_default' => 'はい',
            'false_value' => '選択肢2のときの値',
            'false_label' => '選択肢2のときの表示',
            'false_label_default' => 'いいえ',
            'auto_number_length' => '桁数',
            'auto_number_type' => '採番種類',
            'auto_number_type_format' => 'フォーマット',
            'auto_number_type_random25' => 'ランダム(ライセンスコード)',
            'auto_number_type_random32' => 'ランダム(UUID)',
            'auto_number_format' => '採番フォーマット',
            'multiple_enabled' => '複数選択を許可する',
            'use_label_flg' => 'ラベルで使用する',
            'calc_formula' => '計算式',
        ],
        'system_columns' => [
            'id' => 'ID',
            'suuid' => '内部ID(20桁)',
            'created_at' => '作成日時',
            'updated_at' => '更新日時',            
        ],
        'column_type_options' => [
            "text" => "1行テキスト",
            "textarea" => "複数行テキスト",
            "url" => "URL",
            "email" => "メールアドレス",
            "integer" => "整数",
            "decimal" => "小数",
            "calc" => "計算結果",
            "date" => "日付",
            "time" => "時刻",
            "datetime" => "日付と時刻",
            "select" => "選択肢",
            "select_valtext" => "選択肢 (値・見出しを登録)",
            "select_table" => "選択肢 (他のテーブルの値一覧から選択)",
            "yesno" => "YES/NO",
            "boolean" => "2値の選択",
            "auto_number" => "自動採番",
            "image" => "画像",
            "file" => "ファイル",
            "user" => "ユーザー",
            "organization" => "組織",
            'document' => '書類',
        ],
        'help' => [
            'search_enabled' => 'YESにすることで、検索インデックスが追加されます。これにより、検索時やビューで、条件絞り込みが出来ます。<br/>※同一のテーブルで、「検索インデックス」を非常に多く設定すると、パフォーマンスが低下する可能性があります。',
            'help' => 'フィールドの下に表示されるヘルプ文字列です。',
            'use_label_flg' => 'このデータを選択時、画面に表示する文言の列です。複数列登録した場合、1列のみ反映されます。',
            'number_format' => 'YESにすることで、テキストフィールドがカンマ値で表示されます。',
            'updown_button' => 'YESにすることで、フォームに+-ボタンを表示します。',
            'select_item' => '改行区切りで選択肢を入力してください。',
            'select_item_valtext' => '改行区切りで選択肢を入力します。カンマの前が値、後が見出しとなります。<br/>例：<br/>「1,成人<br/>2,未成年」→"1"が選択時にデータとして登録する値、"成人"が選択時の見出し',
            'select_target_table' => '選択対象となるテーブルを選択してください。',
            'true_value' => '1つ目の選択肢を保存した場合に登録する値を入力してください。',
            'true_label' => '1つ目の選択肢を保存した場合に表示する文字列を入力してください。',
            'false_value' => '2つ目の選択肢を保存した場合に登録する値を入力してください。',
            'false_label' => '2つ目の選択肢を保存した場合に表示する文字列を入力してください。',
            'available_characters' => '入力可能な文字を選択してください。すべてのチェックを外すと、すべての文字を入力できます。',
            'auto_number_format' => '登録する採番のルールを設定します。詳細のルールは&nbsp;<a href="%s" target="_blank">こちら<i class="fa fa-external-link"></i></a>&nbsp;をご参照ください。',
            'calc_formula' => '他のフィールドを使用した、計算式を入力します。※現在β版です。',
        ],
        'available_characters' => [
            'lower' => '英小文字', 
            'upper' => '英大文字', 
            'number' => '数字', 
            'hyphen_underscore' => '"-"または"_"',
            'symbol' => '記号',
        ],
        
        'calc_formula' => [
             'calc_formula' => '計算式',
             'dynamic' => '列',
             'fixed' => '固定値',
             'symbol' => '記号',
        ],
        
        'system_definitions' => [
            'file' => 'ファイル',
            'company_name' => '会社名',
            'company_kana' => '会社カナ',
            'zip01' => '郵便番号1',
            'zip02' => '郵便番号2',
            'tel01' => '電話番号1',
            'tel02' => '電話番号2',
            'tel03' => '電話番号3',
            'fax01' => 'FAX1',
            'fax02' => 'FAX2',
            'fax03' => 'FAX3',
            'pref' => '都道府県',
            'addr01' => '住所',
            'addr02' => '住所(ビル以降)',
            'company_logo' => '会社ロゴ',
            'company_stamp' => '会社印',
            'transfer_bank_name' => '代表振込先口座-銀行名',
            'transfer_bank_office_name' => '代表振込先口座-支店名',
            'transfer_bank_office_no' => '代表振込先口座-支店番号',
            'transfer_bank_account_type' => '代表振込先口座-口座種類',
            'transfer_bank_account_no' => '代表振込先口座-口座番号',
            'transfer_bank_account_name' => '代表振込先口座-口座名',
            'user_code' => 'ユーザーコード',
            'user_name' => 'ユーザー名',
            'email' => 'メールアドレス',
            'organization_code' => '組織コード',
            'organization_name' => '組織名',
            'parent_organization' => '親組織',
        ],
    ],

    'custom_form' => [
        'default_form_name' => 'フォーム',
        'header' => 'カスタムフォーム設定',
        'description' => 'ユーザーが入力できるフォーム画面を定義します。権限やユーザーごとに切り替える事ができます。',
        'form_view_name' => 'フォーム表示名',
        'table_default_label' => 'テーブル',
        'table_one_to_many_label' => '子テーブル - ',
        'table_many_to_many_label' => '関連テーブル - ',
        'suggest_column_label' => 'テーブル列',
        'suggest_other_label' => 'その他',
        'form_block_name' => 'フォームブロック名',
        'view_only' => '表示専用',
        'hidden' => '隠しフィールド',
        'text' => 'テキスト',
        'html' => 'HTML',
        'available' => '使用する',
        'header_basic_setting' => 'ヘッダー基本設定',
        'changedata' => 'データ連動設定',
        'items' => '項目',
        'add_all_items' => 'すべて項目に追加',
        'changedata_target_column' => '列を選択',
        'changedata_target_column_when' => 'の項目を選択したとき',
        'changedata_column' => 'リンク列を選択',
        'changedata_column_then' => 'の値をコピーする',

        'form_column_type_other_options' => [
            'header' => '見出し',
            'html' => 'HTML',
            'explain' => '説明文',
        ],
    ],

    'custom_view' => [
        'header' => 'カスタムビュー設定',
        'description' => 'カスタムビューの設定を行います。',
        'view_view_name' => 'ビュー表示名',
        'custom_view_columns' => '表示列選択',
        'view_column_target' => '対象列',
        'order' => '表示順',
        'custom_view_filters' => '表示条件',
        'view_filter_target' => '対象列',
        'view_filter_condition' => '検索条件',
        'view_filter_condition_value_text' => '検索値',
        'default_view_name' => '既定のビュー',
        'description_custom_view_columns' => 'ビューに表示する列を設定します。',
        'description_custom_view_filters' => 'ビューに表示する条件を設定します。<br/>※この設定の他に、ログインユーザーが所有する権限のデータのみ表示するよう、データのフィルターを行います。',

        'filter_condition_options' => [
            'eq' => '合致する', 
            'ne' => '合致しない', 
            'eq-user' => '現在のユーザーに合致する', 
            'ne-user' => '現在のユーザーに合致しない', 
            'on' => '指定日',
            'on-or-after' => '指定日以降',
            'on-or-before' => '指定日以前',
            'today' => '今日',
            'today-or-after' => '今日以降',
            'today-or-before' => '今日以前',
            'yesterday' => '昨日',
            'tomorrow' => '明日',
            'this-month' => '今月',
            'last-month' => '先月',
            'next-month' => '来月',
            'this-year' => '今年',
            'last-year' => '去年',
            'next-year' => '来年',
            'last-x-day-after' => 'X日前の日付以降', 
            'next-x-day-after' => 'X日後の日付以降', 
            'last-x-day-or-before' => 'X日前の日付以前', 
            'next-x-day-or-before' => 'X日後の日付以前', 
            'not-null' => '値が空でない',
            'null' => '値が空',
        ],
        
        'custom_view_menulist' => [
            'current_view_edit' => '現在のビュー設定変更',
            'create' => 'ビュー新規作成',
        ],

        'custom_view_button_label' => 'ビュー',
        'custom_view_type_options' => [
            'system' => 'システムビュー',
            'user' => 'ユーザービュー',
        ],
    ],

    'authority' => [
        'header' => '権限設定',
        'description' => '権限の設定を行います。',
        'authority_name' => '権限名',
        'authority_view_name' => '権限表示名',
        'authority_type' => '権限の種類',
        'default_flg' => '既定の権限',
        'default_flg_true' => '既定',
        'default_flg_false' => '',
        'description_field' => '説明文',
        'permissions' => '権限詳細',

        'description_form' => [
            'system' => 'システム全体を対象に、権限を付与するユーザー・組織を選択してください。',
            'system_disableorg' => 'システム全体を対象に、権限を付与するユーザーを選択してください。',
            'custom_table' => 'このテーブルを対象に、権限を付与するユーザー・組織を選択してください。',
            'custom_table_disableorg' => 'このテーブルを対象に、権限を付与するユーザーを選択してください。',
            'custom_value' => 'このデータを対象に、権限を付与するユーザー・組織を選択してください。',
            'custom_value_disableorg' => 'このデータを対象に、権限を付与するユーザーを選択してください。',
            'plugin' => 'このプラグインを対象に、権限を付与するユーザー・組織を選択してください。',
            'plugin_disableorg' => 'このプラグインを対象に、権限を付与するユーザーを選択してください。',
        ],

        'authority_type_options' => [
            'system' => 'システム',
            'table' => 'テーブル',
            'value' => 'データ',
            'plugin' => 'プラグイン',
        ],

        'authority_type_option_system' => [
            'system' => ['label' => 'システム情報', 'help' => 'システム情報を変更できます。'],
            'custom_table' => ['label' => 'カスタムテーブル', 'help' => 'カスタムテーブルを追加・変更・削除できます。'],
            'custom_form' => ['label' => 'フォーム', 'help' => 'カスタムフォームを追加・変更・削除できます。'],
            'custom_value_edit_all' => ['label' => 'すべてのデータ', 'help' => 'すべてのデータを追加・変更・削除できます。'],
        ],
        'authority_type_option_table' => [
            'custom_table' => ['label' => 'テーブル', 'help' => 'テーブル定義を変更、またはテーブルを削除できます。'],
            'custom_form' => ['label' => 'フォーム', 'help' => 'フォームを追加・変更・削除できます。'],
            'custom_value_edit_all' => ['label' => 'すべてのデータ', 'help' => 'すべてのデータを追加・編集・削除できます。'],
            'custom_value_edit' => ['label' => '担当者データの編集', 'help' => '自分が担当者のデータを追加・編集・削除できます。'],
            'custom_value_view' => ['label' => '担当者データの閲覧', 'help' => '自分が担当者のデータを閲覧できます。'],
        ], 
        'authority_type_option_value' => [
            'custom_value_edit' => ['label' => '編集者', 'help' => '対象のデータを編集できます。'],
            'custom_value_view' => ['label' => '閲覧者', 'help' => '対象のデータを閲覧できます。'],
        ], 
        'authority_type_option_plugin' => [
            'plugin_access' => ['label' => '利用', 'help' => 'このプラグインを利用できます。'],
            'plugin_setting' => ['label' => '設定変更', 'help' => '設定変更をもつプラグインの場合、このプラグインの設定を変更できます。'],
        ],
    ],

    'custom_relation' => [
        'header' => '関連テーブル設定',
        'description' => 'テーブル間同士のリレーションを定義します。',
        'relation_type' => 'リレーション種類',
        'relation_type_options' => [
            'one_to_many'  => '1対多',
            'many_to_many'  => '多対多',
        ],
        'parent_custom_table_name' => '親テーブル名',
        'parent_custom_table_view_name' => '親テーブル表示名',
        'child_custom_table' => '子テーブル',
        'child_custom_table_name' => '子テーブル名',
        'child_custom_table_view_name' => '子テーブル表示名',
    ],

    'search' => [
        'placeholder' => 'データ検索',
        'header_freeword' => '全データ検索',
        'description_freeword' => '全データ検索の結果一覧です。',
        'header_relation' => '関連データ検索',
        'description_relation' => '関連データ検索の結果一覧です。',
        'no_result' => '検索結果がありませんでした',
        'result_label' => '「%s」 の検索結果' ,
        'view_list' => '一覧表示',
    ],

    'menu' => [
        'menu_type' => 'メニュー種類',
        'menu_target' => '対象',
        'menu_name' => 'メニュー名',
        'title' => 'メニュー表示名',
        'menu_type_options' => [
            'system' => 'システムメニュー',
            'plugin' => 'プラグイン',
            'table' => 'テーブルデータ',
            'custom' => 'カスタムURL',
        ],
        
        'system_definitions' => [
            'home' => 'HOME',
            'system' => 'システム設定',
            'plugin' => 'プラグイン',
            'custom_table' => 'カスタムテーブル',
            'authority' => '権限',
            'user' => 'ユーザー',
            'organization' => '組織',
            'menu' => 'メニュー',
            'template' => 'テンプレート',
            'loginuser' => 'ログインユーザー',
            'mail' => 'メールテンプレート',
            'notify' => '通知',
            'base_info' => '基本情報',
            'master' => 'マスター管理',
            'admin' => '管理者設定',
        ],
    ],

    'mail_template' => [
        'header' => 'メールテンプレート設定',
        'description' => 'メール送信時のメッセージの本文などを管理します。',
        'mail_name' => 'メールキー名',
        'mail_view_name' => 'メール表示名',
        'mail_subject' => 'メール件名',
        'mail_body' => 'メール本文',
        'mail_template_type' => 'テンプレート種類',
        'help' =>[
            'mail_name' => 'システム上で、メールテンプレートを一意に判別するためのキー名です。',
            'mail_view_name' => '一覧画面で表示する、テンプレート名称です。',    
            'mail_subject' => '送付するメールの件名を入力します。変数を利用できます。',
            'mail_body' => '送付するメールの本文を入力します。変数を利用できます。',    
        ],
        
        'mail_template_type_options' => [
            'header' => 'ヘッダー',
            'body' => '本文',
            'footer' => 'フッター',
        ],
    ],

    'template' =>[
        'header' => 'テンプレート',
        'header_export' => 'テンプレート - エクスポート',
        'header_import' => 'テンプレート - インポート',
        'description' => 'Exmentのテーブル、列、フォーム情報をインポート、またはエクスポートします。',
        'description_export' => 'システムに登録している、テーブル・列・フォーム情報をエクスポートします。このテンプレートファイルは、他のシステムでインポートすることができます。',
        'description_import' => 'エクスポートされたExmentテンプレート情報を、このシステムにインポートし、テーブル・列・フォーム情報をインストールします。',
        'template_name' => 'テンプレート名',
        'template_view_name' => 'テンプレート表示名',
        'form_description' => 'テンプレート説明文',
        'thumbnail' => 'サムネイル',
        'upload_template' => 'テンプレートアップロード',
        'export_target' => 'エクスポート対象',
        'target_tables' => 'エクスポート対象テーブル',
        
        'help' => [
            'thumbnail' => '推奨サイズ：256px*256px',
            'upload_template' => 'テンプレートファイルをアップロードして、システムにインストールします。',
            'export_target' => 'エクスポートする対象を選択してください。',
            'target_tables' => 'エクスポートするテーブルを選択してください。未選択の場合、すべてのテーブル情報をエクスポートします。',
        ],

        'export_target_options' => [
            'table' => 'テーブル',
            'dashboard' => 'ダッシュボード',
            'menu' => 'メニュー',
            'authority' => '権限',
            'mail_template' => 'メールテンプレート',
        ]
    ],

    'custom_value' => [
        'template' => 'テンプレート出力',
        'import_export' => 'インポート・エクスポート',
        'import' => [
            'import_file' => 'インポートファイル',
            'import_file_select' => 'CSVファイルを選択',
            'primary_key' => '主キー',
            'error_flow' => 'エラー時処理',
            'import_error_message' => 'エラーメッセージ',
            'import_error_format' => '行%d : %s',
            'help' => [
                'custom_table_file' => 'テンプレート出力した、CSVファイルを選択してください。',
                'primary_key' => '更新データを絞り込む対象のフィールドを選択します。<br />このフィールド値が、すでにあるデータと合致していた場合、更新データとして取り込みを行います。<br />合致するデータが存在しなかった場合、新規データとして取り込みます。',
                'error_flow' => 'データ不備などでエラーが発生した場合、正常データを取り込むかどうか選択します。',
                'import_error_message' => '取込ファイルに不備があった場合、この項目に該当する行と、エラーメッセージを表示します。',
            ],
            'key_options' => [
                'id' => 'ID',
                'suuid' => 'SUUID(内部ID)',
            ],
            'error_options' => [
                'stop' => 'すべてのデータを取り込まない。',
                'skip' => '正常データは取り込むが、エラーデータは取り込まない。',
            ],
        ]
    ],

    'notify' => [
        'header' => '通知設定',
        'header_trigger' => '通知条件設定',
        'header_action' => '通知アクション設定',
        'description' => '特定の条件で、通知を行うための設定を行います。',
        'notify_view_name' => '通知表示名',
        'custom_table_id' => '対象テーブル',
        'notify_trigger' => '実施トリガー',
        'trigger_settings' => '通知実施設定',
        'notify_target_column' => '日付対象列',
        'notify_day' => '通知日',
        'notify_beforeafter' => '通知前後',
        'notify_hour' => '通知時間',
        'notify_action' => '実施アクション',
        'action_settings' => '実施アクション設定',
        'notify_action_target' => '対象',
        'mail_template_id' => 'メールテンプレート',

        'help' => [
            'notify_day' => '通知を行う日付を入力してください。「0」と入力することで、当日に通知を行います。',
            'custom_table_id' => '通知を行う条件として使用する、テーブルを選択します。',
            'notify_trigger' => '通知を行う条件となる内容を選択してください。',
            'trigger_settings' => '通知を行うかどうかの判定を行う、日付・日時のフィールドを選択します。',
            'notify_beforeafter' => '通知を行うのが、登録している日付の「前」か「後」かを選択します。<br/>例：「通知日」が7、「通知前後」が「前」の場合、指定したフィールドの日付の7日前に通知実行',
            'notify_hour' => '通知を実行する時間です。0～23で入力します。 例：「6」と入力した場合、6:00に通知実行',
            'notify_action' => '条件に合致した場合に行う、通知アクションを選択してください。',
            'notify_action_target' => '通知先の対象を選択します。',
            'mail_template_id' => '送付するメールのテンプレートを選択します。新規作成する場合、事前にメールテンプレート画面にて、新規テンプレートを作成してください。',
        ],

        'notify_trigger_options' => [
            'time' => '時間の経過'
        ],
        'notify_beforeafter_options' => [
            'before' => '前', 
            'after' => '後'
        ],
        'notify_action_options' => [
            'email' => 'Eメール', 
        ],

        'notify_action_target_options' => [
            'has_authorities' => '権限のあるユーザー',
        ],
    ],
];
