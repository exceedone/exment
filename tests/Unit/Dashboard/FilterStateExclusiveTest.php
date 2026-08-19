<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Services\Dashboard\FilterState;

/**
 * Mutual exclusion between filter-bar dims (dim option `disables`): while the disabling
 * dim is selected, the disabled dims' df_ params are stripped from the LIVE request
 * before any consumer reads them (deep link / stale URL / breadcrumb hop). Deliberately
 * request-mutating — every reader (filters, badge, benchmark, AI insight) then agrees.
 */
class FilterStateExclusiveTest extends DashboardUnitTestCase
{
    public function testDisabledDimIsStrippedWhileTheDisablingDimIsSelected(): void
    {
        $dashboard = $this->makeDashboard($this->bar(['band' => ['disables' => ['subject']], 'subject', 'region']));
        $req = $this->swapRequest(['df_band' => 'A', 'df_subject' => '3', 'df_region' => '1']);
        FilterState::sanitizeExclusive($dashboard);
        $this->assertNull($req->input('df_subject'), 'locked-out dim dropped from the request');
        $this->assertSame('A', $req->input('df_band'));
        $this->assertSame('1', $req->input('df_region'), 'unrelated dim untouched');
        $this->assertSame(['band', 'region'], FilterState::activeColumns());
    }

    public function testNothingHappensWithoutASelectionOnTheDisablingDim(): void
    {
        $dashboard = $this->makeDashboard($this->bar(['band' => ['disables' => ['subject']], 'subject']));
        $req = $this->swapRequest(['df_subject' => '3']);
        FilterState::sanitizeExclusive($dashboard);
        $this->assertSame('3', $req->input('df_subject'));
    }

    public function testListAndRangeSelectionsAlsoLock(): void
    {
        $dashboard = $this->makeDashboard($this->bar(['score' => ['disables' => ['subject']], 'subject']));
        $req = $this->swapRequest(['df_score' => ['from' => '80', 'to' => ''], 'df_subject' => ['1', '2']]);
        FilterState::sanitizeExclusive($dashboard);
        $this->assertNull($req->input('df_subject'));
    }

    public function testJunkTargetsAndMissingBarAreNoops(): void
    {
        $dashboard = $this->makeDashboard($this->bar(['band' => ['disables' => ['bad name', '', 5]], 'subject']));
        $req = $this->swapRequest(['df_band' => 'A', 'df_subject' => '3']);
        FilterState::sanitizeExclusive($dashboard);
        $this->assertSame('3', $req->input('df_subject'), 'non-identifier targets are ignored');

        $req = $this->swapRequest(['df_band' => 'A', 'df_subject' => '3']);
        FilterState::sanitizeExclusive(null);
        FilterState::sanitizeExclusive($this->makeDashboard(null));
        $this->assertSame('3', $req->input('df_subject'));
    }

    public function testIdempotentPerRequest(): void
    {
        $dashboard = $this->makeDashboard($this->bar(['band' => ['disables' => ['subject']], 'subject']));
        $req = $this->swapRequest(['df_band' => 'A', 'df_subject' => '3']);
        FilterState::sanitizeExclusive($dashboard);
        FilterState::sanitizeExclusive($dashboard);
        $this->assertSame(['df_band' => 'A'], $req->all());
    }
}
