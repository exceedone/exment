# テスト
テスト実行方法です。  

## ブラウザ・単体・結合テスト

### インストール(初回のみ) 
- 以下のコマンドを実行してください。  

```
composer require symfony/css-selector=~6.3
composer require laravel/browser-kit-testing=~7.0
composer require dms/phpunit-arraysubset-asserts=~0.5.0
```

### PHPUnitバージョン変更(初回のみ)
- Exmentでは、PHPUnitにバージョン10.Xを採用しています。  
ルートフォルダのcomposer.jsonの、require-devに、"phpunit/phpunit"に関する記述があれば、以下のように修正してください。

```
"phpunit/phpunit": "~10.1",
```

その後、以下のコマンドを実行してください。

```
composer update
```

- プロジェクトのルートに、phpunit.xmlが含まれていた場合、ファイルを開きます。  
その後、以下の記述が含まれていた場合、コメントアウトします。

``` xml
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="./vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <php>
        <!-- <server name="DB_CONNECTION" value="sqlite"/> --> <!-- コメントアウト -->
        <!-- <server name="DB_DATABASE" value=":memory:"/> --> <!-- コメントアウト -->
    </php>
</phpunit>
```

- テストではAPIを施しますが、場合により、429エラー(Too Many Requests)が発生するようです。  
app\Http\Kernel.phpを開き、以下の記述を修正してください。

``` php
     protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            'throttle:60000,1', // 値を大きな数に変更
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];
```



## テストデータ作成

- 以下のコマンドを実行してください。  
<span style="color:red;">注意：以下のコマンドを実行すると、全てのデータがリセットされます。</span>

```
php artisan exment:inittest
```

### テスト実行

- 以下のコマンドを実行してください。  

```
.\vendor\bin\phpunit .\vendor\exceedone\exment\tests\Browser
.\vendor\bin\phpunit .\vendor\exceedone\exment\tests\Unit
.\vendor\bin\phpunit .\vendor\exceedone\exment\tests\Feature
```

## Lint
Lint(PHPStan / Laratisan)を実行し、構文チェックなどを行います。  
※一部の構文で、実行結果に影響のない不備が残っています。随時修正中です。

### インストール(初回のみ) 
- 以下のコマンドを実行してください。  

```
# Lintのライブラリ
composer require --dev nunomaduro/larastan=~2.6

# Exmentの関連ライブラリ
composer require pragmarx/google2fa
composer require simplesoftwareio/simple-qrcode=^2.0.0
composer require laravel/socialite=~5.1
composer require aacotroneo/laravel-saml2
composer require league/flysystem-ftp ~3.0
composer require phpseclib/phpseclib ^2.0
composer require league/flysystem-sftp ~3.0
composer require league/flysystem-aws-s3-v3 ~3.0
composer require league/flysystem-azure-blob-storage ~3.0
composer require spatie/flysystem-dropbox=^3.0
```

- 以下のファイルを、プロジェクトのルートフォルダにコピーします。

```
vendor/exceedone/exment/phpstan.neon.dist
```

### 設定・実行

- 以下のコマンドを実行してください。  

```
./vendor/bin/phpstan analyse
```

## 言語ファイル作成漏れチェック
以下のコマンドを実施し、言語ファイルの翻訳ファイル設定漏れが無いことを確認する。  
※実行結果、何も表示されなければ正常終了です。翻訳漏れがある場合、その翻訳対象が一覧表示されます。

```
php artisan exment:checklang
```


## テストデータ

### ユーザー
| id | user_code | test password |
| ---- | ---- | ---- |
| 1 | admin | adminadmin |
| 2 | user1 | user1user1 |
| 3 | user2 | user2user2 |
| 4 | user3 | user3user3 |
| 5 | company1-userA | company1-userA |
| 6 | dev0-userB | dev0-userB |
| 7 | dev1-userC | dev1-userC |
| 8 | dev1-userD | dev1-userD |
| 9 | dev2-userE | dev2-userE |
| 10 | company2-userF | company2-userF |

