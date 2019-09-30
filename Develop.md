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
    - npm

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

- Create your github owner name's directory.  
(ex. hirossyi73)

~~~
mkdir hirossyi73
cd hirossyi73
~~~

- Clone your repository.
(ex. https://github.com/hirossyi73/exment.git)

~~~
git clone https://github.com/hirossyi73/exment.git
~~~

- rewrite composer.json on **project root**.  
- **I write comments, but please remove them if you rewrite your composer.json.**

~~~ json
    "require": {
        "php": "^7.1.3",
        "fideloper/proxy": "^4.0",
        "laravel/framework": "5.6.*",
        "laravel/tinker": "^1.0",
        // Add this line
        "hirossyi73/exment": "dev-master"
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
            "url": "packages/hirossyi73/exment",
            "options": {
                "symlink": true
            }
        }
    ]
~~~

- Execute this command on root directory.

~~~
composer update
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
~~~

- Access exment website. And set up Exment.  
(ex. http://localhost/admin)

- If you want to develop exment, please edit on folder "packages/(owner name)/exment".

## Set up Typescript

- Download ts.config from this site and put on root project.  
[ts.config](https://exment.net/downloads/develop/tsconfig.json)

- Open ts.config and edit path.

~~~
"compilerOptions": {
    // "outDir": "./packages/exceedone/exment/public/vendor/exment/js",
    // ↓
    "outDir": "./packages/(owner name)/exment/public/vendor/exment/js",
  },
  "include": [
    // "packages/exceedone/exment/src/Web/ts/*.ts"
    // ↓
    "packages/hirossyi73/exment/src/Web/ts/*.ts"
  ]
~~~

- Install npm packages on root project.  

~~~
npm install -g typescript
npm install @types/jquery @types/jqueryui @types/jquery.pjax @types/bootstrap @types/icheck @types/select2
~~~

- Download d.ts file that not contains npm packages.  
And set d.ts file to node_modules/@types/(package name folder - Please create folder).  
[bignumber/index.d.ts](https://exment.net/downloads/develop/bignumber/index.d.ts)  
[exment/index.d.ts](https://exment.net/downloads/develop/exment/index.d.ts)

- Open packages.json's dependencies block and append downloaded files.

~~~
"dependencies": {
    "@types/bignumber": "^1.0.0",
    "@types/exment": "^1.0.0",
}
~~~

- Download tasks.json file and set ".vscode" folder.  
[tasks.json](https://exment.net/downloads/develop/.vscode/tasks.json)

- If you edit *.ts file and you want to compile, please this command.  Update .js file to packages/(owner name)/public/vendor/exment/js.
Ctrl + Shift + B

- If you want to publish js file, please execute this command on project root directory.

~~~
php artisan exment:publish
~~~

## Set up Sass

- Install VsCode plugin "EasySass".
[EasySass](https://marketplace.visualstudio.com/items?itemName=spook.easysass)

- Open VsCode setting and open EasySass setting.  
Set "Target Dir" setting "packages/(owner name)/exment/public/vendor/exment/css".  

- When you edit .scss file and save, update .css file to packages/(owner name)/public/vendor/exment/css.

- If you want to publish css file, please execute this command on project root directory.

~~~
php artisan exment:publish
~~~

