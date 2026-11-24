<?php

namespace Exceedone\Exment\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Contracts\Console\Kernel;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Middleware\Morph;

trait ExmentTestTrait
{
    use DatabaseMigrations;

    /**
     * @var bool $databaseSetup
     */
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

    /**
     * @return void
     */
    protected function setUpExment()
    {
        \Illuminate\Support\Facades\App::setLocale('en');
    }

    /**
     * Define hooks to migrate the database before and after each test.
     *
     * @return void
     */
    public function runDatabaseMigrations()
    {
        if (!\Illuminate\Support\Facades\App::environment('local', 'testing')) {
            return;
        }

        if (!static::$databaseSetup) {
            $this->dropAllTables();
            \Illuminate\Support\Facades\Artisan::call('migrate');

            System::clearRequestSession();
            Morph::defineMorphMap();

            \Illuminate\Support\Facades\Artisan::call('exment:install');

            static::$databaseSetup = true;

            // $this->beforeApplicationDestroyed(function () {
            //     \Illuminate\Support\Facades\Artisan::call('migrate:reset');
            //     $tables = \Illuminate\Support\Facades\DB::connection()->getDoctrineSchemaManager()->listTableNames();
            //     foreach($tables as $table_name){
            //         \Illuminate\Support\Facades\Schema::dropIfExists($table_name);
            //     }
            // });
        }
    }

    /**
     * @return void
     */
    protected function dropAllTables()
    {
        \Illuminate\Support\Facades\Artisan::call('migrate:reset');
        $tables = \Illuminate\Support\Facades\DB::select('SHOW TABLES');
        $tableKey = array_key_first((array) $tables[0]);
        foreach ($tables as $table) {
            $tableName = $table->$tableKey;
            \Illuminate\Support\Facades\Schema::dropIfExists($tableName);
        }
    }
}
