# Quick start
This is the procedure required to start Exment.
* The introduction of composer is necessary.

## Laravel installation (Project creation)
- At the command line, execute the following command.

~~~
composer create-project "laravel/laravel=5.6.*" (Project Name)
cd (Project Name)
~~~

- Open ".env" and change the database string to your own MySQL setting.  
*MySQL can be used only with json type 5.7 or higher.

## Command execution
- Execute the following command.

~~~
composer require exceedone/exment
php artisan vendor:publish --provider="Encore\Admin\AdminServiceProvider"
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider"
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=public --force
php artisan vendor:publish --provider="Exceedone\Exment\ExmentServiceProvider" --tag=lang --force
~~~

## Change config

- Open "config\admin.php" and modify the key "auth.providers.admin" as follows.

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
php artisan passport:keys
php artisan exment:install
~~~

