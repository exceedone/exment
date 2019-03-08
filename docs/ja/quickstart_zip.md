# クイックスタート(ZIP版)
Exmentを開始するために必要となる手順です。zipファイルよりインストールする方法です。  
※非推奨のインストール方法です。[クイックスタート](/ja/quickstart.md)での導入をおすすめしております。  

## PHP, MySQL環境構築
Exmentには、PHP7.1.3以上とMySQL5.7以上が必要です。  
未導入の方は、PHPとMySQLを同時にインストールできる、XAMPPをお試しください。  
※すでに導入済の方は不要です。  
- [Windows版 XAMPP](https://www.apachefriends.org/xampp-files/7.1.26/xampp-windows-x64-7.1.26-0-VC14-installer.exe)
- [Linux版 XAMPP](https://www.apachefriends.org/xampp-files/7.1.26/xampp-linux-x64-7.1.26-0-installer.run)
- [Mac版 XAMPP](https://www.apachefriends.org/xampp-files/7.1.26/xampp-osx-7.1.26-0-installer.dmg)


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
DB_PASSWORD=secret #MySQLのExment用データベースのパスワード

# 以下、メール送信用の設定値変更
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io #メールサーバー用のホスト名
MAIL_PORT=2525 #メールサーバー用のポート番号
MAIL_USERNAME=null  #メールサーバーのユーザー名
MAIL_PASSWORD=null #メールサーバーのパスワード
MAIL_ENCRYPTION=null #ssl使用の場合"ssl"と記入

# 以下、特定の場合に追加
ADMIN_HTTPS=true #https通信の場合に追加
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