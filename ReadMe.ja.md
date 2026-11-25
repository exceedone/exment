<p align="center">
<a href="ReadMe.md">English</a> | <b>日本語</b> | <a href="ReadMe.vi.md">Tiếng Việt</a>
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


## v7.0.0 の新機能
2026 年 10 月 28 日リリース。仕事の進み具合がひと目でわかります。（[What's new](https://exment.net/whats-new/)）

### カンバンビュー
ステータスなどの列ごとにデータをカードで並べ、ドラッグで移動。ワークフローとも連動します。（[マニュアル](https://exment.net/docs/#/ja/view_kanban)）このページの一番上の画像がカンバンビューです。

### ガントチャートビュー
開始日から期限日までを横棒で時系列に表示。色分け・進捗率・マイルストーン・親子の字下げに対応。（[マニュアル](https://exment.net/docs/#/ja/view_gantt)）

### ワークフローのフロー設計
ステータスとアクションを図で配置して、ワークフローの流れを見ながら設計できます。（[マニュアル](https://exment.net/docs/#/ja/workflow_design)）  
![ワークフローのフロー設計](https://exment.net/docs/ja/img/workflow_design/wf_designer.png)

### セルのスタイル
値に応じてデータ一覧やカンバンのセルの色・文字を変えるプリセットを設定できます。（[マニュアル](https://exment.net/docs/#/ja/cell_style_preset)）  
![セルのスタイル](https://exment.net/docs/ja/img/cell_style_preset/preset_grid_result.png)

### データ一覧のツール
表示する列の切り替え、列の固定、グループ化、インライン編集、クイックプレビュー、一括操作など。（[マニュアル](https://exment.net/docs/#/ja/data_grid_tools)）  
![データ一覧のツール](https://exment.net/docs/ja/img/data_grid_tools/grid_overview.png)

### Laravel 12・PHP 8.2 以上に対応
フレームワークを Laravel 12 に更新し、管理画面のライブラリも新しくしました。アップデート前に注意点をご確認ください。（[アップデート](https://exment.net/docs/#/ja/update)）


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
- PHP 8.3.0 以上（8.4 推奨）
- MySQL 8.0 以上、または MariaDB 10.11 以上
- Laravel 13.X

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
脆弱性を見つけた場合は、公開の issue には書かず、[SECURITY.md](SECURITY.md) の方法で非公開でご報告ください。

# コミュニティ
現在、Exment ではコミュニティ・フォーラムを用意していません。issue を活用しています。ただし、将来的にほかのプラットフォームを使うかもしれません。詳しい知見をお持ちの方は、おすすめを教えてください。

## Pull Request
Pull Request はいつでも大歓迎です。現在は、新しい機能の要望よりも、その機能を実際に実装していただけると嬉しいです。

## ブランチ
| ブランチ | Packagist | 中身 |
|---|---|---|
| `master` | `dev-master` | **次の版の開発。Pull Request はすべてここに出してください**（新しい機能も、不具合の直しも） |
| `7.1` | `7.1.x-dev` | 7.1 系の保守（7.1.1、7.1.2 …） |
| `7.0` | `7.0.x-dev` | 7.0 系の保守（7.0.4、7.0.5 …） |
| `6.2` | `6.2.x-dev` | 6.2 系の保守（6.2.16、6.2.17 …） |

出した版にも入れたい直しは、まず `master` に出し、Pull Request にラベル `backport-7.1`・`backport-7.0`・`backport-6.2` を付けてください。まとめ役がそのブランチに入れます。リリースの流れ（入れたくないものの扱いなど）は [Develop.md](document/ja/Develop.md) に書いてあります。

Exment を入れるときは、`-dev` ではなく、リリースした版を使ってください（例: `composer require exceedone/exment`）。詳しくは [Develop.md](document/ja/Develop.md)


# そのほかのページ
開発方法 [日本語](document/ja/Develop.md) / [English](document/en/Develop.md)  
テスト実行方法 [日本語](document/ja/Test.md) / [English](document/en/Test.md)  


# issues
issues には、英語または日本語で記載してください。  
脆弱性は [SECURITY.md](SECURITY.md) をご覧ください。


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
