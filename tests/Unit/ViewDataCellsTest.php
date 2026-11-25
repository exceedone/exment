<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The cells of a view (CustomView::convertDataTable, the list of the view and the API
 * viewdata) read the settings of each column once, not once a cell (ISS02885): the cells are
 * the same as when each cell reads them, for every kind of value and view.
 */
class ViewDataCellsTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    protected function init(): void
    {
        $this->initAllTest();
    }

    public function testListViews(): void
    {
        $this->init();
        $this->be(\Exceedone\Exment\Model\LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $views = 0;
        foreach ([TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST, 'custom_value_edit'] as $table_name) {
            $custom_table = CustomTable::getEloquent($table_name);
            $this->assertNotNull($custom_table, $table_name);
            foreach (CustomView::where('custom_table_id', $custom_table->id)->whereIn('view_kind_type', [ViewKindType::DEFAULT, ViewKindType::ALLDATA])->get() as $custom_view) {
                $data = $custom_view->getDataPaginate(['maxCount' => 30, 'executeSearch' => false, 'isApi' => true])->items();
                $this->assertSameCells($custom_view, $data);
                $views++;
            }
        }
        $this->assertGreaterThan(2, $views);
    }

    public function testAggregateView(): void
    {
        $this->init();
        $options = [
            'column_settings' => [[
                'column_name' => 'user',
                'condition_type' => ConditionType::COLUMN,
            ]],
            'summary_settings' => [[
                'column_name' => 'id',
                'condition_type' => ConditionType::SYSTEM,
                'summary_condition' => SummaryCondition::COUNT,
            ], [
                'column_name' => 'integer',
                'condition_type' => ConditionType::COLUMN,
                'summary_condition' => SummaryCondition::SUM,
            ]],
        ];
        list($custom_view, $data) = $this->getCustomView($options, ViewKindType::AGGREGATE);
        $this->assertNotEmpty($data);
        $this->assertSameCells($custom_view, $data);
    }

    /**
     * @param iterable<mixed> $data
     */
    protected function assertSameCells(CustomView $custom_view, $data): void
    {
        foreach ([ValueType::HTML, ValueType::TEXT, ValueType::PURE_VALUE, ValueType::VALUE] as $valueType) {
            foreach ([true, false] as $appendLink) {
                $options = ['appendLink' => $appendLink, 'valueType' => $valueType];
                $expected = $this->cellsOneByOne($custom_view, $data, $options);
                list($headers, $bodies) = $custom_view->convertDataTable($data, $options);
                if ($appendLink && $custom_view->view_kind_type != ViewKindType::AGGREGATE) {
                    // the links of a row come last, made as before
                    $bodies = array_map(function ($cells) {
                        $link = array_pop($cells);
                        $this->assertStringContainsString('data-id=', (string)$link);
                        return $cells;
                    }, $bodies);
                }
                $this->assertSame(
                    $this->comparable($expected),
                    $this->comparable($bodies),
                    "view {$custom_view->view_view_name}, $valueType, link " . ($appendLink ? 'on' : 'off')
                );
            }
        }
    }

    /**
     * The cells as convertDataTable made them before: each cell reads the settings of its column.
     *
     * @param iterable<mixed> $data
     * @param array<string, mixed> $options
     * @return array<int, array<int, mixed>>
     */
    protected function cellsOneByOne(CustomView $custom_view, $data, array $options): array
    {
        $bodies = [];
        foreach ($data as $row) {
            $body_items = [];
            foreach ($custom_view->getSummaryIndexAndViewColumns() as $view_column_item) {
                $column = array_get($view_column_item, 'item');
                $item = $column->column_item;
                if ($custom_view->view_kind_type == ViewKindType::AGGREGATE) {
                    $summary_condition = array_get($column, 'view_summary_condition');
                    $item->options([
                        'summary' => true,
                        'summary_index' => array_get($view_column_item, 'index'),
                        'summary_condition' => $summary_condition,
                        'group_condition' => array_get($column, 'view_group_condition'),
                        'disable_currency_symbol' => ($summary_condition == SummaryCondition::COUNT),
                    ]);
                }
                $item->options([
                    'view_pivot_column' => $column->view_pivot_column_id ?? null,
                    'view_pivot_table' => $column->view_pivot_table_id ?? null,
                    'grid_column' => true,
                    'grid_preset' => $column->getOption('grid_preset'),
                ]);
                $body_items[] = ValueType::getEnum($options['valueType'])->getCustomValue($item, $row);
            }
            $bodies[] = $body_items;
        }
        return $bodies;
    }

    /**
     * The cells without the links (made the same way by both) as text, so objects compare by value.
     *
     * @param array<int, array<int, mixed>> $bodies
     */
    protected function comparable(array $bodies): string
    {
        return json_encode(array_map(function ($cells) {
            return array_map(function ($cell) {
                return is_object($cell) && method_exists($cell, 'toArray') ? $cell->toArray() : $cell;
            }, $cells);
        }, $bodies), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }
}
