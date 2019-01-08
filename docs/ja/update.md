# アップデート
Exmentのバージョンが更新され、アップデートが必要になった場合の手順です。

## (任意)データのバックアップ
データのバックアップを実行します。
- 管理者でExmentにログインし、左メニューより「管理者設定」→「バックアップ」を選択します。
- 「バックアップ」ページ右上の「バックアップ」ボタンをクリックします。
- 最新のデータ、ならびに添付ファイルなどのバックアップが作成されます。
- その後、実行した時刻のバックアップファイルをクリックし、ダウンロードします。


## 最新ソース取得、反映
- コマンドラインで、以下のコマンドを実行します。  

~~~
cd (プロジェクトのルートディレクトリ)
composer update exceedone/exment
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=public --force
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=lang --force
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=views_vendor
~~~

## データベース最新化
- コマンドラインで、以下のコマンドを実行します。  

~~~
php artisan migrate
~~~

