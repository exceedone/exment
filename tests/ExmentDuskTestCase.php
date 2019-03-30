<?php

namespace Exceedone\Exment\Tests;

use Tests\DuskTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

abstract class ExmentDuskTestCase extends DuskTestCase
{
    static $databaseSetup = false;
    
    use DatabaseMigrations;
    use ExmentTestTrait {
        ExmentTestTrait::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function setUp()
    {
        parent::setUp();

        if (!$this->app) {
            $this->refreshApplication();
        }

        $this->setUpExment();
    }
}
