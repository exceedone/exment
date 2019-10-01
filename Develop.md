# Set up developing Exment
> NOW WE ARE WRITING THIS PAGE.

This page explains how to set up developing Exment engine.

## First
- We suggest using Visual Studio Code for developing.
- This page is beta version. If you cannot set up, please write issue.
- Please install first.
    - Visual Studio Code
    - git
    - composer
    - node.js

## Fork repository
- Access [https://github.com/exceedone/exment] and click "fork" right-top button on page.

- Fork Exment to your repository. And copy URL of your Exment repository.  
(ex. https://github.com/hirossyi73/exment)

## Create Laravel Project
- Please execute this command on any path.

~~~
composer create-project laravel/laravel  --prefer-dist (project name) "5.6.*"
cd (project name)
~~~

- Create "packages" directory.

~~~
mkdir packages
cd packages
~~~

- Create "exceedone" directory.  

~~~
mkdir exceedone
cd exceedone
~~~

- Clone your repository.
(ex. https://github.com/hirossyi73/exment.git)

~~~
git clone https://github.com/hirossyi73/exment.git
~~~

- rewrite composer.json on **project root**. *I write comments, but please remove them if you rewrite your composer.json.

~~~
    "require": {
        "php": "^7.1.3",
        "fideloper/proxy": "^4.0",
        "laravel/framework": "5.6.*",
        "laravel/tinker": "^1.0",
        // Add this line
        "exceedone/exment": "dev-master"
    },

    "autoload": {
        "classmap": [
            "database/seeds",
            "database/factories"
        ],
        "psr-4": {
            "App\\": "app/",
            // Add this line
            "Exceedone\\Exment\\": "packages/exceedone/exment/src/"
        }
    },

    // Add this block
    "repositories": [
        {
            "type": "path",
            "url": "packages/exceedone/exment",
            "options": {
                "symlink": true
            }
        }
    ]
~~~

- Execute this command on project root directory.

~~~
composer update
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
~~~

- Access exment website. And set up Exment.  
(ex. http://localhost/admin)


## GitHub

### Brunch
GitHub brunch is operated as follows.  
*Reference(Japanese site)： [Gitのブランチモデルについて](https://qiita.com/okuderap/items/0b57830d2f56d1d51692)

| GitHub Brunch Name | Derived from | Explain |
| ------------------ | -------------| ------------- |
| `master` | - | The current stable version. Manage source code at the time of release. / 現在の安定版です。リリースした時点でのソースコードを管理します。 |
| `hotfix` | master | This branch is for emergency response when there is a fatal defect in the released source code. After push, merge into develop and master. / リリースしたソースコードで、致命的な不具合があったときに緊急対応を行うためのブランチです。push後、developとmasterにマージします。 |
| `develop` | master | This branch develops the next functions. / 次期機能などの開発を行うブランチです。 |
| `feature` | develop | A branch for each function to be implemented. When development is complete, merge into develop. Example: feature/XXX, feature/YYY / 実装する機能ごとのブランチです。 開発が完了したら、developにマージを行います。 例：feature/XXX, feature/YYY |
| `release` | develop | This is a version for fine-tuning at the time of release after development is completed. Check the operation in this branch, and when completed, merge to master. / developでの開発完了後、リリース時の微調整を行うためのバージョンです。こちらのブランチで動作確認を行い、完了したら、masterにマージを行います。 |

### Before Commit - php-cs-fixer
Before pushing to GitHub, execute php-cs-fixer and format the source code.  

~~~
composer global require friendsofphp/php-cs-fixer #Only execute first
%USERPROFILE%\AppData\Roaming\Composer\vendor\bin # Add user environment. Change "%USERPROFILE%" to machine user name. ex:「C:\Users\XXXX」
php-cs-fixer fix ./vendor/exceedone/exment --rules=no_unused_imports #Remove unused use
php-cs-fixer fix ./vendor/exceedone/exment/src #Fix all source
~~~