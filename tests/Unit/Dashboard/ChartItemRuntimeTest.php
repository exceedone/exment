<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard;

use ReflectionMethod;
use ReflectionProperty;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\DashboardUnitTestCase;
use Exceedone\Exment\Tests\Unit\Dashboard\Support\FakeBoxForm;
use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Model\DashboardBox;

/**
 * ChartItem — the box item that hosts all three features. Two DB-free slices:
 *
 *  RUNTIME (a box without a table: no query is ever built, but the constructor and the
 *  toolbar fragments still run):
 *   - Feature 3: the `ct` param is applied in the CONSTRUCTOR through the registry, so
 *     every type gate downstream sees the effective type; the toolbar <select> echoes it;
 *     `chart_type_lock` renders no switcher (and ignores ct).
 *   - Feature 1: no chart_filters / no table → no filter fragment, no context caption.
 *
 *  ADMIN SAVE (ChartItem::saving with the fake form): chart_filters normalization —
 *  identifiers only; the key is written only when the user picked something or the box
 *  already HAD it (a real clear), so pristine boxes never grow a stray key; every stored
 *  key the form does not expose is merged back and the model-level guard is armed.
 */
class ChartItemRuntimeTest extends DashboardUnitTestCase
{
    protected function item(array $options, array $request = [], ?array $bar = null): ChartItem
    {
        $this->swapRequest($request);
        $box = $this->makeBox('b1', $options, $this->makeDashboard($bar));
        return new ChartItem($box);
    }

    protected function prop(ChartItem $item, string $name)
    {
        $p = new ReflectionProperty($item, $name);
        $p->setAccessible(true);
        return $p->getValue($item);
    }

    protected function invokeItem(ChartItem $item, string $method, ...$args)
    {
        $m = new ReflectionMethod($item, $method);
        $m->setAccessible(true);
        return $m->invoke($item, ...$args);
    }

    // ---- Feature 3: ct applied at construction ------------------------------------

    public function testConfiguredTypeWithoutCt(): void
    {
        $this->assertSame('bar', $this->prop($this->item(['chart_type' => 'bar']), 'chart_type'));
    }

    public function testCtSwitchesWithinPool(): void
    {
        $this->assertSame('line', $this->prop($this->item(['chart_type' => 'bar'], ['ct' => 'line']), 'chart_type'));
        $this->assertSame('hbar', $this->prop($this->item(['chart_type' => 'pie'], ['ct' => 'hbar']), 'chart_type'));
        $this->assertSame('sbar', $this->prop($this->item(['chart_type' => 'mbar'], ['ct' => 'sbar']), 'chart_type'));
    }

    public function testCtCrossShapeOrJunkFallsBack(): void
    {
        $this->assertSame('bar', $this->prop($this->item(['chart_type' => 'bar'], ['ct' => 'mbar']), 'chart_type'));
        $this->assertSame('mbar', $this->prop($this->item(['chart_type' => 'mbar'], ['ct' => 'bar']), 'chart_type'));
        $this->assertSame('bar', $this->prop($this->item(['chart_type' => 'bar'], ['ct' => 'zzz']), 'chart_type'));
        $this->assertSame('bar', $this->prop($this->item(['chart_type' => 'bar'], ['ct' => ['line']]), 'chart_type'));
    }

    /** ct is presentation only: the configured type in options is never touched. */
    public function testCtDoesNotMutateConfig(): void
    {
        $item = $this->item(['chart_type' => 'bar'], ['ct' => 'line']);
        $this->assertSame('bar', $this->prop($item, 'config')->chartType());
        $this->assertSame('bar', $this->prop($item, 'dashboard_box')->options['chart_type']);
    }

    // ---- Feature 3: toolbar type select ---------------------------------------------

