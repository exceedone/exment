<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Console\BatchCommand;

/**
 * The exit code of exment:batch from what a batch plugin returns.
 */
class BatchExitCodeTest extends UnitTestBase
{
    public function testTrueAndNothingAreASuccess(): void
    {
        $this->assertSame(0, BatchCommand::exitCode(true));
        $this->assertSame(0, BatchCommand::exitCode(null));
        $this->assertSame(0, BatchCommand::exitCode(0));
    }

    public function testFalseAndANumberAreKept(): void
    {
        $this->assertSame(1, BatchCommand::exitCode(false));
        $this->assertSame(1, BatchCommand::exitCode(1));
        $this->assertSame(2, BatchCommand::exitCode(2));
    }
}
