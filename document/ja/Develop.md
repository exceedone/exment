# 開発環境セットアップ
このページでは、Exment開発環境のセットアップ方法について記載します。

## はじめに
- Visual Studio Codeによる開発を推奨しています。また、この記事はWindows向けに作成しています。Mac端末だと相違点がある可能性がありますが、ご了承ください。
- このページは作成中です。もしセットアップがうまくいかない場合、issueに記載してください。
- 事前に、以下のアプリケーションがインストールされている必要があります。
    - Visual Studio Code
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
composer create-project "laravel/laravel=12.*" (プロジェクト名)
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
        "php": "^8.2",
        "guzzlehttp/guzzle": "^7.10",
        "laravel/framework": "^12.0",
        "laravel/sanctum": "^4.2",
        "laravel/tinker": "^2.10",
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

> **ブランチ（2026-11-24 に決定。exment と exment-saas で同じ）**
>
> | ブランチ | 中身 | pull request |
> |---|---|---|
> | `develop` | 次の版（今は 7.3）を作るところ。GitHub で最初に出るブランチ | **新しい機能・大きな変更は、ここに出す**（ふつうはここ） |
> | `7.2` | 7.2.x の直し | 7.2.x で出す直しは、**`7.2` と `develop` に 1 つずつ**（同じ中身の PR を 2 つ） |
> | `master` | **出した版**（exment はリリースずみの版、exment-saas は本番の版） | 直接は出さない。リリースのときに、まとめ役が入れる |
> | `7.1`・`7.0`・`6.2` | 古い版の保守（脆弱性の直しだけ。6.2 は 2027-03-31 まで） | まとめ役が、必要なものだけ cherry-pick する |
> | `feature/XXX`・`fix/XXX` | 作業のブランチ | 上のどれかに出す |
>
> **リリースの流れ（両方同じ）**
> 1. 7.2.x は `7.2` で、新しい版（7.3.0 など）は `develop` で、最新の中身でテストをまとめて 1 回通す
> 2. タグを付けて出す（exment は `v7.2.4`、exment-saas は `saas.v7.2.4`）
> 3. 出した中身を `master` に入れる
> 4. 新しい版（7.3.0 など）を出したら、そのタグの位置で版のブランチ（`7.3`）を作る（`git push origin v7.3.0^{commit}:refs/heads/7.3`）
> 5. `develop` に入っていて出したくないものは、設定で切り替えられるようにして**初期値をオフ**にするか（`Features::register`）、タグの前に `git revert` する
>
> **exment-saas（SaaS）**
> - 版は本体と同じ番号にする：本体 7.2.4 を取り込んだ SaaS は `saas.v7.2.4`、その上の SaaS だけの直しは `saas.v7.2.4.1`・`saas.v7.2.4.2`…（7.2.1〜7.2.3 のころの `saas.v7.2.0.N` はやめる）
> - 本体を出したら、取り込みの PR を作る。AI の機能（AI アシスタント・チャットボット・AI-OCR）は SaaS にしかないので、**AI チームがステージング（`stg.*`）で確かめてから**本番に出す
>
> **PR の出し方（GitHub のテストの順番待ちを減らすため）**
> - 小さな直しは、話題ごとにまとめる。1 つの直しごとに PR を作らない
> - テストが動いているあいだは、なるべく push しない（push するたびに、始めからやり直しになる）
> - ネットワークで落ちたジョブ（タイムアウト・ダウンロードの失敗）は、落ちたジョブだけ動かし直す
> - マージする前に、ベースが進んでいたら新しい中身に合わせて、テストを通してから入れる。さわるファイルが重ならない PR は、そのまま入れてよい
>
> 脆弱性の修正は、公開の Issue・pull request にせず、SECURITY.md の方法で扱います（GitHub の Security Advisory の非公開のフォークで直す）。
>
> **切り替えの前**：exment の `master` は、2026-11-24 まで次の版（7.3 向け）の開発に使っていた。切り替えのときに、そこから `develop` を作り、`master` は出した版（v7.2.4）にもどす。`dev-master` を使っている人は、中身が 7.2.4 に変わる。
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

- exceedone/exmentの、上の「今の運用」の表のブランチ（新しい機能は **develop**、7.2.x で出す直しは **7.2** と **develop**）を対象に、pull requestを実行します。ブランチ名は、機能追加は `feature/XXX`、不具合対応は `fix/XXX` としてください。  
コメントは、可能な限り記載してください。
