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

## Operating environment
### Server
- PHP 7.1.3 or upper
- MySQL 5.7.8 or upper, or MariaDB 10.2.7 or upper
- Laravel5.6

### Support Browser
- Google Chrome
- Microsoft Edge

## Screen Shot
------------

![Custom Table and Column](https://exment.net/docs/img/common/screenshot_table_and_column.jpg)  
  
![Custom Form and view](https://exment.net/docs/img/common/screenshot_form_and_view.jpg)  
  
![Custom Data, Dashboard and Template](https://exment.net/docs/img/common/screenshot_data_dashboard_template.jpg)

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



------------
# Develop
If you'd like to help with the development of Exment, please visit this site and see how to set it up.  
[Develop Setup](Develop.md)