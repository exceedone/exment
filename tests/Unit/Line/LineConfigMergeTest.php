<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use Exceedone\Exment\Tests\Unit\UnitTestBase;

/**
 * Pin kết quả giải conflict config/exment.php khi merge feature/line-integration
 * vào feature/laravel-12: block của CẢ 2 nhánh phải cùng tồn tại.
 *
 * Đọc thẳng file config của package, không qua config(): app đã publish
 * config/exment.php thì config() vẫn có block line/safety_check dù file package mất block.
 */
class LineConfigMergeTest extends UnitTestBase
{
    /**
     * @return array<string, mixed>
     */
    private function packageConfig(): array
    {
        return require dirname(__DIR__, 3) . '/config/exment.php';
    }

    public function testLineBlockSurvivedMerge(): void
    {
        $line = $this->packageConfig()['line'] ?? null;

        $this->assertIsArray($line, 'package config/exment.php lost the "line" block');
        $this->assertSame(10, (int) $line['link_code_ttl_minutes']);
        $this->assertSame(5, (int) $line['link_max_attempts']);
        $this->assertSame('https://api.line.me', $line['api_base']);
    }

    public function testSafetyCheckBlockSurvivedMerge(): void
    {
        $safety = $this->packageConfig()['safety_check'] ?? null;

        $this->assertIsArray($safety, 'package config/exment.php lost the "safety_check" block');
        $this->assertSame('https://api.p2pquake.net/v2/history', $safety['feed_url']);
        $this->assertSame(10, (int) $safety['feed_limit']);
    }

    public function testLaravel12OperationLogBlockSurvivedMerge(): void
    {
        $operationLog = $this->packageConfig()['operation_log'] ?? null;

        $this->assertIsArray($operationLog, 'package config/exment.php lost the "operation_log" block');
        $this->assertIsArray($operationLog['mask_columns'] ?? null);
        $this->assertNotEmpty($operationLog['mask_columns']);
    }
}
