<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use Exceedone\Exment\Tests\Unit\UnitTestBase;

/**
 * Pin kết quả giải conflict config/exment.php khi merge feature/line-integration
 * vào feature/laravel-12: block của CẢ 2 nhánh phải cùng tồn tại.
 */
class LineConfigMergeTest extends UnitTestBase
{
    public function testLineBlockSurvivedMerge()
    {
        $this->assertSame(10, (int) config('exment.line.link_code_ttl_minutes'));
        $this->assertSame(5, (int) config('exment.line.link_max_attempts'));
        $this->assertSame('https://api.line.me', config('exment.line.api_base'));
    }

    public function testSafetyCheckBlockSurvivedMerge()
    {
        $this->assertSame('https://api.p2pquake.net/v2/history', config('exment.safety_check.feed_url'));
        $this->assertSame(10, (int) config('exment.safety_check.feed_limit'));
    }

    public function testLaravel12OperationLogBlockSurvivedMerge()
    {
        $this->assertIsArray(config('exment.operation_log'));
        $this->assertNotEmpty(config('exment.operation_log'));
    }
}