    public function testTypeSelectListsPoolAndEchoesEffectiveType(): void
    {
        $html = $this->invokeItem($this->item(['chart_type' => 'bar'], ['ct' => 'line']), 'toolbarTypeHtml');
        $this->assertStringContainsString('class="exment-ct-switch ct-sel"', $html);
        $this->assertMatchesRegularExpression('/<option value="line" selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="bar" selected>/', $html);
        foreach (['bar', 'line', 'pie', 'hbar', 'doughnut'] as $t) {
            $this->assertStringContainsString('<option value="' . $t . '"', $html);
        }
        $this->assertStringNotContainsString('value="mbar"', $html, 'multi-series types are another pool');
        $this->assertStringNotContainsString('value="sbar"', $html);
        // one option per pool entry, exactly once
        $this->assertSame(count(\Exceedone\Exment\Services\Dashboard\ChartRendererRegistry::switchPool('bar')), substr_count($html, '<option '));
    }

    public function testTypeSelectSelectedIsConfiguredWithoutCt(): void
    {
        $html = $this->invokeItem($this->item(['chart_type' => 'pie']), 'toolbarTypeHtml');
        $this->assertMatchesRegularExpression('/<option value="pie" selected>/', $html);
    }

    public function testTypeSelectHiddenWhenLocked(): void
    {
        $this->assertSame('', $this->invokeItem($this->item(['chart_type' => 'bar', 'chart_type_lock' => 1]), 'toolbarTypeHtml'));
    }

    /** chart_type_lock hides the select AND makes the server ignore a hand-made ct. */
    public function testLockIgnoresCtServerSide(): void
    {
        $item = $this->item(['chart_type' => 'bar', 'chart_type_lock' => 1], ['ct' => 'line']);
        $this->assertSame('bar', $this->prop($item, 'chart_type'));
        $this->assertSame('', $this->invokeItem($item, 'toolbarTypeHtml'));
    }

    // ---- Feature 3: option flags follow the EFFECTIVE family ---------------------------

    /** saving() strips LEGEND for bar boxes — a bar→pie switch must not render a legend-less pie. */
    public function testCrossFamilySwitchGetsItsFamilyDefaultFlag(): void
    {
        // bar box: only BEGIN_ZERO stored; switched to pie → LEGEND added, BEGIN_ZERO kept (harmless)
        $item = $this->item(['chart_type' => 'bar', 'chart_options' => ['2']], ['ct' => 'pie']);
        $this->assertSame(['2', '1'], $this->prop($item, 'chart_options'));
        // pie box: only LEGEND stored; switched to bar → BEGIN_ZERO added
        $item = $this->item(['chart_type' => 'pie', 'chart_options' => ['1']], ['ct' => 'bar']);
        $this->assertSame(['1', '2'], $this->prop($item, 'chart_options'));
        // circular → circular (pie → doughnut) keeps flags as configured (legend explicitly OFF stays OFF)
        $item = $this->item(['chart_type' => 'pie', 'chart_options' => []], ['ct' => 'doughnut']);
        $this->assertSame([], $this->prop($item, 'chart_options'));
        // same type / refused switch / no ct → untouched
        foreach ([[], ['ct' => 'bar'], ['ct' => 'mbar'], ['ct' => 'zzz']] as $req) {
            $item = $this->item(['chart_type' => 'bar', 'chart_options' => ['2']], $req);
            $this->assertSame(['2'], $this->prop($item, 'chart_options'), json_encode($req));
        }
        // no flag duplicated when already present
        $item = $this->item(['chart_type' => 'bar', 'chart_options' => ['1', '2']], ['ct' => 'pie']);
        $this->assertSame(['1', '2'], $this->prop($item, 'chart_options'));
    }

    // ---- toolbar composition ------------------------------------------------------------

    public function testToolbarOnlyTypeForALegacyBox(): void
    {
        $html = $this->invokeItem($this->item(['chart_type' => 'bar']), 'chartToolbarHtml');
        $this->assertStringStartsWith('<div class="chart-toolbar"', $html);
        $this->assertStringContainsString('exment-ct-switch', $html);
        $this->assertStringNotContainsString('data-pop="filters"', $html, 'no chart_filters → no filter button');
        $this->assertStringNotContainsString('ct-active', $html, 'no active bf → no context caption');
        $this->assertSame('', $this->invokeItem($this->item(['chart_type' => 'bar', 'chart_type_lock' => 1]), 'chartToolbarHtml'), 'locked box without filters → no toolbar at all');
    }

