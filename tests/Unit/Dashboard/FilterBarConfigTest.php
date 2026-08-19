<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Services\Dashboard\FilterBarConfig;

/**
 * FilterBarConfig — parsed view of options.filter_bar (the slicer config the targeting
 * feature extends with dims[].targets). DB-free: configs here carry no source_table, so
 * the metadata parent inference has nothing to look up and the EXPLICIT parent rules
 * (column / '-' / junk) are what is exercised; the inference itself is covered by the
 * DB-bound `_evd/tests/t_multifilter.php` section P.
 */
class FilterBarConfigTest extends DashboardUnitTestCase
{
    // ---- construction contract -------------------------------------------------

    public function testFromDashboardNullContracts(): void
    {
        $this->assertNull(FilterBarConfig::fromDashboard(null));
        $this->assertNull(FilterBarConfig::fromDashboard($this->makeDashboard(null)), 'no filter_bar key');
        $this->assertNull(FilterBarConfig::fromDashboard($this->makeDashboard(['source_table' => 'x'])), 'dims missing');
        $this->assertNull(FilterBarConfig::fromDashboard($this->makeDashboard(['dims' => 'x'])), 'dims not an array');
        $this->assertNotNull(FilterBarConfig::fromDashboard($this->makeDashboard(['dims' => []])), 'empty dims IS a config');
    }

    public function testFromArray(): void
    {
        $this->assertNull(FilterBarConfig::fromArray([]));
        $this->assertNull(FilterBarConfig::fromArray(['dims' => null]));
        $cfg = FilterBarConfig::fromArray(['dims' => [['column' => 'a', 'targets' => ['b1']]]]);
        $this->assertSame([['column' => 'a', 'targets' => ['b1']]], $cfg->dims(), 'dims are returned raw (targets untouched)');
    }

    public function testDimsAreReindexed(): void
    {
        $cfg = FilterBarConfig::fromArray(['dims' => [5 => ['column' => 'a'], 9 => ['column' => 'b']]]);
        $this->assertSame([['column' => 'a'], ['column' => 'b']], $cfg->dims());
    }

    // ---- simple options -----------------------------------------------------------

    public function testMaxOptionsFallback(): void
    {
        $this->assertSame(500, FilterBarConfig::fromArray(['dims' => []])->maxOptions());
        $this->assertSame(500, FilterBarConfig::fromArray(['dims' => [], 'max_options' => 'abc'])->maxOptions());
        $this->assertSame(500, FilterBarConfig::fromArray(['dims' => [], 'max_options' => 0])->maxOptions());
        $this->assertSame(500, FilterBarConfig::fromArray(['dims' => [], 'max_options' => -3])->maxOptions());
        $this->assertSame(1000, FilterBarConfig::fromArray(['dims' => [], 'max_options' => '1000'])->maxOptions());
    }

    public function testRootLabel(): void
    {
        $this->assertNull(FilterBarConfig::fromArray(['dims' => []])->rootLabel());
        $this->assertNull(FilterBarConfig::fromArray(['dims' => [], 'root_label' => ''])->rootLabel());
        $this->assertSame('ABC', FilterBarConfig::fromArray(['dims' => [], 'root_label' => 'ABC'])->rootLabel());
    }

    /** scope = fixed option-list scope for a one-entity dashboard; junk entries dropped. */
    public function testScopeNormalizesToSpecs(): void
    {
        $cfg = FilterBarConfig::fromArray(['dims' => [], 'scope' => [
            'school' => '17',
            'grade' => ['1', '2'],
            'bad key' => '1',
            'empty' => '',
            'range' => ['from' => '1', 'to' => '5'],
        ]]);
        $this->assertSame([
            'school' => ['in' => ['17']],
            'grade' => ['in' => ['1', '2']],
            'range' => ['from' => '1', 'to' => '5'],
        ], $cfg->scope());
        $this->assertSame([], FilterBarConfig::fromArray(['dims' => []])->scope());
        $this->assertSame([], FilterBarConfig::fromArray(['dims' => [], 'scope' => 'x'])->scope());
    }

    // ---- explicit parents / chain ---------------------------------------------------

    public function testExplicitChainInDeclaredOrder(): void
    {
        $cfg = FilterBarConfig::fromArray(['dims' => [
            ['column' => 'region'],
            ['column' => 'prefecture', 'parent' => 'region'],
            ['column' => 'school', 'parent' => 'prefecture'],
            ['column' => 'subject'],              // independent cross-cut
            ['column' => 'grade', 'parent' => 'school'],
        ]]);
        $this->assertSame(['region', 'prefecture', 'school', 'grade'], $cfg->chainColumns());
        $this->assertTrue($cfg->isChainColumn('region'), 'a root named as a parent is in the chain');
        $this->assertFalse($cfg->isChainColumn('subject'));
        $this->assertSame('prefecture', $cfg->parentOf('school'));
        $this->assertNull($cfg->parentOf('region'));
        $this->assertNull($cfg->parentOf('subject'));
        $this->assertTrue($cfg->isParentExplicit('school'));
        $this->assertFalse($cfg->isParentExplicit('region'));
    }

    public function testParentNoneAndJunkParents(): void
    {
        $cfg = FilterBarConfig::fromArray(['dims' => [
            ['column' => 'a'],
            ['column' => 'b', 'parent' => FilterBarConfig::PARENT_NONE],
            ['column' => 'c', 'parent' => 'c'],          // self reference
            ['column' => 'd', 'parent' => 'ghost'],      // not a dim
            ['column' => 'e', 'parent' => 'a'],
        ]]);
        $this->assertNull($cfg->parentOf('b'));
        $this->assertTrue($cfg->isParentExplicit('b'), "'-' is an explicit choice");
        $this->assertNull($cfg->parentOf('c'));
        $this->assertNull($cfg->parentOf('d'));
        $this->assertSame('a', $cfg->parentOf('e'));
        $this->assertSame(['a', 'e'], $cfg->chainColumns());
    }

    /** With no select_table metadata to read (no source table), unparented dims are independent. */
    public function testNoMetadataMeansNoInferredParents(): void
    {
        $cfg = FilterBarConfig::fromArray(['dims' => [['column' => 'a'], ['column' => 'b']]]);
        $this->assertNull($cfg->inferredParentOf('a'));
        $this->assertNull($cfg->inferredParentOf('b'));
        $this->assertSame([], $cfg->chainColumns());
        $this->assertSame(['a' => null, 'b' => null], $cfg->parents());
    }

    public function testDimsWithoutColumnAreIgnoredByParents(): void
    {
        $cfg = FilterBarConfig::fromArray(['dims' => [['label' => 'no column'], ['column' => 'a']]]);
        $this->assertSame(['a' => null], $cfg->parents());
        $this->assertCount(2, $cfg->dims(), 'dims() stays raw — consumers keep their own guards');
    }

    /** Targets ride through untouched: FilterBarConfig does not interpret them (FilterState does). */
    public function testTargetsAreOpaqueHere(): void
    {
        $bar = $this->bar(['region' => ['targets' => ['b1', 'b2']], 'subject']);
        unset($bar['source_table']);
        $cfg = FilterBarConfig::fromArray($bar);
        $this->assertSame(['b1', 'b2'], $cfg->dims()[0]['targets']);
        $this->assertArrayNotHasKey('targets', $cfg->dims()[1]);
    }
}
