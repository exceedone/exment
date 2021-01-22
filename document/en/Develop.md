# Set up developing Exment
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
composer create-project "laravel/laravel=5.6.*" (project name)
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

- rewrite composer.json on project root directory.  
***When you edit composer.json, please remove comments. We cannot add comments on json file.**

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
            "Exceedone\\Exment\\": "packages/hirossyi73/exment/src/"
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


## Set up Typescript

- Download tsconfig.json from this site and put on root project.  
[tsconfig.json](https://exment.net/downloads/develop/tsconfig.json)

- Install npm packages on root project.  

~~~
npm install -g typescript
npm install @types/node @types/jquery @types/jqueryui @types/jquery.pjax @types/bootstrap @types/icheck @types/select2
~~~

- Download *.d.ts files that not contains npm packages.  
And set *.d.ts files to node_modules/@types/(package name folder - Please create folder).  
[bignumber/index.d.ts](https://exment.net/downloads/develop/bignumber/index.d.ts) please  
[exment/index.d.ts](https://exment.net/downloads/develop/exment/index.d.ts)

- Open packages.json's dependencies block and append downloaded files.

~~~
"dependencies": {
    "@types/bignumber": "^1.0.0",
    "@types/exment": "^1.0.0",
}
~~~

- Download tasks.json file and set ".vscode" folder on project root folder. (If doesn't have ".vscode", please create it.)
[tasks.json](https://exment.net/downloads/develop/tasks.json)

- If you update *.ts file in "exment" package and you want to compile, please execute this command "Ctrl + Shift + B" on VSCode.  
Update .js file in packages/exceedone/exment/public/vendor/exment/js.


- If you want to publish js file for web, please execute this command on project root directory.

~~~
php artisan exment:publish
~~~

## Set up Sass

- Install VsCode plugin "EasySass".
[EasySass](https://marketplace.visualstudio.com/items?itemName=spook.easysass)

- Open VsCode setting and open EasySass setting.  
Set "Target Dir" setting "packages/exceedone/exment/public/vendor/exment/css".  

- When you edit .scss file and save, update .css file to packages/exceedone/exment/public/vendor/exment/css.

- If you want to publish css file, please execute this command on project root directory.

~~~
php artisan exment:publish
~~~

## GitHub

### Brunch
GitHub brunch is operated as follows.  
*Reference(Japanese site)： [Gitのブランチモデルについて](https://qiita.com/okuderap/items/0b57830d2f56d1d51692)

| GitHub Brunch Name | Derived from | Explain |
| ------------------ | -------------| ------------- |
| `master` | - | The current stable version. Manage source code at the time of release. |
| `hotfix` | master | This branch is for emergency response when there is a fatal defect in the released source code. After push, merge into develop and master. |
| `hotfixfeature` | hotfix | When a large number of corrections occur due to emergency response when there is a fatal defect, we will create a branch from hotfix and respond. After the fix is ​​complete, merge it into the hotfix. |
| `develop` | master | This branch develops the next functions.  |
| `feature` | develop | A branch for each function to be implemented. When development is complete, merge into develop. Example: feature/XXX, feature/YYY |
| `release` | develop | This is a version for fine-tuning at the time of release after development is completed. Check the operation in this branch, and when completed, merge to master. |
| `test` | master | For adding test. |
| `testfeature` | test | If you want to add test, create this brunch, and add code.  ex.testfeature/XXX |

### Before Commit - php-cs-fixer
Before pushing to GitHub, execute php-cs-fixer and format the source code.  

#### Only First
~~~
composer global require friendsofphp/php-cs-fixer

# Add user environment. Change "%USERPROFILE%" to machine user name. ex:「C:\Users\XXXX」
%USERPROFILE%\AppData\Roaming\Composer\vendor\bin 
~~~

#### Forcat Source

```
#Remove unused use
php-cs-fixer fix ./vendor/exceedone/exment --rules=no_unused_imports
#Fix all source
php-cs-fixer fix ./vendor/exceedone/exment/src 
```


### Commit to Core Exment
If you want to commit to exceedone/exment of the main unit, follow the procedure below. PR is welcome.  

- Exment pull request on GitHub.  

- Execute a pull request for the branch hotfix (fixing a bug) or develop (adding a function) of exceedone/exment.  
Please include comments as much as possible.

