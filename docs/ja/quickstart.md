# クイックスタート
Exmentを開始するために必要となる手順です。  
※composerの導入が必要です。
※MySQLは、json型に対応している5.7以上でのみご利用可能です。

## Laravelインストール(プロジェクト作成)
- コマンドラインで、以下のコマンドを実行します。  
※作成したプロジェクトのフォルダを、このマニュアルでは「ルートディレクトリ」と呼びます。

~~~
composer create-project "laravel/laravel=5.6.*" (プロジェクト名)
cd (プロジェクト名)
~~~

## データベース作成
- Exment用のデータベースを、MySQLで作成してください。


## .env変更

- ".env" を開き、以下の内容を追加・変更します。  

~~~
#基本設定
APP_URL=http://XXXX.com #そのサイトにアクセスするURL。"admin"は不要

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
EXMENT_API=true #外部APIを使用する場合にtrueに追加

~~~



## コマンド実行
- 以下のコマンドを実行します。

~~~
composer require exceedone/exment
php artisan vendor:publish --provider="Encore\Admin\AdminServiceProvider"
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=public --force
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=lang --force
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=views_vendor
~~~

## config変更

- "config\admin.php"を開き、 キー "auth.guards.admin.provider" を以下のように修正します。

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
php artisan passport:keys
php artisan exment:install
~~~

## その他の初期設定
以上の作業で、Exmentを開始することは可能ですが、一部の機能を使うために、追加で設定が必要になる場合があります。  
以下のリンクをご確認ください。  
- [シングルサインオン](/ja/quickstart_more.md#シングルサインオン)
- [タスクスケジュール機能](/ja/quickstart_more.md#タスクスケジュール機能)