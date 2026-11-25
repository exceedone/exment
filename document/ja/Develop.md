# 開発環境セットアップ
このページでは、Exment開発環境のセットアップ方法について記載します。

## はじめに
- Visual Studio Codeによる開発を推奨しています。また、この記事はWindows向けに作成しています。Mac端末だと相違点がある可能性がありますが、ご了承ください。
- このページは作成中です。もしセットアップがうまくいかない場合、issueに記載してください。
- 事前に、以下のアプリケーションがインストールされている必要があります。
    - Visual Studio Code
    - PHP 8.3以上（PHP 8.4推奨）
    - git
    - composer
    - node.js(npm)
- ローカルでExmentを動作させる為に必要なWEBサーバー、データベース等は、ご自身の環境に合わせてご用意ください。[参考サイト](https://exment.net/docs/#/ja/server)

## リポジトリのフォーク
- GitHubの [https://github.com/exceedone/exment] にアクセスし、ページ右上の「Fork」ボタンをクリックしてください。

- Exmentをあなたのリポジトリにフォークしてください。また、あなたのリポジトリをコピーしてください。  
(ex. https://github.com/hirossyi73/exment
以後、あなたのGitHubユーザー名・オーナー名を、"hirossyi73"として記載しています。)

## Laravelのプロジェクト作成
- Laravelのプロジェクトを作成します。

~~~
composer create-project "laravel/laravel=13.*" (プロジェクト名)
cd (プロジェクト名)
~~~

- "packages"ディレクトリを作成します。

~~~
mkdir packages
cd packages
~~~

- あなたのGitHubのユーザー名で、ディレクトリを作成します。  
(ex. hirossyi73)

~~~
mkdir hirossyi73
cd hirossyi73
~~~

- 以下のコマンドを実行します。
~~~
composer config --no-plugins allow-plugins.kylekatarnls/update-helper true
composer require psr/simple-cache=^2.0.0
~~~

- あなたのリポジトリをクローンします。
(ex. https://github.com/hirossyi73/exment.git)

~~~
git clone https://github.com/hirossyi73/exment.git
~~~
- (任意)あなたのexment-admin-coreリポジトリをクローンします。
(ex. https://github.com/hirossyi73/exment-admin-core.git)

~~~
git clone https://github.com/hirossyi73/exment-admin-core.git
~~~

- Laravelプロジェクトフォルダ直下の、composer.jsonを書き換えます。
**便宜上、下記の記述にコメントを記載していますが、実際はコメントを削除してください。**

~~~
    "require": {
        "php": "^8.3",
        "guzzlehttp/guzzle": "^7.10",
        "laravel/framework": "^13.0",
        "laravel/sanctum": "^4.3.1",
        "laravel/tinker": "^3.0",
        "psr/simple-cache": "2.0.0",
        // 行追加
        "exceedone/exment": "dev-master"
        // 任意
        ,"exceedone/exment-admin-core": "dev-master"
    },

    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/",
            // 行追加。また、"hirossyi73"部分は書き換えてください
            "Exceedone\\Exment\\": "packages/hirossyi73/exment/src/"
        }
    },

    // ブロックを追加。また、"hirossyi73"部分は書き換えてください
    "repositories": [
        {
            "type": "path",
            "url": "packages/hirossyi73/exment",
            "options": {
                "symlink": true
            }
        }
        // 任意
        ,
        {
            "type": "path",
            "url": "packages/hirossyi73/exment-admin-core",
            "options": {
                "symlink": true
            }
        }
    ]
~~~

- 以下のコマンドを、Laravelプロジェクトフォルダ直下で実施してください。

~~~
composer update
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
php artisan passport:keys
~~~

- 以下のページにアクセスし、Exmentページにアクセス＆セットアップしてください。
(ex. http://localhost/admin)


## Typescriptセットアップ

- 以下のサイトから、tsconfig.jsonファイルをダウンロードし、Laravelプロジェクトフォルダ直下に配置してください。  
[tsconfig.json](https://exment.net/downloads/develop/tsconfig.json)

- tsconfig.jsonを開き、5行目と10行目を、自分のオーナー名に編集してください。

```
  "compilerOptions": {
    ～ 省略 ～
    "outDir": "./packages/hirossyi73/exment/public/vendor/exment/js",
    ～ 省略 ～
  },
  "include": [
    "packages/hirossyi73/exment/src/Web/ts/*.ts"
  ]
```


- 以下のコマンドを実施し、npmパッケージをインストールしてください。

~~~
npm init
# そのままEnterで初期設定完了
npm install -g typescript
npm install @types/node @types/jquery @types/jqueryui @types/jquery.pjax @types/bootstrap @types/icheck @types/select2 @types/jquery.validation
~~~

- 以下の"\*.d.ts"ファイルをダウンロードしてください。これらのファイルは、npmに含まれていないファイルです。  
その後、これらの"*.d.ts"ファイルを、"node_modules/@types/(パッケージ名フォルダ)"に配置してください。   
例：node_modules/@types/bignumber/index.d.ts、node_modules/@types/exment/index.d.ts  
[bignumber/index.d.ts](https://exment.net/downloads/develop/bignumber/index.d.ts)  
[exment/index.d.ts](https://exment.net/downloads/develop/exment/index.d.ts)

- プロジェクトフォルダ直下の"packages.json" ファイルを開き、"dependencies" ブロックに、ダウンロードしたファイルの記述を追加します。

~~~
"dependencies": {
    "@types/bignumber": "^1.0.0",
    "@types/exment": "^1.0.0",
}
~~~

- プロジェクトフォルダ直下の"tasks.json"ファイルを、プロジェクトルートフォルダの".vscode"フォルダにコピーします。  
※"tasks.json"ファイルがなければ、[こちら](https://exment.net/downloads/develop/tasks.json)からダウンロードしてください。  
※".vscode"フォルダがなければ、ご自身で作成してください。  

- index.d.tsで、bootstrapとjqueryの定義ファイルで競合が発生しているので、個別に修正します。  
node_modules\@types\bootstrap\index.d.tsを開き、以下のように修正します。※27行目付近です。

``` typescript
declare global {
    interface JQuery {
        alert: Alert.jQueryInterface;
        // コメントアウト
        //button: Button.jQueryInterface;
        carousel: Carousel.jQueryInterface;
        collapse: Collapse.jQueryInterface;
        dropdown: Dropdown.jQueryInterface;
        tab: Tab.jQueryInterface;
        modal: Modal.jQueryInterface;
        offcanvas: Offcanvas.jQueryInterface;
        [Popover.NAME]: Popover.jQueryInterface;
        scrollspy: ScrollSpy.jQueryInterface;
        toast: Toast.jQueryInterface;
        // コメントアウト
        //[Tooltip.NAME]: Tooltip.jQueryInterface;
    }
}

```


- あなたが "exment" パッケージ内の.tsファイルを編集した後、コンパイルする場合は、"Ctrl + Shift + B" コマンドをVSCodeで実行してください。（おそらくMacでは、"Command + Shift + B"です）  
コンパイルされたjsファイルが、 packages/hirossyi73/exment/public/vendor/exment/js に配置されます。

- コンパイルしたjsファイルをWebに反映したい場合、以下のコマンドを実行してください。

~~~
php artisan exment:publish
~~~

## Sass設定

- VsCodeの拡張機能"EasySass"をインストールしてください。  
[EasySass](https://marketplace.visualstudio.com/items?itemName=spook.easysass)

- VSCodeの設定画面を開きます。サブメニューの拡張機能→EasySassを選択します。  
"Target Dir"の欄に、"packages/hirossyi73/exment/public/vendor/exment/css"を入力します。  
※"hirossyi73"の部分はご自身のオーナー名に変換してください。  

- あなたが.scssファイルを編集して、保存した際、.cssファイルが自動的に packages/hirossyi73/exment/public/vendor/exment/cssに配置されます。

- cssファイルをWebに反映する場合は、以下のコマンドを実行してください。

~~~
php artisan exment:publish
~~~

## その他

- シンボリックリンクの設定により、Exmentのパッケージのファイルは、packagesフォルダとvendorフォルダの両方に存在します。  
プログラムを修正する際は、packagesフォルダを編集するようにお願いします。


## GitHub

### ブランチ
GitHubのブランチを、以下のように運用しています。  
[Gitのブランチモデルについて](https://qiita.com/okuderap/items/0b57830d2f56d1d51692)

> **今の運用（2026-11-20 から）**: pull request は、すべて `master` に出します。
>
> | ブランチ | 内容 | pull request |
> |---|---|---|
> | `master` | 次の版の開発 | **すべてここに出す**（機能の追加・高速化・不具合の修正） |
> | `7.1` | 7.1.x の保守（v7.1.0 のタグから作る） | 直接は出さない。`master` に出して、ラベル `backport-7.1` を付ける |
> | `7.0` | 7.0.x の保守 | 直接は出さない。`master` に出して、ラベル `backport-7.0` を付ける |
> | `6.2` | 6.2.x の保守（脆弱性の修正だけ。2027-03-31 まで） | 直接は出さない。ラベル `backport-6.2` |
>
> **リリースの流れ**
> 1. `master` には、**次の版で出してよいものだけ**をマージする。**出したくないものは、リリースが終わるまで PR をマージしない**。作りかけ・大きな変更（例：Laravel 13 への移行）は、機能のブランチ（`feature/XXX`）で育てる。入れても見せたくないものは、設定で切り替えられるようにして**初期値をオフ**にする
> 2. リリースの日に、`master` にタグ（例：`v7.2.0`）を付けてリリースする
> 3. **版のブランチ（例：`7.2`）は、タグを付けたときに、そのタグの位置で作る**（`git push origin v7.2.0^{commit}:refs/heads/7.2`）。それまでは作らない。作ったブランチは、そのまま 7.2.x の保守に使う
> 4. もう `master` に入っていて出したくないものがあったら、タグを付ける前に `master` で `git revert` する（出したあとで、もう一度入れる）
> 5. 出したあとの直しは `master` に出し、`backport-7.x` のラベルを付ける。まとめ役が版のブランチに cherry-pick し、7.x.y として出す
>
> 脆弱性の修正は、公開の Issue・pull request にせず、[SECURITY.md](../../SECURITY.md) の方法で扱います。
>
> 下の表は以前のブランチの運用です。

| GitHub Brunch Name | Derived from | Explain |
| ------------------ | -------------| ------------- |
| `master` | - | 現在の安定版です。リリースした時点でのソースコードを管理します。 |
| `hotfix` | master | リリースしたソースコードで、致命的な不具合があったときに緊急対応を行うためのブランチです。push後、developとmasterにマージします。 |
| `hotfixfeature` | hotfix | 致命的な不具合があったときの緊急対応で、多数の修正が発生する場合に、hotfixよりブランチを作成し、対応を行います。修正が完了後、hotfixにマージします。 |
| `develop` | master | 次期機能などの開発を行うブランチです。 |
| `feature` | develop | 実装する機能ごとのブランチです。 開発が完了したら、developにマージを行います。 例：feature/XXX, feature/YYY |
| `release` | develop | developでの開発完了後、リリース時の微調整を行うためのバージョンです。こちらのブランチで動作確認を行い、完了したら、masterにマージを行います。 |
| `test` | master | テストコード作成用のブランチです。 |
| `testfeature` | test | 新しいテストを追加する場合、このブランチを作成し、コードを追加します。例：testfeature/XXX |

### コミット前に実行 - php-cs-fixer
GitHubにpushする前に、以下のコマンドを実行し、ソースを整形してください。  

#### 初回のみ実行

```
composer global require friendsofphp/php-cs-fixer

# 環境変数に以下のパスを追加してください。（"%USERPROFILE%"は、ご自身のユーザーディレクトリを指す変数です。 ex:「C:\Users\XXXX」）
%USERPROFILE%\AppData\Roaming\Composer\vendor\bin 
```

#### ソース整形
~~~
# 不要な"use"を削除
php-cs-fixer fix ./packages/hirossyi73/exment --rules=no_unused_imports 
# すべてのソースを整形
php-cs-fixer fix ./packages/hirossyi73/exment
~~~


### 本体にコミット
本体のexceedone/exmentにコミットを行う場合、以下の手順を実施してください。  

- GitHubのpull requestを実行します。  

- exceedone/exmentの、上の「今の運用」の表のブランチ（新しい機能も不具合の修正も **master**）を対象に、pull requestを実行します。ブランチ名は、機能追加は `feature/XXX`、不具合対応は `fix/XXX` としてください。  
コメントは、可能な限り記載してください。
