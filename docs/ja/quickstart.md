# クイックスタート
Exmentを開始するために必要となる手順です。  
※composerの導入が必要です。

## Laravelインストール(プロジェクト作成)
- コマンドラインで、以下のコマンドを実行します。

~~~
composer create-project "laravel/laravel=5.5.*" (プロジェクト名)
cd (プロジェクト名)
~~~

- ".env" を開き、データベース文字列を、ご自身のもつMySQLの設定値に変更します。  
※MySQLは、json型に対応している5.7以上でのみご利用可能です。

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


- "config\app.php"を開き、 キー "providers" に以下の行を追加します。

~~~ php

'providers' => [
    ...
    // Exment Add------s
    Collective\Html\HtmlServiceProvider::class,
    Exceedone\Exment\ExmentServiceProvider::class,
    Exceedone\Exment\Providers\PasswordResetServiceProvider::class,
    // Exment Edit------e
]

'aliases' => [
    ...
    // Exment Add------s
    'Uuid' => Webpatser\Uuid\Uuid::class,
    'Form' => Collective\Html\FormFacade::class,
    'Html' => Collective\Html\HtmlFacade::class,
    // Exment Edit------e
]

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
