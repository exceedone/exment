<?php

namespace Exceedone\Exment\Tests;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

abstract class ExmentTestCase extends TestCase
{
    static $databaseSetup = false;
    
    use DatabaseMigrations;
    use ExmentTestTrait {
        ExmentTestTrait::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    public function setUp() : void
    {
        parent::setUp();

        if (!$this->app) {
            $this->refreshApplication();
        }

        $this->setUpExment();
    }
}
