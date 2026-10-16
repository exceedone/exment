<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * @method void beginDatabaseTransaction()
 */
abstract class UnitTestBase extends TestCase
{
    use TestTrait;

    /**
     * Boot the testing helper traits.
     *
     * @return array<mixed>
     */
    protected function setUpTraits()
    {
        $uses = parent::setUpTraits();

        if (isset($uses[DatabaseTransactions::class])) {
            $this->beginDatabaseTransaction();
        }

        return $uses;
    }
}
