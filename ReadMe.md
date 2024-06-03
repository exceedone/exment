<p align="center">
<img src="https://exment.net/docs/img/common/exment_logo_side.png" alt="Exment">
</p>


## For Japanese(日本語)
こちらのサイトにアクセスしてください。  
<a href="https://exment.net" target="_blank">公式サイト</a>  
<a href="https://exment.net/demo-env" target="_blank">デモサイト一覧</a>  
<a href="https://exment.net/docs/#/ja/">マニュアル</a>


## What is Exment?
Exment is open source software for managing information assets on the Web.  
For Web Database, SFA, CRM, Business improvement, ...  
<a href="https://en.exment.net" target="_blank">Official Site</a>  
<a href="https://exment.net/docs/#/">Manual</a>
<a href="https://demo.exment.net/admin" target="_blank">Demo site</a>  
Then please enter  
Usercode：admin  
Password：adminadmin


## Functions
- Dashboard
- Data registration from the screen
- Custom table/Custom column creation
- Import/export templates (Custom tables/Columns can be used with other Exments)
- Import/export data
- Value calculation function in form (total amount, calculation of tax amount)
- Authorization management
- Organization management
- Menu configuration management
- Search (free word search, search words related to information)
- Mail Template
- API

And more and more and more functions....

## Operating environment
### Server
- PHP 7.3.0 or upper
- MySQL 5.7.8 or upper and less than 8.0.0, or MariaDB 10.2.7 or upper
- Laravel8.X

### Support Browser
- Google Chrome
- Microsoft Edge

## Screen Shot

### List of Data
![List of Data](https://exment.net/wp-content/uploads/2020/03/list_of_data.gif)  
  
### Data Edit
![Data Edit](https://exment.net/wp-content/uploads/2020/03/list_edit.gif)  
  
### Search
![Search](https://exment.net/wp-content/uploads/2020/03/search.gif)

### Dashboard
![Dashboard](https://exment.net/wp-content/uploads/2020/03/dashboard.gif)

### Calendar
![Calendar](https://exment.net/wp-content/uploads/2019/05/capture_7_calendarview.png)


## QuickStart
> You need set up LAMP and install composer.

- Create Laravel project using composer. ("exment" is project name.)

~~~
composer create-project "laravel/laravel=10.*" exment
cd exment
~~~

- Require exceedone/exment using composer.

~~~
composer require exceedone/exment
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
~~~

- Edit .env file.

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
APP_TIMEZONE=America/Santiago
APP_LOCALE=en
~~~

- (Recommend) Add error page. Open "app/Exceptions/Handler.php", and modify "render" function.

~~~ php
    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $exception
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $exception)
    {
        // Modify
        return \Exment::error($request, $exception, function($request, $exception){
            return parent::render($request, $exception);
        });
    }
~~~


- Access exment page.  
URL is http://127.0.0.1:8000/admin

~~~
php artisan serve
~~~

# Community
Exment does not currently have a community forum. I am taking advantage of issues. However, we may use some other platform in the future. If you have detailed knowledge, I would be grateful if you could give me a recommendation.  
現在、Exmentではコミュニティ・フォーラムを用意していません。issueを活用しています。  ただし、将来的に何か他のプラットフォームを使うかもしれません。詳しい知見をお持ちの方は、推奨を教えてください。

## Pull Request
Pull Requests are always welcome. Currently, I'd be happy if you actually implemented the function rather than requesting a new function.  
Pull Requestはいつでも大歓迎です。現在は、新しい機能の要望よりも、その機能を実際に実装していただいた方が、嬉しいです。


# Other pages
Setup for Develop [En](document/en/Develop.md) / 開発方法 [日本語](document/ja/Develop.md)  
Setup for Test [En](document/en/Test.md) / テスト実行方法 [日本語](document/ja/Test.md)  


# issues
Please write issues using English or Japanese.  / issuesには英語または日本語で記載してください。


# Other repositories

- **[laravel-admin](https://github.com/exceedone/laravel-admin)**  
Based Exment's framework. Forked from [z-song/laravel-admin](https://github.com/z-song/laravel-admin).

- **[Manual](https://github.com/exceedone/exment-manual)**  
For exment manual page.

- **[Update Batch](https://github.com/exment-git/batch-update)**  
Update batch.

- **[Auto Composer](https://github.com/exment-git/auto-composer)**  
Call auto composer.

- **[Docker](https://github.com/exment-git/docker-exment)**  
Exment dockers.

- **[Plugin Sample](https://github.com/exment-git/plugin-sample)**  
Exment plugin's samples.

- **[Spout](https://github.com/exment-git/spout)**  
Excel import and export library.  
Forked from [box/spout](https://github.com/box/spout). We forked because box/spout is archived.

