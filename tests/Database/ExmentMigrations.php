<?php

namespace Exceedone\Exment\Tests\Database;

use Illuminate\Foundation\Testing\DatabaseMigrations;

trait ExmentMigrations
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $this->artisan('migrate:fresh');
        $this->artisan('exment:install');
    }
}