### 組織
| id | organization_code | parent_organization_code | users |
| ---- | ---- | ---- | ---- |
| 1 | company1 | - | company1-userA |
| 2 | dev | company1 | dev0-userB |
| 3 | manage | company1 | - |
| 4 | dev1 | dev | dev1-userC,dev1-userD |
| 5 | dev2 | dev | dev2-userE |
| 6 | company2 | - | company2-userF |
| 7 | company2-a | company2 | - |



### 役割グループ
| id | role_group_name | organizations | users | permissions |
| ---- | ---- | ---- | ---- | ---- |
| 1 | data_admin_group | - | user1 | Can access all data |
| 2 | user_organization_admin_group | - | - | Can manage user and organization |
| 3 | information_manage_group | - | - | Can manage information |
| 4 | user_group | dev | user2,user3 | Please look bottom |


### カスタムテーブル
| id | table_name | description | column_pattern |
| ---- | ---- | ---- | ---- |
| 9 | custom_value_edit_all | "user_group"に所属するユーザーはすべてのデータを編集できます。 | 1 |
| 10 | custom_value_view_all | "user_group"に所属するユーザーはすべてのデータを閲覧できます。 | 1 |
| 11 | custom_value_access_all | "user_group"に所属するユーザーはすべてのデータにアクセスできます。 | 1 |
| 12 | custom_value_edit | "user_group"に所属するユーザーは担当者のデータを編集できます。かつ、ワークフローを持ちます。 | 1 |
| 13 | custom_value_view | "user_group"に所属するユーザーは担当者のデータを閲覧できます。 | 1 |
| 14 | no_permission | "user_group"に所属するユーザーはアクセス権を持ちません。 | 1 |
| 15 | parent_table | 1:nリレーションの親テーブルです。 | 1 |
| 16 | child_table | 1:nリレーションの子テーブルです。 | 1 |
| 17 | pivot_table | parent_table, child_tableの両方をカスタム列に持つテーブルです。 | 2|
| 18 | parent_table_n_n | n:nリレーションの親テーブルです。 | 1 |
| 19 | child_table_n_n | n:nリレーションの子テーブルです。 | 1 |
| 20 | pivot_table_n_n | parent_table_n_n, child_table_n_nの両方をカスタム列に持つテーブルです。 | 2|
| 21 | parent_table_select | カスタム列"select_table"により関連をもつテーブルの、参照先のテーブルです。 | 1 |
| 22 | child_table_select | カスタム列"select_table"により関連をもつテーブルの、参照元のテーブルです。 | 1 |
| 23 | pivot_table_select | parent_table_select, child_table_selectの両方をカスタム列に持つテーブルです。 | 2 |
| 24 | all_columns_table | すべての列種類をもつテーブルです。 | 3 |


### カスタム列

#### 列種類1
- Each tables have above columns;

| column_name | column_type | options | description |
| ---- | ---- | ---- | ---- |
| text | text | required | 'test_' + created_user_id |
| user | user | index_enabled | created_user_id |
| index_text | text | index_enabled | 'index_text_' + created_user_id + '_' + loop_no(1-10) |
| odd_even | text | index_enabled | If loop_no(1-10) is odd then 'odd' else 'even' |
| multiples_of_3 | yesno | index_enabled | If loop_no(1-10)'s multiple is 3 then 1 else 0 |
| file | file |  | file column. |
| date | date |  | date column. |
| init_text | text | init_only | text and init_only column. |


#### 列種類2
- Each tables have above columns;