    /** chart_filters declared but the box has no table (misconfigured) → fragment silently empty. */
    public function testFilterFragmentNeedsATable(): void
    {
        $item = $this->item(['chart_type' => 'bar', 'chart_filters' => ['region']], ['bf_region' => '1']);
        $this->assertSame('', $this->invokeItem($item, 'toolbarFilterHtml'));
        $this->assertSame([], $this->invokeItem($item, 'boxFilterContext'));
    }

    /** The lazy option endpoint answers nothing for a box it cannot serve (no table / no permission). */
    public function testChartFilterOptionsEmptyWithoutTable(): void
    {
        $item = $this->item(['chart_type' => 'bar', 'chart_filters' => ['region']]);
        $this->assertSame([], $item->chartFilterOptions());
        $this->assertSame([], $item->chartFilterOptions('region'));
        $this->assertSame([], $this->item(['chart_type' => 'bar'])->chartFilterOptions(), 'no chart_filters at all');
    }

    // ---- filterBarChain() with explicit parents (targets untouched) ------------------------

    public function testFilterBarChainDeepestFollowsSelection(): void
    {
        $bar = ['dims' => [['column' => 'region'], ['column' => 'school', 'parent' => 'region'], ['column' => 'subject']]];
        $item = $this->item(['chart_type' => 'bar'], ['df_region' => '1', 'df_subject' => '2'], $bar);
        $chain = $this->invokeItem($item, 'filterBarChain');
        $this->assertSame(['region', 'school'], $chain['chain']);
        $this->assertSame('region', $chain['deepest']);
        $this->assertSame([], $chain['applied'], 'no table → nothing applied');

        $item = $this->item(['chart_type' => 'bar'], ['df_school' => ['3', '4']], $bar);
        $this->assertSame('school', $this->invokeItem($item, 'filterBarChain')['deepest'], 'a multi-value selection counts');
    }

    // ---- ChartItem::saving(): chart_filters normalization --------------------------------

    protected function storedBox(?array $options): DashboardBox
    {
        $box = $this->makeBox('b1', $options ?? []);
        if ($options !== null) {
            $box->exists = true; // behaves like a loaded row
        }
        return $box;
    }

    public function testSavingKeepsPickedFilters(): void
    {
        $form = new FakeBoxForm($this->storedBox(['chart_type' => 'bar']), ['options' => ['chart_type' => 'bar', 'chart_filters' => ['region', 'bad name', '', 'subject']]]);
        ChartItem::saving($form);
        $this->assertSame(['region', 'subject'], $form->options['chart_filters']);
    }

    public function testSavingPristineBoxGainsNoStrayKey(): void
    {
        $form = new FakeBoxForm($this->storedBox(['chart_type' => 'bar']), ['options' => ['chart_type' => 'bar']]);
        ChartItem::saving($form);
        $this->assertArrayNotHasKey('chart_filters', $form->options);

        // new (unsaved) box, nothing picked → no key either
        $form = new FakeBoxForm($this->storedBox(null), ['options' => ['chart_type' => 'bar']]);
        ChartItem::saving($form);
        $this->assertArrayNotHasKey('chart_filters', $form->options);
    }

    public function testSavingClearIsHonoredWhenBoxHadFilters(): void
    {
        $form = new FakeBoxForm($this->storedBox(['chart_type' => 'bar', 'chart_filters' => ['region']]), ['options' => ['chart_type' => 'bar']]);
        ChartItem::saving($form);
        $this->assertSame([], $form->options['chart_filters'], 'multiselect emptied → explicit [] wins over the merge');
    }

