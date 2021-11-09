# 開発環境セットアップ
このページでは、Exment開発環境のセットアップ方法について記載します。

## はじめに
- Visual Studio Codeによる開発を推奨しています。また、この記事はWindows向けに作成しています。Mac端末だと相違点がある可能性がありますが、ご了承ください。
- このページは作成中です。もしセットアップがうまくいかない場合、issueに記載してください。
- はじめに、これらのアプリケーションをインストールしてください。
    - Visual Studio Code
    - git
    - composer
    - node.js

## リポジトリのフォーク
- GitHubの [https://github.com/exceedone/exment] にアクセスし、ページ右上の「Fork」ボタンをクリックしてください。

- Exmentをあなたのリポジトリにフォークしてください。また、あなたのリポジトリをコピーしてください。  
(ex. https://github.com/hirossyi73/exment。以後、あなたのGitHubユーザー名・オーナー名を、"hirossyi73"として記載しています。)

## Laravelのプロジェクト作成
- Laravelのプロジェクトを作成します。

~~~
composer create-project "laravel/laravel=6.*" (プロジェクト名)
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

- あなたのリポジトリをクローンします。
(ex. https://github.com/hirossyi73/exment.git)

~~~
git clone https://github.com/hirossyi73/exment.git
~~~

- Laravelプロジェクトフォルダ直下の、composer.jsonを書き換えます。
**便宜上、下記の記述にコメントを記載していますが、実際はコメントを削除してください。**

~~~
    "require": {
        "php": "^7.1.3",
        "fideloper/proxy": "^4.0",
        "laravel/framework": "6.*",
        "laravel/tinker": "^1.0",
        // 行追加
        "exceedone/exment": "dev-master"
    },

    "autoload": {
        "classmap": [
            "database/seeds",
            "database/factories"
        ],
        "psr-4": {
            "App\\": "app/",
            // 行追加
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

- tsconfig.jsonを開き、10行目を、自分のオーナー名に編集してください。

```
  "include": [
    "packages/hirossyi73/exment/src/Web/ts/*.ts"
  ]
```


- 以下のコマンドを実施し、npmパッケージをインストールしてください。

~~~
npm install -g typescript
npm install @types/node @types/jquery @types/jqueryui @types/jquery.pjax @types/bootstrap @types/icheck @types/select2
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

- プロジェクトフォルダ直下の"tasks.json"ファイルを開き、プロジェクトルートフォルダの".vscode"フォルダに配置します。(".vscode"フォルダがなければ、作成してください)
[tasks.json](https://exment.net/downloads/develop/tasks.json)

- あなたが "exment" パッケージ内の.tsファイルを編集した後、コンパイルしたい場合、"Ctrl + Shift + B" コマンドをVSCodeで実行してください。（おそらくMacでは、"Command + Shift + B"です）  
コンパイルされたjsファイルが、 packages/hirossyi73/exment/public/vendor/exment/js に配置されます。

- コンパイルしたjsファイルをWebに反映したい場合、以下のコマンドを実行してください。

~~~
php artisan exment:publish
~~~

## Sass設定

- VsCodeの拡張機能"EasySass"をインストールしてください。  
[EasySass](https://marketplace.visualstudio.com/items?itemName=spook.easysass)

- VSCodeの設定を開き、EasySassの設定を開きます。  
"Target Dir"の設定を開き、"packages/hirossyi73/exment/public/vendor/exment/css"をセットします。

- あなたが.scssファイルを編集し、保存時、自動的に.cssファイルが packages/hirossyi73/exment/public/vendor/exment/cssに配置されます。

- cssファイルをWebに反映したい場合、以下のコマンドを実行してください。

~~~
php artisan exment:publish
~~~

## GitHub

### ブランチ
GitHubのブランチを、以下のように運用しています。  
[Gitのブランチモデルについて](https://qiita.com/okuderap/items/0b57830d2f56d1d51692)

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

# あなたの環境変数を追加してください。"%USERPROFILE%"は、あなたのマシン名です。 ex:「C:\Users\XXXX」
%USERPROFILE%\AppData\Roaming\Composer\vendor\bin 
```

#### ソース整形
~~~
# 不要な"use"を削除
php-cs-fixer fix ./vendor/exceedone/exment --rules=no_unused_imports 
# すべてのソースを整形
php-cs-fixer fix
~~~


### 本体にコミット
本体のexceedone/exmentにコミットを行う場合、以下の手順を実施してください。  

- GitHubのpull requestを実行します。  

- exceedone/exmentのブランチhotfix(不具合対応)、もしくはdevelop(機能追加)を対象に、pull requestを実行します。  
コメントは、可能な限り記載してください。
