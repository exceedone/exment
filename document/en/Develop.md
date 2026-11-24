# Set up developing Exment
This page explains how to set up developing Exment engine.

## First
- We suggest using Visual Studio Code for developing.
- This page is beta version. If you cannot set up, please write issue.
- The following applications must be installed in advance.
    - Visual Studio Code
    - git
    - composer
    - node.js(npm)
- Please prepare the WEB server, database, etc. required to operate Exment locally according to your environment.[Reference site](https://exment.net/docs/#/server)  

## Fork repository
- Access [https://github.com/exceedone/exment] and click "fork" right-top button on page.

- Fork Exment to your repository. And copy URL of your Exment repository.  
(ex. https://github.com/hirossyi73/exment)

## Create Laravel Project
- Please execute this command on any path.

~~~
composer create-project "laravel/laravel=12.*" (project name)
cd (project name)
~~~

- Create "packages" directory.

~~~
mkdir packages
cd packages
~~~

- Create a directory with your GitHub username.  
(ex. hirossyi73)

~~~
mkdir hirossyi73
cd hirossyi73
~~~

- Execute the following command.
~~~
composer config --no-plugins allow-plugins.kylekatarnls/update-helper true
composer require psr/simple-cache=^2.0.0
~~~


- Clone your repository.
(ex. https://github.com/hirossyi73/exment.git)

~~~
git clone https://github.com/hirossyi73/exment.git
~~~
- (Optional)Clone your exment-admin-core repository.
(ex. https://github.com/hirossyi73/exment-admin-core.git)

~~~
git clone https://github.com/hirossyi73/exment-admin-core.git
~~~
- rewrite composer.json on project root directory.  
***When you edit composer.json, please remove comments. We cannot add comments on json file.**

~~~
    "require": {
        "php": "^8.2",
        "guzzlehttp/guzzle": "^7.10",
        "laravel/framework": "^12.0",
        "laravel/sanctum": "^4.2",
        "laravel/tinker": "^2.10",
        "psr/simple-cache": "2.0.0",
        // Add this line
        "exceedone/exment": "dev-master"
        // Optional
        ,"exceedone/exment-admin-core": "dev-master"
    },

    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/",
            // Add this line.Please rewrite the "hirossyi73" part
            "Exceedone\\Exment\\": "packages/hirossyi73/exment/src/"
        }
    },

    // Add this block.Please rewrite the "hirossyi73" part
    "repositories": [
        {
            "type": "path",
            "url": "packages/hirossyi73/exment",
            "options": {
                "symlink": true
            }
        }
        // Optional
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

- Execute this command on project root directory.

~~~
composer update
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
php artisan passport:keys
~~~

- Access exment website. And set up Exment.  
(ex. http://localhost/admin)


## Set up Typescript

- Download tsconfig.json from this site and put on root project.  
[tsconfig.json](https://exment.net/downloads/develop/tsconfig.json)

- Open tsconfig.json and edit lines 5 and 10 to your own owner name.  

```
  "compilerOptions": {
    ...
    "outDir": "./packages/hirossyi73/exment/public/vendor/exment/js",
    ...
  },
  "include": [
    "packages/hirossyi73/exment/src/Web/ts/*.ts"
  ]
```

- Install npm packages on root project.  

~~~
npm init
# Please input 'Enter' and finish install.
npm install -g typescript
npm install @types/node @types/jquery @types/jqueryui @types/jquery.pjax @types/bootstrap @types/icheck @types/select2 @types/jquery.validation
~~~

- Download *.d.ts files that not contains npm packages.  
And set *.d.ts files to node_modules/@types/(package name folder - Please create folder).  
[bignumber/index.d.ts](https://exment.net/downloads/develop/bignumber/index.d.ts)   
[exment/index.d.ts](https://exment.net/downloads/develop/exment/index.d.ts)

- Open packages.json's dependencies block and append downloaded files.

~~~
"dependencies": {
    "@types/bignumber": "^1.0.0",
    "@types/exment": "^1.0.0",
}
~~~

- Copy the "tasks.json" file directly under the project folder to the ".vscode" folder in the project root folder.  
    - If the "tasks.json" file does not exist, download it from [here](https://exment.net/downloads/develop/tasks.json).  
    - If the ".vscode" folder does not exist, create it yourself.  

- In index.d.ts, there is a conflict between bootstrap and jquery definition files, so fix them individually.
Open node_modules\@types\bootstrap\index.d.ts and modify it as follows: *Near the 27th line.

``` typescript
declare global {
    interface JQuery {
        alert: Alert.jQueryInterface;
        // Comment
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
        // Comment
        //[Tooltip.NAME]: Tooltip.jQueryInterface;
    }
}
```

- If you update *.ts file in "exment" package and you want to compile, please execute this command "Ctrl + Shift + B" on VSCode.  
Update .js file in packages/hirossyi73/exment/public/vendor/exment/js.

- If you want to publish js file for web, please execute this command on project root directory.

~~~
php artisan exment:publish
~~~

## Set up Sass

- Install VsCode plugin "EasySass".
[EasySass](https://marketplace.visualstudio.com/items?itemName=spook.easysass)

- Open the VS Code settings page. Select Extensions → Easy Sass from the submenu.  
In the "Target Dir" field, enter "packages/hirossyi73/exment/public/vendor/exment/css".  
    - Please convert the "hirossyi73" part to your own owner name.  

- When you edit .scss file and save, update .css file to packages/hirossyi73/exment/public/vendor/exment/css.

- If you want to publish css file, please execute this command on project root directory.

~~~
php artisan exment:publish
~~~

## others

- By setting the symbolic link, the files of the Exment package exist in both the 'packages' folder and the 'vendor' folder.  
When modifying the program, please edit the 'packages' folder.

## GitHub

### Brunch
GitHub brunch is operated as follows.  
*Reference(Japanese site)： [Gitのブランチモデルについて](https://qiita.com/okuderap/items/0b57830d2f56d1d51692)

> **Branches (decided on 2026-11-24, the same in exment and exment-saas)**
>
> There are only two kinds of branches.
>
> | Branch | What it is |
> |---|---|
> | `develop/<version>` (e.g. `develop/7.2.6`, `develop/7.3.0`) | Collects what ships in that version. **Send every pull request to the `develop/<version>` of the version it should ship in** |
> | `master` | **What has been released** (exment: the released version; exment-saas: production). Not directly |
>
> - Send PRs from work branches (`feature/XXX`, `fix/XXX`) to `develop/<version>`
> - Large features (e.g. for 7.3) go to `develop/7.3.0`, fixes for 7.2.x to `develop/7.2.<n>`. When unsure, ask the maintainers
> - A change to documents only (`document/`) may go to `master`
> - Old versions (`7.1`, `7.0`, `6.2`) get security fixes only, cherry-picked by the maintainers (6.2 until 2027-03-31)
>
> **Each time (the same in exment and exment-saas)**
> 1. After a version ships, make the next `develop/<version>` (e.g. `develop/7.2.6` after 7.2.5)
> 2. Merge what ships in it through PRs
> 3. Before the release, ask the AI team to "check `develop/<version>`". The AI features (AI assistant, chatbot, AI-OCR) exist only in SaaS, so they check them on staging (`stg.*`). **Nothing else goes into a `develop/<version>` while it is being checked**
> 4. When it is OK, run the tests once more on the latest content, tag and release (exment `v7.2.6`, exment-saas `saas.v7.2.6`), merge into `master`, and delete `develop/<version>`
> 5. When two `develop/<version>` branches exist (e.g. `develop/7.2.6` and `develop/7.3.0`), the maintainers bring the fixes of the one released first into the other
>
> **exment-saas versions** follow core: SaaS with core 7.2.4 is `saas.v7.2.4`; SaaS-only fixes on it are `saas.v7.2.4.1`, …
>
> **How to send pull requests (to keep the GitHub test queue short)**
> - Bundle small fixes by topic, not one PR per fix
> - Push as little as possible while the tests run
> - When a job fails because of the network, rerun only the failed job
>
> Security fixes are not handled in public issues or pull requests; see SECURITY.md (they are fixed in the private fork of a GitHub Security Advisory).
>
> **The switch on 2026-11-24**: `master` of exment was used for the next version (7.3) until then. Its content moved to `develop/7.3.0`, and `master` went back to the released version (v7.2.4); the old `master` is kept as `backup/master-2026-11-24`. The plain `develop` and `7.2` branches are not used. The same day, `release/<version>` was renamed `develop/<version>`; only 7.2.5 keeps `release/7.2.5` until it ships, because the AI team was testing it. Users of `dev-master` now get 7.2.4.
>
> The table below describes the previous branch model.

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

# Add the following path to your environment variables. ("%USERPROFILE%" is a variable that points to your user directory. ex:「C:\Users\XXXX」)
%USERPROFILE%\AppData\Roaming\Composer\vendor\bin 
~~~

#### Forcat Source

```
#Remove unused use
php-cs-fixer fix ./packages/hirossyi73/exment --rules=no_unused_imports
#Fix all source
php-cs-fixer fix ./packages/hirossyi73/exment
```


### Commit to Core Exment
If you want to commit to exceedone/exment of the main unit, follow the procedure below. PR is welcome.  

- Exment pull request on GitHub.  

- Execute a pull request for the **develop/<version>** of the version it should ship in (see "Branches" above) of exceedone/exment. Please name your branch `feature/XXX` for new functions or `fix/XXX` for bug fixes.  
Please include comments as much as possible.

