<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\GlobalSearch\RequestFilters;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Query params are attacker-controlled. `?query[]=x` makes input() return an
 * array, and casting that to string raises E_WARNING - which Laravel's error
 * handler turns into an ErrorException, i.e. a 500 page from a crafted URL.
 */
class RequestStringInputTest extends TestCase
{
    /**
     * @param array<string,mixed> $query
     */
    private function req(array $query): Request
    {
        return Request::create('/admin/search', 'GET', $query);
    }

    public function testScalarIsReturnedAsString(): void
    {
        $this->assertSame('abc', RequestFilters::str($this->req(['query' => 'abc']), 'query'));
        $this->assertSame('12', RequestFilters::str($this->req(['query' => 12]), 'query'));
    }

    public function testArrayFallsBackToTheDefaultInsteadOfCasting(): void
    {
        $this->assertSame('', RequestFilters::str($this->req(['query' => ['a']]), 'query'));
        $this->assertSame('1', RequestFilters::str($this->req(['with_query' => ['a']]), 'with_query', '1'));
    }

    public function testMissingParamUsesTheDefault(): void
    {
        $this->assertSame('', RequestFilters::str($this->req([]), 'query'));
        $this->assertSame('x', RequestFilters::str($this->req([]), 'query', 'x'));
    }

    public function testStrListKeepsScalarsAndDropsEmpties(): void
    {
        $this->assertSame(
            ['a', 'b'],
            RequestFilters::strList($this->req(['tables' => ['a', '', 'b']]), 'tables')
        );
    }

    /**
     * `?tables[][]=x` - the nested entry must be dropped, not stringified.
     */
    public function testStrListDropsNestedArrays(): void
    {
        $this->assertSame(
            ['ok'],
            RequestFilters::strList($this->req(['tables' => ['ok', ['nested']]]), 'tables')
        );
    }

    public function testStrListAcceptsASingleScalar(): void
    {
        $this->assertSame(['a'], RequestFilters::strList($this->req(['tables' => 'a']), 'tables'));
    }

    public function testStrListOnMissingParamIsEmpty(): void
    {
        $this->assertSame([], RequestFilters::strList($this->req([]), 'tables'));
    }
}
