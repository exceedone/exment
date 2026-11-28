<p align="center">
<a href="README.md">English</a> | <b>日本語</b> | <a href="README.vi.md">Tiếng Việt</a>
</p>

<p align="center">
<picture>
<source media="(prefers-color-scheme: dark)" srcset=".github/images/exment_logo_dark.svg">
<img src=".github/images/exment_logo.svg" alt="Exment" width="360">
</picture>
</p>

<h3 align="center">ノーコードで業務システムを作れる、無料のオープンソース Web データベース</h3>

<p align="center">
<a href="https://exment.net/">公式サイト</a> ·
<a href="https://exment.net/demo-env/">デモ</a> ·
<a href="https://exment.net/docs/#/ja/">マニュアル</a> ·
<a href="https://exment.net/whats-new/">What's new</a> ·
<a href="https://exment.net/roadmap/">今後の予定</a>
</p>

<p align="center">
<img src="https://exment.net/docs/ja/img/view_kanban/kanban_board.png" alt="Exment のカンバンビュー" width="800">
</p>


## Exment とは
Exment（エクスメント）は、プログラミングなしで業務システムを作れる、無料のオープンソース Web データベースです。Excel やバラバラのファイルで管理していた顧客・案件・申請などの情報を、画面の設定だけでひとつにまとめられます。  
ワークフロー、カンバン、ガントチャート、SSO・2 段階認証など、業務に必要な機能がそろっています。自社のサーバー、国内・海外の 99% のレンタルサーバー（XServer・さくらインターネットなど）、クラウド（AWS・Azure など）で動くので、データを自社で持てます。ユーザーが何人になっても、料金は増えません。

- **無料。ユーザー数で料金が増えない**: オープンソース（GPL）。ユーザー数・機能の制限はありません
- **業務向けのテンプレートですぐ始められる**: 「タスク管理」「顧客管理」などのテンプレートを用意しています
- **自社のサーバーで動き、データを自社で持てる**: PHP（Laravel）と MySQL / MariaDB / SQL Server で動きます
- **実績**: オープンソース版のインストール 40,000 回以上（Packagist の累計、2026 年 11 月時点）、2018 年の公開から 200 回以上のバージョンアップ

