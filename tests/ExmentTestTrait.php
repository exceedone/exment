<?php

namespace Exceedone\Exment\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Contracts\Console\Kernel;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Middleware\Morph;

trait ExmentTestTrait
{
    use DatabaseMigrations;
    public static $databaseSetup = false;

    /**
     * Boots the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../../../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUpExment()
    {
        \App::setLocale('en');
    }

    /**
     * Define hooks to migrate the database before and after each test.
     *
     * @return void
     */
    public function runDatabaseMigrations()
    {
        if (!\App::environment('local', 'testing')) {
            return;
        }

        if (!static::$databaseSetup) {
            $this->dropAllTables();
            \Artisan::call('migrate');

            System::clearRequestSession();
            Morph::defineMorphMap();

            \Artisan::call('exment:install');

            static::$databaseSetup = true;

            // $this->beforeApplicationDestroyed(function () {
            //     \Artisan::call('migrate:reset');
            //     $tables = \DB::connection()->getDoctrineSchemaManager()->listTableNames();
            //     foreach($tables as $table_name){
            //         \Schema::dropIfExists($table_name);
            //     }
            // });
        }
    }

    protected function dropAllTables()
    {
        \Artisan::call('migrate:reset');
        $tables = \DB::connection()->getDoctrineSchemaManager()->listTableNames();
        foreach ($tables as $table_name) {
            \Schema::dropIfExists($table_name);
        }
    }
}
