# クイックスタート
※この手順はdev版です。githubに公開、composerにアップロードした後に、再度手順を確立します。

## ファイルコピー
- フォルダ "packages/exceedone/exment" をプロジェクトのルートに追加します。

- ソースコードを "exment" フォルダに追加します。

## composer変更

- ルートの "composer.json" に、以下の行を追加します。

~~~ json
"require": {
    "exceedone/exment": "dev-master"
}

"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Exceedone\\Exment\\": "packages/exceedone/exment/src/"
    }
},

"repositories": [
{
    "type": "path",
    "url": "packages/exceedone/exment",
    "options": {
        "symlink": true
        }
    }
]

~~~


- ".env" を開き、データベース文字列を、ご自身のもつMySQLの設定値に変更します。  
※MySQLは、json型に対応している5.7以上でのみご利用可能です。


## コマンド実行
- 以下のコマンドを実行します。

~~~
composer update exceedone/exment
php artisan vendor:publish --provider="Encore\Admin\AdminServiceProvider"
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=public --force
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
php artisan admin:install
php artisan exment:install
~~~

## (補足)データリセット
現在、このプロジェクトは開発中です。そのため、頻繁にテーブル定義が変更される可能性があります。
そのため、ときどきテーブル再定義を行う必要があります。
（もちろん、将来的にはそのような必要はなくなります）
その際には、以下のコマンドを実施してください。

~~~
php artisan migrate:reset
php artisan exment:install
~~~