| column_name | column_type | options | description |
| ---- | ---- | ---- | ---- |
| child | select_table | index_enabled, freeword_search, select_target_table to child table | - |
| parent | select_table | index_enabled, freeword_search, select_target_table to parent table | - |
| child_view | select_table | index_enabled, freeword_search, select_target_table to child table, target_view to "-view-odd" view | - |
| child_ajax | select_table | index_enabled, freeword_search, select_target_table to child table, filter ajax | - |
| child_ajax_view | select_table | index_enabled, freeword_search, select_target_table to child table, target_view to "-view-odd" view, filter ajax | - |
| child_relation_filter | select_table | index_enabled, freeword_search, select_target_table to child table, filter if selected "parent" | - |
| child_view | select_table | index_enabled, freeword_search, select_target_table to child table, target_view to "-view-odd" view, filter if selected "parent" | - |
| child_ajax | select_table | index_enabled, freeword_search, select_target_table to child table, filter ajax, filter if selected "parent" | - |
| child_ajax_view | select_table | index_enabled, freeword_search, select_target_table to child table, target_view to "-view-odd" view, filter ajax, filter if selected "parent" | - |
| parent_multi | select_table | index_enabled, freeword_search, multiple_enabled, select_target_table to parent table | - |
| child_relation_filter | select_table | index_enabled, freeword_search, multiple_enabled, select_target_table to child table, filter if selected "parent_multi" | - |
| child_relation_filter_ajax | select_table | index_enabled, freeword_search, multiple_enabled, select_target_table to child table, filter ajax, filter if selected "parent_multi" | - |


#### 列種類3
- Each tables have above columns;

| column_name | column_type | options | description |
| ---- | ---- | ---- | ---- |
| text | text | index_enabled, freeword_search | - |
| text_area | text_area | - | - |
| editor | editor | - | - |
| url | url | index_enabled, freeword_search | - |
| email | email | index_enabled, freeword_search | - |
| integer | integer | index_enabled | - |
| decimal | decimal | index_enabled | - |
| currency | currency | index_enabled, symbol:"&yen;" | - |
| date | date | index_enabled | - |
| time | time | index_enabled | - |
| datetime | datetime | index_enabled | - |
| select | select | index_enabled, select_item:foo,bar,baz | - |
| select_valtext | select_valtext | index_enabled, select_item:foo_FOO,bar_BAR,baz_BAZ | - |
| select_table | select_table | index_enabled, select_target_table:custom_table_view_all | - |
| yes_no | yes_no | index_enabled | - |
| boolean | boolean | index_enabled, true_value:ok, true_label:OK, false_value:ng, false_label:NG | - |
| image | image | - | - |
| file | image | - | - |
| user | user | - | - |
| organization | organization | - | - |



### カスタムビュー
- Each tables have above view;

| view_name | view_type | filter_description |
| ---- | ---- | ---- |
| table_name + ' view all' | all | - |
| table_name + ' view and' | default | odd_even != odd and multiples_of_3 == 1 and user == 2 |
| table_name + ' view or' | default | odd_even != odd or multiples_of_3 == 1 or user == 2 |
| table_name + ' view workflow_status_start' | default | Workflow status is "start" |
| table_name + ' view workflow_status_middle' | default | Workflow status is "middle" |
| table_name + ' view workflow_work_user' | default | Workflow is self |


### カスタムデータ
- Create custom data for each table.  
- Each of the above users creates 10 data.

Ex.

| value.text | value.user | value.odd_even | value.index_text | value.multiples_of_3 | created_user_id |
| ---- | ---- | ---- | ---- | ---- | ---- |
| test_1 | 1 | odd | index_1_1 | 0 | 1 |
| test_1 | 1 | even | index_1_2 | 0 | 1 |
| test_1 | 1 | odd | index_1_3 | 1 | 1 |
| test_2 | 2 | odd | index_2_1 | 0 | 2 |
| test_2 | 2 | even | index_2_2 | 0 | 2 |
| test_2 | 2 | odd | index_2_3 | 1 | 2 |


### ワークフロー

| id | workflow_name | workflow_type | setting_completed_flg | target_table |
| ---- | ---- | ---- | ---- | ---- |
| 1 | workflow_common_company | common | 1 | custom_value_edit_all |
| 2 | workflow_common_no_complete | common | - | - |
| 3 | workflow_for_individual_table | table | 1 | custom_value_edit |
