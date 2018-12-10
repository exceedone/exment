# クイックスタート(ZIP版)
Exmentを開始するために必要となる手順です。zipファイルよりインストールする方法です。  
※MySQLは、json型に対応している5.7以上でのみご利用可能です。  

## zipダウンロード・展開
- 以下のURLより、zipファイルをダウンロードします。  
[Exment zip版](https://exment.net/downloads/ja/exment.zip)

- zipファイルを、PHP実行可能なパスに展開します。  
例：C:\xampp\htdocs


## APP_KEY再生成
アプリケーションキー(APP_KEY)を再生成します。以下のコマンドを実行してください。  
※APP_KEYは、データの暗号化で使用する内容です。このコマンドを実行しない場合、zip内にあるデフォルトのAPP_KEYが使用されてしまいます。セキュリティ対策として、必ず実行してください。

~~~
cd (展開したexmentのルートフォルダ)
php artisan key:generate
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
php artisan exment:install
~~~
