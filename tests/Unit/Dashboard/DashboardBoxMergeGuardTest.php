<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use Illuminate\Support\Facades\Event;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Enums\ChartType;

/**
 * DashboardBox::boot() "embeds-wipe" guard: laravel-admin's embedded form strips every
 * options key it does not declare AFTER the form's saving callbacks, so ChartItem::saving
 * arms a one-shot flag and the model re-merges the stored keys at Eloquent `saving` time.
 * Fired here through the model event (no DB) on an "existing" model whose original JSON
 * still holds the stored keys.
 */
class DashboardBoxMergeGuardTest extends DashboardUnitTestCase
{
    /** A box that looks persisted: exists + original options = $stored. */
    private function stored(array $stored): DashboardBox
    {
        $box = new DashboardBox();
        $box->suuid = 'b1';
        $box->dashboard_box_type = 'chart';
        $box->options = $stored;
        $box->syncOriginal();
        $box->exists = true;
        return $box;
    }

    private function fireSaving(DashboardBox $box): void
    {
        Event::dispatch('eloquent.saving: ' . DashboardBox::class, $box);
    }

    public function testArmedGuardRestoresKeysTheFormDidNotSubmit(): void
    {
        $box = $this->stored(['chart_type' => 'bar', 'chart_level_views' => ['' => 5], 'chart_value_mean' => true]);
        $box->options = ['chart_type' => 'line', 'chart_filters' => []]; // what the embedded form submits
        $box->mergeStoredOptions = true;
        $this->fireSaving($box);
        $this->assertSame([
            'chart_type' => 'line',
            'chart_filters' => [],
            'chart_level_views' => ['' => 5],
            'chart_value_mean' => true,
        ], $box->options, 'submitted keys win, absent stored keys come back');
        $this->assertFalse($box->mergeStoredOptions, 'one shot');
    }

    public function testSubmittedKeyAlwaysWinsEvenWhenCleared(): void
    {
        $box = $this->stored(['chart_filters' => ['a', 'b'], 'chart_sort_value' => 'desc']);
        $box->options = ['chart_filters' => [], 'chart_sort_value' => null];
        $box->mergeStoredOptions = true;
        $this->fireSaving($box);
        $this->assertSame([], $box->options['chart_filters'], 'a key the form carries (even empty) is the user edit');
        $this->assertArrayHasKey('chart_sort_value', $box->options);
        $this->assertNull($box->options['chart_sort_value']);
    }

    public function testNotArmedMeansSeedsAndFeatureCodeCanRemoveKeys(): void
    {
        $box = $this->stored(['chart_type' => 'bar', 'chart_level_views' => ['' => 5]]);
        $box->options = ['chart_type' => 'bar']; // deliberate removal outside the admin form
        $this->fireSaving($box);
        $this->assertSame(['chart_type' => 'bar'], $box->options);
    }

    public function testNewModelAndUnchangedOptionsAreLeftAlone(): void
    {
        $fresh = new DashboardBox();
        $fresh->options = ['chart_type' => 'bar'];
        $fresh->mergeStoredOptions = true;
        $this->fireSaving($fresh);
        $this->assertSame(['chart_type' => 'bar'], $fresh->options, 'nothing stored to merge on a new box');

        $same = $this->stored(['chart_type' => 'bar', 'x' => 1]);
        $same->mergeStoredOptions = true;
        $this->fireSaving($same); // options not dirty
        $this->assertSame(['chart_type' => 'bar', 'x' => 1], $same->options);
        $this->assertTrue($same->mergeStoredOptions, 'flag untouched when there was nothing to do');
    }

    public function testUnknownBoxTypeDegradesToNullItem(): void
    {
        $box = new DashboardBox();
        $box->dashboard_box_type = 'no_such_type';
        $box->options = [];
        $this->assertNull($box->dashboard_box_item);
        $attrs = $box->getBoxHtmlAttr();
        $this->assertSame('no_such_type', $attrs['data-dashboard_box_type']);
    }
}
