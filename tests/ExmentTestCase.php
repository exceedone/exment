<?php

namespace Exceedone\Exment\Tests;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

abstract class ExmentTestCase extends TestCase
{
    use DatabaseMigrations;
    use ExmentTestTrait {
        ExmentTestTrait::runDatabaseMigrations insteadof DatabaseMigrations;
    }

    /**
     * @var bool $databaseSetup
     */
    public static $databaseSetup = false;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        /** @phpstan-ignore-next-line $this->app is always Application */
        if (!$this->app) {
            $this->refreshApplication();
        }

        $this->setUpExment();
    }
}