| | |
|---|---|
| 製品の紹介 | [公式サイト](https://exment.net/) ・ [主な機能](https://exment.net/functions/) ・ [活用法の紹介](https://exment.net/modelcases/) ・ [資料（PDF）](https://exment.net/assets/exment-flyer.pdf) |
| 試す | [デモサイト一覧](https://exment.net/demo-env/) ・ [インストール手順](https://exment.net/docs/#/ja/quickstart) |
| 料金・比較 | [料金・サポート・ライセンス](https://exment.net/plan/) ・ [ほかのサービスとの比較](https://exment.net/compare/) |
| AI | [AI-OCR](https://exment.net/ai-ocr/) ・ [Exment AI Chatbot](https://exment.net/exment-ai-chatbot/) |
| 新機能・予定 | [What's new（新機能）](https://exment.net/whats-new/) ・ [今後の予定（ロードマップ）](https://exment.net/roadmap/) |
| 使い方 | [マニュアル](https://exment.net/docs/#/ja/) |
| お問い合わせ | [お問い合わせ](https://exment.net/inquiry/)（導入・乗り換えのご相談、保守サポート） |

<a href="https://demo.exment.net/admin" target="_blank">デモサイト</a>  
次の内容でログインしてください。  
ユーザーコード：admin  
パスワード：adminadmin

開発: [株式会社カジトリ](https://exment.net/company/)（エクシード・ワン グループ）


## いまの Exment（7.2・7.3）
- **ノーコードの Web データベース**：テーブル・列・フォーム・ビュー（一覧・集計・カレンダー・カンバン・ガントチャート）を画面で作れます
- **ワークフロー**：承認の流れを図で設計できます
- **機能の管理**：新しい機能は、はじめは「使わない」。使う機能を管理者が選ぶので、更新しただけでサイトが変わりません
- **API キーと MCP サーバー**：ほかのシステムや AI のツールから Exment を使えます
- **ファイルの共有**：権限つきのフォルダー・ファイル、共有リンク、前の版
- **無料の公式プラグインを同梱**：プロジェクト管理・グループウェア・サポートデスク。管理者が「使う」にしたときだけ入ります

版ごとのくわしい内容：[What's new](https://exment.net/whats-new/) ・ [リリースノート](https://exment.net/docs/ja/release_note.html)


## 主な機能
- ダッシュボード
- 画面からのデータ登録
- カスタムテーブル・カスタム列の作成
- 業務向けのテンプレート（タスク管理・顧客管理など）
- テンプレートのインポート・エクスポート（カスタムテーブル・列をほかの Exment で使える）
- データのインポート・エクスポート
- フォームでの計算（合計金額・税額の計算など）
- 権限の管理
- 組織の管理
- メニューの設定
- 検索（フリーワード検索・関連する情報の検索）
- メールテンプレート
- ビュー（一覧・集計・カレンダー・カンバン・ガントチャート）とセルのスタイル
- データ一覧のツール（表示する列・列の固定・グループ化・インライン編集・一括操作）
- ワークフロー（フロー設計の画面つきの承認フロー）
- 公開フォーム（ログインなしでのデータ登録）
- 通知（メール・画面・Slack・Microsoft Teams・LINE）
- ログインのセキュリティ（2 段階認証、SAML / LDAP / OAuth による SSO）
- 帳票の出力（PDF / Excel）、バックアップ・リストア
- プラグイン
- API

ほかにも、たくさんの機能があります。

## 動作環境
### サーバー
- PHP 8.2.0 以上
- MySQL 8.0 以上、または MariaDB 10.11 以上
- Laravel 12.X

### 対応ブラウザ
- Google Chrome
- Microsoft Edge

## 画面

### データ一覧
![データ一覧](https://exment.net/wp-content/uploads/2020/03/list_of_data.gif)  
  
### データの編集
![データの編集](https://exment.net/wp-content/uploads/2020/03/list_edit.gif)  
  
### 検索
![検索](https://exment.net/wp-content/uploads/2020/03/search.gif)

### ダッシュボード
![ダッシュボード](https://exment.net/wp-content/uploads/2020/03/dashboard.gif)

### カレンダー
![カレンダー](https://exment.net/wp-content/uploads/2019/05/capture_7_calendarview.png)


## クイックスタート
> LAMP の環境と composer を用意してください。くわしい手順は[マニュアルのインストール手順](https://exment.net/docs/#/ja/quickstart)をご覧ください。

- composer で Laravel のプロジェクトを作ります（「exment」はプロジェクト名です）。

~~~
composer create-project "laravel/laravel=12.*" exment
cd exment
~~~

- composer で exceedone/exment を追加します。

~~~
composer require exceedone/exment
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
~~~

- .env ファイルを編集します。

~~~
### Database setting
# Change database setting
# If your database is MySQL, as below
DB_CONNECTION=mysql
# If your database is MariaDB, as below.
DB_CONNECTION=mariadb

DB_HOST=127.0.0.1 #MySQL host name
DB_PORT=3306 #MySQL port no.
DB_DATABASE=homestead #MySQL database name for Exment.
DB_USERNAME=homestead #MySQL user name for Exment.
DB_PASSWORD=secret #MySQL password name for Exment.

### timezone and locale
APP_TIMEZONE=Asia/Tokyo
APP_LOCALE=ja
~~~

- （推奨）エラーページを追加します。Laravel 12 には `app/Exceptions/Handler.php` がなく、例外の処理は `bootstrap/app.php` で設定します。`withExceptions` のブロックを次のように変えてください。

~~~ php
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;

    ->withExceptions(function (Exceptions $exceptions) {
        // Add
        $exceptions->render(function (\Throwable $e, Request $request) {
            // Returning null falls back to Laravel's default rendering.
            return \Exment::error($request, $e, function ($request, $e) {
                return null;
            });
        });
    })
~~~


- Exment のページを開きます。  
URL は http://127.0.0.1:8000/admin です。

~~~
php artisan serve
~~~

# セキュリティ
脆弱性を見つけたときは、公開の issue に書かず、[SECURITY.md](SECURITY.md) の方法で非公開でご報告ください。

# 開発する人へ
Pull Request を歓迎します。機能の要望より、実際に作っていただけるとさらにうれしいです。

- [CONTRIBUTING.ja.md](CONTRIBUTING.ja.md)：Pull Request の出し方（どのブランチへ・テスト・書き方）
- [docs/ja/development.md](docs/ja/development.md)：開発の環境の作り方
- [docs/ja/releasing.md](docs/ja/releasing.md)：ブランチ・版の番号・リリースのしかた
- [document/ja/Test.md](document/ja/Test.md)：テストの動かし方
- [マニュアル](https://exment.net/docs/ja/) ・ [リリースノート](https://exment.net/docs/ja/release_note.html) ・ [保守の期間](https://exment.net/docs/ja/support_policy.html)

Exment を入れるときは、`-dev` のブランチではなく、リリースした版を使ってください（例: `composer require exceedone/exment`）。

# コミュニティ
いまはフォーラムがないので、GitHub の issue をお使いください。issue は日本語か英語で書いてください。  
参加する人は、みな [行動規範（Code of Conduct）](CODE_OF_CONDUCT.md) を守ります。


# 関連するリポジトリ

- **[exment-admin-core](https://github.com/exceedone/exment-admin-core)**  
Exment の土台のフレームワーク（名前空間 `ExmentAdminCore\Admin`）。[open-admin](https://github.com/open-admin-org/open-admin) / [open-admin-core](https://github.com/dedermus/open-admin-core) からのフォークで、これらは [z-song/laravel-admin](https://github.com/z-song/laravel-admin) から派生しています。

- **[マニュアル](https://github.com/exceedone/exment-manual)**  
Exment のマニュアルのページ。

- **[Update Batch](https://github.com/exment-git/batch-update)**  
アップデートのバッチ。

- **[Auto Composer](https://github.com/exment-git/auto-composer)**  
composer を自動で実行します。

- **[Docker](https://github.com/exment-git/docker-exment)**  
Exment の Docker。

- **[Plugin Sample](https://github.com/exment-git/plugin-sample)**  
Exment のプラグインのサンプル。

- **[Spout](https://github.com/exment-git/spout)**  
Excel のインポート・エクスポートのライブラリ。  
[box/spout](https://github.com/box/spout) からのフォークです。box/spout がアーカイブされたため、フォークしました。
