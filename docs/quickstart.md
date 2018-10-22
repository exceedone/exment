# Quick start
This is the procedure required to start Exment.
* The introduction of composer is necessary.

## Laravel installation (Project creation)
- At the command line, execute the following command.

~~~
composer create-project "laravel/laravel=5.5.*" (Project Name)
cd (Project Name)
~~~

- Open ".env" and change the database string to your own MySQL setting.  
*MySQL can be used only with json type 5.7 or higher.

## Command execution
- Execute the following command.

~~~
composer require exceedone/exment=dev-master
php artisan vendor:publish --provider="Encore\Admin\AdminServiceProvider"
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=public --force
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=lang --force
~~~

## Change config

- Open "config \ database.php" and modify the value of the key "mysql" as follows.

~~~ php
'mysql' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'forge'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    // Exment Edit------s
    //'strict' => true,
    'strict'    => false,
    'options'   => [
        PDO::ATTR_CASE => PDO::CASE_LOWER,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => true,
        PDO::ATTR_EMULATE_PREPARES => true,
    ],
    // Exment Edit------e
    'engine' => null,
],

~~~

- Open "config \ admin.php" and modify the key "auth.providers.admin" as follows.

~~~ php
    'auth' => [
        'providers' => [
            'admin' => [
                // Exment Edit------s
                // 'driver' => 'eloquent',
                //'model'  => Encore\Admin\Auth\Database\Administrator::class,
                'driver' => 'exment-auth',
                // Exment Edit------e
            ],
        ],
    ],
~~~


- Open "config \ app.php" and add the following line to the key "providers".  

~~~ php

'providers' => [
    ...
    // Exment Add------s
    Collective\Html\HtmlServiceProvider::class,
    Exceedone\Exment\ExmentServiceProvider::class,
    Exceedone\Exment\Providers\PasswordResetServiceProvider::class,
    // Exment Edit------e
]

'aliases' => [
    ...
    // Exment Add------s
    'Uuid' => Webpatser\Uuid\Uuid::class,
    'Form' => Collective\Html\FormFacade::class,
    'Html' => Collective\Html\HtmlFacade::class,
    // Exment Edit------e
]

~~~

- If you want to change the language and timezone, open "config\app.php" and correct the following lines.

~~~ php

    // 'timezone' => 'UTC',
    'timezone' => 'America/Chicago',

    //'locale' => 'en',
     'locale' => 'es',

~~~

## Command execution
- Execute the following command.

~~~
php artisan admin:install
php artisan exment:install
~~~

