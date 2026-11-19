<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use PHPUnit\Framework\TestCase;

/**
 * [Filter v1] Generate the filter fields f_date (created_at) + f_user (created_user_id).
 */
class FilterFieldsTest extends TestCase
{
    public function testIntTimestampAndUser(): void
    {
        $out = DocumentMapper::filterFieldsV1(1719791999, 5);

        $this->assertSame(1719791999, $out['f_date']);
        $this->assertSame([5], $out['f_user']);
    }

    public function testDatetimeObjectToTimestamp(): void
    {
        $dt = new \DateTimeImmutable('2026-06-29T00:00:00+00:00');
        $out = DocumentMapper::filterFieldsV1($dt, 7);

        $this->assertSame($dt->getTimestamp(), $out['f_date']);
        $this->assertSame([7], $out['f_user']);
    }

    public function testNullCreatedAtOmitsFDate(): void
    {
        $out = DocumentMapper::filterFieldsV1(null, 3);

        $this->assertArrayNotHasKey('f_date', $out);
        $this->assertSame([3], $out['f_user']);
    }

    public function testNullUserGivesEmptyArray(): void
    {
        $out = DocumentMapper::filterFieldsV1(1719791999, null);

        $this->assertSame([], $out['f_user']);
    }

    public function testUserIdCastToInt(): void
    {
        $out = DocumentMapper::filterFieldsV1(null, '42');

        $this->assertSame([42], $out['f_user']);
    }
}
