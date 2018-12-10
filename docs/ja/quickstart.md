# クイックスタート
Exmentを開始するために必要となる手順です。  
※composerの導入が必要です。
※MySQLは、json型に対応している5.7以上でのみご利用可能です。

## Laravelインストール(プロジェクト作成)
- コマンドラインで、以下のコマンドを実行します。

~~~
composer create-project "laravel/laravel=5.5.*" (プロジェクト名)
cd (プロジェクト名)
~~~

## データベース作成
- Exment用のデータベースを、MySQLで作成してください。


## .env変更

- ".env" を開き、以下の内容を追加・変更します。  

~~~
# 以下、データベースの設定値変更
DB_CONNECTION=mysql
DB_HOST=127.0.0.1 #MySQLのホスト名
DB_PORT=3306 #MySQLのポート番号
DB_DATABASE=homestead #MySQLのExment用データベース名
DB_USERNAME=homestead #MySQLのExment用データベースのユーザー名
DB_PASSWORD=secret #MySQLのExment用データベースの1パスワード

# 以下、メール送信用の設定値変更
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io #メールサーバー用のホスト名
MAIL_PORT=2525 #メールサーバー用のポート番号
MAIL_USERNAME=null  #メールサーバーのユーザー名
MAIL_PASSWORD=null #メールサーバーのパスワード
MAIL_ENCRYPTION=null #ssl使用の場合"ssl"と記入

# 以下、特定の場合に追加
ADMIN_HTTPS=true #https通信の場合に追加
EXMENT_LOGIN_PROVIDERS=graph,google #SSOログインを実施する場合に追加。プロバイダをカンマ区切りで記入
EXMENT_SHOW_DEFAULT_LOGIN_PROVIDER=false #SSOログインを実施する場合で、通常のログインフォームを表示しない場合に追加


~~~



## コマンド実行
- 以下のコマンドを実行します。

~~~
composer require exceedone/exment
php artisan vendor:publish --provider="Encore\Admin\AdminServiceProvider"
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=public --force
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=lang --force
~~~

## config変更

- "config\database.php"を開き、 キー "mysql" の値を以下のように修正します。

~~~ php
'mysql' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'forge'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    // Exment Edit------s
    //'strict' => true,
    'strict'    => false,
    'options'   => [
        PDO::ATTR_CASE => PDO::CASE_LOWER,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => true,
        PDO::ATTR_EMULATE_PREPARES => true,
    ],
    // Exment Edit------e
    'engine' => null,
],

~~~

- "config\admin.php"を開き、 キー "auth.providers.admin" を以下のように修正します。

~~~ php
    'auth' => [
        'providers' => [
            'admin' => [
                // Exment Edit------s
                // 'driver' => 'eloquent',
                //'model'  => Encore\Admin\Auth\Database\Administrator::class,
                'driver' => 'exment-auth',
                // Exment Edit------e
            ],
        ],  
    ],
~~~

- 言語とタイムゾーンを変更したい場合、"config\app.php"を開き、 以下の行を修正します。

~~~ php

    // 'timezone' => 'UTC',
    'timezone' => 'Asia/Tokyo',

    //'locale' => 'en',
     'locale' => 'ja',

~~~


## コマンド実行
- 以下のコマンドを実行します。

~~~
php artisan exment:install
~~~
