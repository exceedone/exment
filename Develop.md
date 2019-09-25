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
*I write comments, but please remove them if you rewrite your composer.json.

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

- Execute this command.

~~~
composer update
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
~~~

- Access exment website. And set up Exment.  
(ex. http://localhost/admin)
