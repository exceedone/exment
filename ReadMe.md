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
- PHP 7.1.3 or upper
- MySQL 5.7.8 or upper and less than 8.0.0, or MariaDB 10.2.7 or upper
- Laravel5.6

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
composer create-project "laravel/laravel=5.6.*" exment
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


# Other pages
[Setup for Develop / 開発方法](Develop.md)  
[Setup for Test / テスト実行方法](Test.md)


# issues
Please write issues using English or Japanese.  / issuesには英語または日本語で記載してください。
