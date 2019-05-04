<p align="center">
<img src="https://exment.net/docs/img/common/exment_logo_side.png" alt="Exment">
</p>

<p align="center">
<a href="https://exment.net/docs/#">Document</a> | 
<a href="https://exment.net/docs/#/ja/">ドキュメント</a>
</p>

デモ
------------
<p align="center">
<a href="https://demo.exment.net/admin">Demo Site</a> | 
<a href="https://demo-jp.exment.net/admin">デモサイト</a>
<br/>※IDとパスワードは「admin/adminadmin」を入力してください
</p>

## Exmentとは
Exmentは、情報資産をWeb上で管理するための、オープンソースソフトウェアです。

## 機能
- ダッシュボード
- 画面上からデータ登録
- 独自テーブル・独自列作成
- テンプレートのインポート・エクスポート（独自テーブル・列を、他のExmentで使用することが可能）
- データのインポート・エクスポート
- フォーム内での値計算機能(合計額や、税額の計算)
- 権限管理
- 組織管理
- メニュー構成管理
- 検索（フリーワード検索、情報に関連する単語の検索）
- メールテンプレート
- プラグイン（独自のページや機能を作成）
- 資料作成(見積書や請求書を画面から作成)
- API（他のシステムなどからデータ連携）

## 動作環境
### サーバー
- PHP 7.1.3以上
- MySQL 5.7.8以上 または MariaDB 10.2.7以上
- Laravel5.6以上

### 動作確認ブラウザ
- Google Chrome
- Internet Explorer

## その他
Exmentは、以下のプラグイン・サービスなどを利用しております。
+ [Laravel](https://laravel.com/)
+ [laravel-admin](http://laravel-admin.org/)
+ [Laravel Passport](https://github.com/laravel/passport)
+ [Laravel Uuid](https://github.com/webpatser/laravel-uuid)
+ [mPDF](https://github.com/mpdf/mpdf)
+ [PhpSpreadsheet](https://github.com/phpoffice/phpspreadsheet)
+ [TinyMCE](https://www.tiny.cloud/)

## スクリーンショット
------------

![Custom Table and Column](https://exment.net/docs/img/common/screenshot_table_and_column.jpg)  
  
![Custom Form and view](https://exment.net/docs/img/common/screenshot_form_and_view.jpg)  
  
![Custom Data, Dashboard and Template](https://exment.net/docs/img/common/screenshot_data_dashboard_template.jpg)

------------
# GitHub運用

## ブランチ
GitHubブランチは、以下の運用とします。  
※参考： [Gitのブランチモデルについて](https://qiita.com/okuderap/items/0b57830d2f56d1d51692)

| GitHub ブランチ名 | 派生元 | 説明 |
| ------------------ | -------------| ------------- |
| `master` | - | 現在の安定版です。リリースした時点でのソースコードを管理します。 |
| `hotfix` | master | リリースしたソースコードで、致命的な不具合があったときに緊急対応を行うためのブランチです。push後、developとmasterにマージします。 |
| `develop` | master | 次期機能などの開発を行うブランチです。 |
| `feature` | develop | 実装する機能ごとのブランチです。 feature/XXX, feature/YYYなど。開発が完了したら、developにマージを行います。 |
| `release` | develop | developでの開発完了後、リリース時の微調整を行うためのバージョンです。こちらのブランチで動作確認を行い、完了したら、masterにマージを行います。 |

## Commit前 - php-cs-fixer
GitHubにCommitを行う前に、php-cs-fixerを実施し、ソースコードの整形を行ってください。  

~~~
composer global require friendsofphp/php-cs-fixer #初回のみ
%USERPROFILE%\AppData\Roaming\Composer\vendor\bin # 左記を環境変数に追加。%USERPROFILE%は、「C:\Users\XXXX」など、端末のユーザー名に置き換える
php-cs-fixer fix ./exment --rules=no_unused_imports #不要なuse削除
php-cs-fixer fix ./exment/src #全般の整形
~~~