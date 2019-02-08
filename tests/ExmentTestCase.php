<?php

namespace Exceedone\Exment\Tests;

use Tests\TestCase;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Contracts\Console\Kernel;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Middleware\Morph;

abstract class ExmentTestCase extends TestCase
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