    public function testSavingMergesUnexposedKeysAndArmsGuard(): void
    {
        $model = $this->storedBox(['chart_type' => 'bar', 'chart_level_views' => ['' => 5], 'chart_value_mean' => 1, 'chart_filters' => ['region']]);
        $form = new FakeBoxForm($model, ['options' => ['chart_type' => 'line', 'chart_filters' => ['subject']]]);
        ChartItem::saving($form);
        $this->assertSame('line', $form->options['chart_type']);
        $this->assertSame(['subject'], $form->options['chart_filters'], 'submitted value wins');
        $this->assertSame(['' => 5], $form->options['chart_level_views'], 'unexposed key merged back');
        $this->assertSame(1, $form->options['chart_value_mean']);
        $this->assertTrue($model->mergeStoredOptions, 'model-level one-shot guard armed for the real save');
    }

    /** chart_sort_value / chart_max_groups (top-N ranking as an ordinary chart): normalize + keep-when-had. */
    public function testSavingSortAndMaxGroups(): void
    {
        // picked → stored normalized
        $form = new FakeBoxForm($this->storedBox(['chart_type' => 'bar']), ['options' => ['chart_type' => 'bar', 'chart_sort_value' => 'desc', 'chart_max_groups' => '10']]);
        ChartItem::saving($form);
        $this->assertSame('desc', $form->options['chart_sort_value']);
        $this->assertSame(10, $form->options['chart_max_groups']);

        // pristine box, nothing picked → no stray keys
        $form = new FakeBoxForm($this->storedBox(['chart_type' => 'bar']), ['options' => ['chart_type' => 'bar', 'chart_sort_value' => '', 'chart_max_groups' => '']]);
        ChartItem::saving($form);
        $this->assertArrayNotHasKey('chart_sort_value', $form->options);
        $this->assertArrayNotHasKey('chart_max_groups', $form->options);

        // junk → off (0 groups = all, unknown sort = view order)
        $form = new FakeBoxForm($this->storedBox(['chart_type' => 'bar']), ['options' => ['chart_type' => 'bar', 'chart_sort_value' => 'asc', 'chart_max_groups' => '0']]);
        ChartItem::saving($form);
        $this->assertArrayNotHasKey('chart_sort_value', $form->options);
        $this->assertArrayNotHasKey('chart_max_groups', $form->options);

        // box HAD them, user cleared → key kept as null so the stored-key merge cannot resurrect
        $form = new FakeBoxForm($this->storedBox(['chart_type' => 'bar', 'chart_sort_value' => 'desc', 'chart_max_groups' => 10]), ['options' => ['chart_type' => 'bar', 'chart_sort_value' => '', 'chart_max_groups' => '']]);
        ChartItem::saving($form);
        $this->assertArrayHasKey('chart_sort_value', $form->options);
        $this->assertNull($form->options['chart_sort_value']);
        $this->assertArrayHasKey('chart_max_groups', $form->options);
        $this->assertNull($form->options['chart_max_groups']);
        // and the config reader treats the cleared value as "off"
        $this->assertSame(0, \Exceedone\Exment\Services\Dashboard\BoxChartConfig::of(['options' => $form->options])->maxGroups());
    }

    /** Legacy: chart_options are still pruned by chart family (circular keeps LEGEND, others BEGIN_ZERO). */
    public function testSavingLegacyChartOptionsPruning(): void
    {
        $form = new FakeBoxForm($this->storedBox(null), ['options' => ['chart_type' => 'pie', 'chart_options' => ['1', '2'], 'chart_axis_label' => ['x']]]);
        ChartItem::saving($form);
        $this->assertSame(['1'], $form->options['chart_options']);
        $this->assertSame([], $form->options['chart_axis_label']);

        $form = new FakeBoxForm($this->storedBox(null), ['options' => ['chart_type' => 'bar', 'chart_options' => ['1', '2']]]);
        ChartItem::saving($form);
        $this->assertSame(['2'], $form->options['chart_options']);
    }
}
