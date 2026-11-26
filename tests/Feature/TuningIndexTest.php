<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Jobs\AddColumnIndexJob;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SystemRequire\Tuning\HeavyViewTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\UnindexedColumnTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\ViewUsage;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Bus;

/**
 * The columns without a search index and the heavy views on the tuning page (ISS02927): found from
 * the views of the large tables (as counted at night), and the index added to the chosen columns
 * in the background, never by itself. (The index itself is CustomColumn::alterColumn: a change of
 * the table of the data, not made in a test that rolls back.)
 */
class TuningIndexTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** @var CustomTable */
    protected $table;

    /** @var CustomColumn */
    protected $column;

    /** @var CustomView */
    protected $view;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        UnindexedColumnTuning::setPending([]);
        /** @var CustomTable $table */
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $this->table = $table;
        $column = $table->custom_columns_cache->first(function ($column) {
            return !boolval($column->index_enabled) && $column->column_type === ColumnType::TEXT;
        });
        if (!($column instanceof CustomColumn)) {
            $this->fail('no text column without an index');
        }
        $this->column = $column;

        $view = new CustomView();
        $view->custom_table_id = $table->id;
        $view->view_view_name = 'tuning heavy';
        $view->view_type = ViewType::SYSTEM;
        $view->view_kind_type = ViewKindType::DEFAULT;
        $view->save();
        $this->view = $view;
        CustomViewFilter::create([
            'custom_view_id' => $view->id, 'view_column_type' => ConditionType::COLUMN, 'view_column_table_id' => $table->id,
            'view_column_target_id' => $column->id, 'view_filter_condition' => FilterOption::EQ, 'view_filter_condition_value_text' => 'x',
        ]);
        CustomViewSort::create([
            'custom_view_id' => $view->id, 'view_column_type' => ConditionType::COLUMN, 'view_column_table_id' => $table->id,
            'view_column_target_id' => $column->id, 'sort' => 1, 'priority' => 1,
        ]);
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        UnindexedColumnTuning::setPending([]);
        parent::tearDown();
    }

    protected function indexEnabled(): bool
    {
        /** @var CustomColumn $column */
        $column = CustomColumn::query()->findOrFail($this->column->id);
        return boolval($column->index_enabled);
    }

    /**
     * @return array<string, mixed>
     */
    protected function stats(int $rows): array
    {
        return [
            'time' => time(),
            'tables' => [$this->table->table_name => ['rows' => $rows, 'deleted' => 0, 'revisions' => 0, 'data_bytes' => null, 'index_bytes' => null, 'previous_rows' => null, 'previous_time' => null]],
            'files' => ['count' => 0, 'bytes' => 0, 'bytes_partial' => false, 'orphans' => 0],
            'database' => ['bytes' => null],
        ];
    }

    public function testTheColumnsAndTheViewsOfALargeTable(): void
    {
        $item = new UnindexedColumnTuning($this->stats(20000));
        $this->assertSame(SystemRequireResult::WARNING, $item->checkResult());
        $choices = $item->getChoices() ?? [];
        $this->assertArrayHasKey($this->column->id, $choices);
        $label = $choices[$this->column->id];
        $this->assertStringContainsString($this->column->column_view_name, $label);
        $this->assertStringContainsString(exmtrans('tuning.item.unindexed_columns.use.filter'), $label);
        $this->assertStringContainsString(exmtrans('tuning.item.unindexed_columns.use.sort'), $label);
        $this->assertSame(UnindexedColumnTuning::ACTION, $item->getAction());
        $this->assertStringContainsString('/column/' . $this->table->table_name . '/', strval($item->getSettingUrl()));

        $heavy = new HeavyViewTuning($this->stats(20000));
        $this->assertSame(SystemRequireResult::WARNING, $heavy->checkResult());
        $this->assertArrayHasKey($this->view->id, $heavy->views());
        $this->assertSame(['filter', 'sort'], $heavy->views()[$this->view->id]['reasons']);
        $this->assertStringContainsString('tuning heavy', strval($heavy->getResultText()));
    }

    public function testASmallTableIsNotTold(): void
    {
        $this->assertSame(SystemRequireResult::OK, (new UnindexedColumnTuning($this->stats(100)))->checkResult());
        $this->assertSame(SystemRequireResult::OK, (new HeavyViewTuning($this->stats(100)))->checkResult());
        $this->assertNull((new UnindexedColumnTuning($this->stats(100)))->getAction());
    }

    public function testOnlyTheChosenColumnsInTheBackground(): void
    {
        config(['queue.default' => 'sync']);
        Bus::fake();
        $item = new UnindexedColumnTuning($this->stats(20000));
        // a column that is not told is not added
        $this->assertSame(0, $item->addIndexes([999999, 'x']));
        Bus::assertNothingDispatched();

        $this->assertSame(1, $item->addIndexes([$this->column->id, $this->column->id]));
        Bus::assertDispatchedAfterResponse(AddColumnIndexJob::class, function ($job) {
            return $job->columnId === intval($this->column->id);
        });
        $this->assertArrayHasKey($this->column->id, UnindexedColumnTuning::pending());
        // waiting for its index: not told again
        $this->assertArrayNotHasKey($this->column->id, (new UnindexedColumnTuning($this->stats(20000)))->columns());
        // not changed by itself
        $this->assertFalse($this->indexEnabled());

        // with a queue worker: on the queue
        UnindexedColumnTuning::setPending([]);
        config(['queue.default' => 'database']);
        $this->assertSame(1, (new UnindexedColumnTuning($this->stats(20000)))->addIndexes([$this->column->id]));
        Bus::assertDispatched(AddColumnIndexJob::class);
    }

    public function testTheJobKeepsTheLimitOfTheTable(): void
    {
        UnindexedColumnTuning::setPending([intval($this->column->id) => time()]);
        config(['exment.column_index_enabled_count' => 0]);
        (new AddColumnIndexJob(intval($this->column->id)))->handle();
        $this->assertFalse($this->indexEnabled());
        $this->assertArrayNotHasKey($this->column->id, UnindexedColumnTuning::pending());
        // the table has all its index columns: no choice
        $this->assertSame([], (new UnindexedColumnTuning($this->stats(20000)))->getChoices());
    }

    public function testNoMoreColumnsThanTheTableCanHave(): void
    {
        config(['queue.default' => 'sync']);
        Bus::fake();
        // two more columns filtered by the view: three to choose
        $others = $this->table->custom_columns_cache->filter(function ($column) {
            return !boolval($column->index_enabled) && !in_array($column->column_type, ViewUsage::NOT_INDEXED_TYPES, true) && $column->id !== $this->column->id;
        })->take(2)->values();
        $this->assertCount(2, $others);
        foreach ($others as $other) {
            CustomViewFilter::create([
                'custom_view_id' => $this->view->id, 'view_column_type' => ConditionType::COLUMN, 'view_column_table_id' => $this->table->id,
                'view_column_target_id' => $other->id, 'view_filter_condition' => FilterOption::EQ, 'view_filter_condition_value_text' => 'x',
            ]);
        }
        System::clearRequestSession();
        $ids = array_merge([intval($this->column->id)], $others->pluck('id')->map(function ($id) {
            return intval($id);
        })->all());

        // room for one more
        $tableId = intval($this->table->id);
        config(['exment.column_index_enabled_count' => UnindexedColumnTuning::indexCount($tableId) + 1]);
        $item = new UnindexedColumnTuning($this->stats(20000));
        foreach ($ids as $id) {
            $this->assertArrayHasKey($id, $item->getChoices() ?? []);
        }
        $this->assertSame(1, $item->addIndexes($ids));
        Bus::assertDispatchedTimes(AddColumnIndexJob::class, 1);
        // the one waiting takes the room: nothing more to choose
        $this->assertSame([], (new UnindexedColumnTuning($this->stats(20000)))->getChoices());
        $this->assertSame(0, (new UnindexedColumnTuning($this->stats(20000)))->addIndexes($ids));

        // a column stored with true counts as indexed too (the counts of the form read '1')
        UnindexedColumnTuning::setPending([]);
        $before = UnindexedColumnTuning::indexCount($tableId);
        $options = (array) json_decode(strval(\DB::table('custom_columns')->where('id', $ids[2])->value('options')), true);
        $options['index_enabled'] = true;
        \DB::table('custom_columns')->where('id', $ids[2])->update(['options' => json_encode($options)]);
        $this->assertSame($before + 1, UnindexedColumnTuning::indexCount($tableId));
    }

    public function testThePageAndTheButton(): void
    {
        System::tuning_data_stats($this->stats(20000));
        System::clearCache();
        config(['exment.features_locked' => '', 'queue.default' => 'sync']);
        Bus::fake();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $content = (string) $this->get(admin_url('tuning'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringContainsString('name="choices[]" value="' . $this->column->id . '"', $content);
        $this->assertStringContainsString('data-tuning="heavy_views"', $content);

        $this->post(admin_url('tuning/action'), ['action' => UnindexedColumnTuning::ACTION, 'choices' => [$this->column->id]])
            ->assertRedirect(admin_url('tuning'));
        Bus::assertDispatchedAfterResponse(AddColumnIndexJob::class);
        // none chosen
        $this->post(admin_url('tuning/action'), ['action' => UnindexedColumnTuning::ACTION])->assertRedirect(admin_url('tuning'));
        System::tuning_data_stats([]);
    }
}
