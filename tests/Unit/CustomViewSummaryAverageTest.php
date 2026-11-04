<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\DashboardBoxItems\ChartItem;
use Exceedone\Exment\DataItems\Grid\SummaryGrid;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Validator\ExmentCustomValidator;

/**
 * AVG in an aggregate view (SummaryCondition::AVG).
 *
 * Disabled since 2019 (2a5f421f6): a summary of a child relation runs in two steps — a sub
 * query per parent, folded again by the main query — and an average of the per-parent
 * averages is not the average of the rows. The sub query now selects each parent's sum and
 * count and the main query divides their totals. Each average is checked against the rows
 * it summarizes, read without the view.
 */
class CustomViewSummaryAverageTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    // Enum, options and validation ------------------------------------------------------

    public function testAverageIsANumericSummaryCondition(): void
    {
        $this->assertSame('2', SummaryCondition::AVG, 'the id the options kept for it since 2019');
        $this->assertSame('avg', SummaryCondition::getSummaryConditionName(SummaryCondition::AVG));
        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval', array_keys(SummaryCondition::getOptions())), 'listed after SUM');
        $this->assertArrayNotHasKey('2', SummaryCondition::getOptions(['numeric' => false]), 'offered for number columns only, like SUM');
        $this->assertArrayNotHasKey('1', SummaryCondition::getOptions(['numeric' => false]));
        foreach (['ja', 'en'] as $locale) {
            $this->assertTrue(\Lang::has('exment::exment.custom_view.summary_condition_options.avg', $locale), "label in $locale");
        }
    }

    public function testAverageIsValidForNumberColumnsOnly(): void
    {
        $table = CustomTable::getEloquent('parent_table');
        $valid = function (string $target, string $condition): bool {
            $validator = \Validator::make(
                ['custom_view_summaries' => ['new_1' => ['view_column_target' => $target, 'view_summary_condition' => $condition]]],
                ['custom_view_summaries.*.view_summary_condition' => 'summaryCondition']
            );
            $this->assertInstanceOf(ExmentCustomValidator::class, $validator);
            return $validator->passes();
        };
        foreach (['integer', 'decimal', 'currency'] as $column_name) {
            $column = CustomColumn::getEloquent($column_name, $table);
            $this->assertTrue($valid("{$column->id}?table_id={$table->id}", SummaryCondition::AVG), $column_name);
        }
        $text = CustomColumn::getEloquent('text', $table);
        $this->assertFalse($valid("{$text->id}?table_id={$table->id}", SummaryCondition::AVG), 'no average of a text column');
        $this->assertFalse($valid("id?table_id={$table->id}", SummaryCondition::AVG), 'no average of a system column');
        $this->assertTrue($valid("{$text->id}?table_id={$table->id}", SummaryCondition::COUNT), 'COUNT of a text column as before');
        $this->assertFalse($valid("{$text->id}?table_id={$table->id}", SummaryCondition::SUM), 'SUM of a text column refused as before');
    }

    // Values -------------------------------------------------------------------------------

    /**
     * A column of the view's own table: indexed (all_columns_table_fortest) or read from the
     * JSON value (parent_table), integer / decimal / currency.
     */
    public function testAverageOfTheTablesOwnColumn(): void
    {
        foreach (['all_columns_table_fortest' => 'text', 'parent_table' => 'odd_even'] as $table_name => $group) {
            $table = CustomTable::getEloquent($table_name);
            foreach (['integer', 'decimal', 'currency'] as $column_name) {
                $expected = [];
                foreach ($table->getValueModel()->get() as $row) {
                    $expected[$this->key($row->getValue($group))][] = $row->getValue($column_name);
                }
                $result = $this->summary([
                    'target_table_name' => $table_name,
                    'column_settings' => [['column_name' => $group]],
                    'summary_settings' => [['column_name' => $column_name, 'summary_condition' => SummaryCondition::AVG]],
                ]);
                $this->assertAverages($expected, $result, "$table_name.$column_name");
            }
        }
    }

    /**
     * 1:n children (parent_table → child_table): the average of every child of the group's
     * parents, whatever their number per parent.
     */
    public function testAverageOfChildRows(): void
    {
        $children = CustomTable::getEloquent('child_table')->getValueModel()->get()->groupBy('parent_id');
        $expected = [];
        foreach (CustomTable::getEloquent('parent_table')->getValueModel()->get() as $parent) {
            foreach ($children->get($parent->id, collect()) as $child) {
                $expected[$this->key($parent->getValue('multiples_of_3'))][] = $child->getValue('integer');
            }
        }
        $result = $this->summary([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'multiples_of_3']],
            'summary_settings' => [['reference_table' => 'child_table', 'is_child' => true, 'column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $this->assertAverages($expected, $result, 'child_table.integer');
    }

    /**
     * The case AVG was disabled for: parent A has one child of 100, parent B three of 0. The
     * average of the group is (100 + 0 + 0 + 0) / 4 = 25 — not (100 + 0) / 2 = 50, the
     * average of the per-parent averages. A child without a value is no 0; a group whose
     * parents have no child has no average.
     */
    public function testAverageOfChildRowsIsNotAnAverageOfAverages(): void
    {
        $a = $this->createParent('parent_table', 'avg_trap');
        $this->createChild('child_table', $a, 100);
        $this->createChild('child_table', $a, null);
        $b = $this->createParent('parent_table', 'avg_trap');
        foreach ([0, 0, 0] as $integer) {
            $this->createChild('child_table', $b, $integer);
        }
        $this->createParent('parent_table', 'avg_no_child');

        $result = $this->summary([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['reference_table' => 'child_table', 'is_child' => true, 'column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $this->assertEquals(25, $result['avg_trap']);
        $this->assertNull($result['avg_no_child']);
    }

    /**
     * n:n children (parent_table_n_n ↔ child_table_n_n): a child linked to two parents of a
     * group counts once per link, as SUM and COUNT count it.
     */
    public function testAverageOfManyToManyChildren(): void
    {
        $relation = CustomRelation::getRelationByParentChild('parent_table_n_n', 'child_table_n_n');
        $children = CustomTable::getEloquent('child_table_n_n')->getValueModel()->get()->keyBy('id');
        $links = \DB::table($relation->getRelationName())->get()->groupBy('parent_id');
        $expected = [];
        foreach (CustomTable::getEloquent('parent_table_n_n')->getValueModel()->get() as $parent) {
            foreach ($links->get($parent->id, collect()) as $link) {
                $child = $children->get($link->child_id);
                if ($child) {
                    $expected[$this->key($parent->updated_user_id)][] = $child->getValue('integer');
                }
            }
        }
        $result = $this->summary([
            'target_table_name' => 'parent_table_n_n',
            'column_settings' => [['condition_type' => ConditionType::SYSTEM, 'column_name' => 'updated_user']],
            'summary_settings' => [['reference_table' => 'child_table_n_n', 'is_child' => true, 'column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $this->assertAverages($expected, $result, 'child_table_n_n.integer');
    }

    /**
     * The trap of the 1:n case through an n:n relation: 25, not 50.
     */
    public function testAverageOfManyToManyChildrenIsNotAnAverageOfAverages(): void
    {
        $relation = CustomRelation::getRelationByParentChild('parent_table_n_n', 'child_table_n_n');
        $a = $this->createParent('parent_table_n_n', 'avg_trap');
        $b = $this->createParent('parent_table_n_n', 'avg_trap');
        $link = function (CustomValue $parent, ?int $integer) use ($relation) {
            $child = $this->createRecord('child_table_n_n', ['text' => 'avg_child', 'integer' => $integer]);
            \DB::table($relation->getRelationName())->insert(['parent_id' => $parent->id, 'child_id' => $child->id]);
        };
        $link($a, 100);
        $link($a, null);
        foreach ([0, 0, 0] as $integer) {
            $link($b, $integer);
        }

        $result = $this->summary([
            'target_table_name' => 'parent_table_n_n',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['reference_table' => 'child_table_n_n', 'is_child' => true, 'column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $this->assertEquals(25, $result['avg_trap']);
    }

    /**
     * Grouped by a column of the table that refers to the view's table (child_table_select →
     * parent_table_select): the average of the parents referred to from each group.
     */
    public function testAverageGroupedByAReferringTable(): void
    {
        $parents = CustomTable::getEloquent('parent_table_select')->getValueModel()->get()->keyBy('id');
        $ids = [];
        foreach (CustomTable::getEloquent('child_table_select')->getValueModel()->get() as $child) {
            $date = $child->getValue('date');
            $parent_id = $child->getValue('parent_select_table', \Exceedone\Exment\Enums\ValueType::PURE_VALUE);
            if (isset($parent_id)) {
                $ids[$this->key(isset($date) ? \Carbon\Carbon::parse($date)->format('Y-m') : null)][$parent_id] = true;
            }
        }
        $expected = [];
        foreach ($ids as $key => $parent_ids) {
            foreach (array_keys($parent_ids) as $id) {
                $expected[$key][] = $parents->get($id)?->getValue('integer');
            }
        }
        $result = $this->summary([
            'target_table_name' => 'parent_table_select',
            'column_settings' => [['column_name' => 'date', 'reference_table' => 'child_table_select', 'reference_column' => 'parent_select_table', 'is_refer' => true, 'options' => ['view_group_condition' => 'ym']]],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $this->assertAverages(array_intersect_key($expected, $result), $result, 'parent_table_select.integer');
        $this->assertNotEmpty($result);
    }

    /**
     * Empty values are left out of the average (no 0): 10, 20 and an empty one average 15;
     * a group of empty values only has none.
     */
    public function testEmptyValuesAreLeftOut(): void
    {
        foreach ([10, 20, null] as $integer) {
            $this->createParent('parent_table', 'avg_some_empty', ['integer' => $integer]);
        }
        $this->createParent('parent_table', 'avg_all_empty', ['integer' => null]);

        $result = $this->summary([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $this->assertEquals(15, $result['avg_some_empty']);
        $this->assertNull($result['avg_all_empty']);
    }

    /**
     * An average is rounded to the column's decimal places, else 2: 10, 11 and 11 average
     * 10.666…, 10.67.
     */
    public function testAverageIsRoundedToTheColumnsDecimalPlaces(): void
    {
        foreach ([10, 11, 11] as $integer) {
            $this->createParent('parent_table', 'avg_round', ['integer' => $integer]);
        }
        $result = $this->summary([
            'target_table_name' => 'parent_table',
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ]);
        $this->assertEquals(10.67, $result['avg_round']);
    }

    /**
     * The ordering of the view (sort by the summary) works on the average.
     */
    public function testViewIsSortedByTheAverage(): void
    {
        foreach (['avg_sort_b' => [1, 1], 'avg_sort_a' => [5, 6], 'avg_sort_c' => [3]] as $group => $values) {
            foreach ($values as $integer) {
                $this->createParent('parent_table', $group, ['integer' => $integer]);
            }
        }
        list($custom_table, $custom_view) = $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'filter_settings' => [['column_name' => 'odd_even', 'filter_condition' => \Exceedone\Exment\Enums\FilterOption::LIKE, 'filter_value_text' => 'avg_sort_']],
            // the summary sorts first (the group column also sorts, 1st by default)
            'column_settings' => [['column_name' => 'odd_even', 'options' => ['sort_order' => 2]]],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG, 'options' => ['sort_order' => 1, 'sort_type' => '-1']]],
        ], ViewKindType::AGGREGATE);
        $query = $custom_table->getValueQuery();
        (new SummaryGrid($custom_table, $custom_view))->getQuery($query);
        $rows = $query->get();
        $this->assertSame(['avg_sort_a', 'avg_sort_c', 'avg_sort_b'], $rows->map(function ($row) {
            return array_values($row->getAttributes())[0];
        })->all(), 'highest average first: 5.5, 3, 1');
    }

    // SQL ------------------------------------------------------------------------------------

    /**
     * The SQL of an average: "1.0 *" keeps SQL Server from averaging / dividing integers as
     * integers (it would drop the decimals); a child relation's sub query selects the sum and
     * the count, never AVG.
     */
    public function testAverageSql(): void
    {
        $item = $this->averageItem('parent_table', 'integer');
        $this->assertMatchesRegularExpression('/^ROUND\(AVG\(1\.0 \* CAST\(.+ AS signed\)\), 2\)$/', $item->getSummaryWrapTableColumn());

        $columns = $item->getSummarySubQueryColumns();
        $this->assertSame([$item->sqlAsName(), $item->sqlAsName() . '_count'], array_keys($columns));
        $this->assertMatchesRegularExpression('/^SUM\(CAST\(.+\)\)$/', $columns[$item->sqlAsName()]);
        $this->assertMatchesRegularExpression('/^COUNT\(CAST\(.+\)\)$/', $columns[$item->sqlAsName() . '_count']);
        $this->assertStringNotContainsStringIgnoringCase('avg(', implode(' ', $columns));
        $this->assertMatchesRegularExpression('/^ROUND\(1\.0 \* SUM\(.+\) \/ NULLIF\(SUM\(.+_count`\), 0\), 2\)$/', $item->getSummaryJoinResultWrapTableColumn());

        // a decimal column: its own decimal places
        $decimal = CustomColumn::getEloquent('decimal', 'parent_table')->replicate();
        $decimal->setOption('decimal_digit', 3);
        $this->assertStringEndsWith(', 3)', $this->averageItem('parent_table', $decimal)->getSummaryWrapTableColumn());
        $decimal->setOption('decimal_digit', 0);
        $this->assertStringEndsWith(', 0)', $this->averageItem('parent_table', $decimal)->getSummaryWrapTableColumn());
    }

    /**
     * The other conditions keep their SQL: the sub query selects the one summary as before.
     */
    public function testOtherConditionsKeepTheirSql(): void
    {
        foreach ([SummaryCondition::SUM => 'sum', SummaryCondition::COUNT => 'count', SummaryCondition::MIN => 'min', SummaryCondition::MAX => 'max'] as $condition => $function) {
            $item = CustomItem::getItem(CustomColumn::getEloquent('integer', 'parent_table'))->options(['summary' => true, 'summary_condition' => $condition]);
            $sql = $item->getSummaryWrapTableColumn();
            $this->assertMatchesRegularExpression("/^$function\(CAST\(.+ AS signed\)\)$/", $sql);
            $this->assertSame([$item->sqlAsName() => $sql], $item->getSummarySubQueryColumns());
        }
    }

    // Display ------------------------------------------------------------------------------

    /**
     * An integer column's average keeps the decimals the query rounded it to, without
     * trailing zeros, with the column's thousands separator.
     */
    public function testIntegerAverageText(): void
    {
        $plain = CustomColumn::getEloquent('integer', 'parent_table')->replicate();
        $plain->setOption('number_format', 0);
        $formatted = CustomColumn::getEloquent('integer', 'parent_table')->replicate();
        $formatted->setOption('number_format', 1);

        $cases = [
            ['69.20', '69.2', '69.2'],
            ['60.00', '60', '60'],
            ['45.67', '45.67', '45.67'],
            ['1234.50', '1234.5', '1,234.5'],
            ['-12.50', '-12.5', '-12.5'],
            ['0.00', '0', '0'],
        ];
        foreach ($cases as [$value, $plain_text, $formatted_text]) {
            $this->assertSame($plain_text, $this->averageText($plain, $value), $value);
            $this->assertSame($formatted_text, $this->averageText($formatted, $value), "$value with number_format");
        }
        $this->assertNull($this->averageText($plain, null), 'no average');

        // the other conditions show an integer as before
        $item = CustomItem::getItem($formatted)->options(['summary' => true, 'summary_condition' => SummaryCondition::SUM]);
        $this->assertSame('1,235', $item->setCustomValue([$item->sqlAsName() => '1234.5'])->text());
        $item = CustomItem::getItem($plain)->options(['summary' => true, 'summary_condition' => SummaryCondition::SUM]);
        $this->assertSame('1234', $item->setCustomValue([$item->sqlAsName() => '1234'])->text());
    }

    // Chart ----------------------------------------------------------------------------------

    /**
     * A chart of an AVG view draws the averages; a multi-series chart of a view grouped by
     * more columns than it reads asks for a view grouped by its two columns instead of
     * drawing averages it cannot combine.
     */
    public function testChartOfAnAverageView(): void
    {
        foreach ([10, 20] as $integer) {
            $this->createParent('parent_table', 'avg_chart', ['integer' => $integer]);
        }
        $filter = [['column_name' => 'odd_even', 'filter_condition' => \Exceedone\Exment\Enums\FilterOption::EQ, 'filter_value_text' => 'avg_chart']];

        // single series: one group column
        list(, $view) = $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'filter_settings' => $filter,
            'column_settings' => [['column_name' => 'odd_even']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ], ViewKindType::AGGREGATE);
        $data = $this->invokeChart($this->chartItem($view, 'bar'), 'getAggregateData');
        $this->assertIsArray($data);
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $data['chart_data']);
        $this->assertEquals([15], $data['chart_data']->values()->all());

        // multi series: two group columns draw; a third asks for a regrouped view
        $two = $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'filter_settings' => $filter,
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'multiples_of_3']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ], ViewKindType::AGGREGATE)[1];
        $item = $this->chartItem($two, 'mbar');
        $this->assertFalse($this->invokeChart($item, 'isAverageOfExtraGroups'));
        $this->assertEquals([[15]], $this->invokeChart($item, 'getMultiSeriesData')['matrix']);

        $three = $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'filter_settings' => $filter,
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'multiples_of_3'], ['column_name' => 'text']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::AVG]],
        ], ViewKindType::AGGREGATE)[1];
        $item = $this->chartItem($three, 'mbar');
        $this->assertTrue($this->invokeChart($item, 'isAverageOfExtraGroups'));
        $this->assertSame(exmtrans('dashboard.message.need_average_groups'), $item->body());

        // SUM of the same three-column view still draws, folded as before
        $sum = $this->createCustomViewAll([
            'target_table_name' => 'parent_table',
            'filter_settings' => $filter,
            'column_settings' => [['column_name' => 'odd_even'], ['column_name' => 'multiples_of_3'], ['column_name' => 'text']],
            'summary_settings' => [['column_name' => 'integer', 'summary_condition' => SummaryCondition::SUM]],
        ], ViewKindType::AGGREGATE)[1];
        $item = $this->chartItem($sum, 'mbar');
        $this->assertFalse($this->invokeChart($item, 'isAverageOfExtraGroups'));
        $this->assertEquals([[30]], $this->invokeChart($item, 'getMultiSeriesData')['matrix']);
    }

    // Helpers --------------------------------------------------------------------------------

    /**
     * The aggregate view's rows as group key => summary value.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function summary(array $options): array
    {
        System::clearCache();
        list($custom_table, $custom_view) = $this->createCustomViewAll($options, ViewKindType::AGGREGATE);
        $query = $custom_table->getValueQuery();
        (new SummaryGrid($custom_table, $custom_view))->getQuery($query);
        $rows = $query->get();
        $result = [];
        foreach ($rows as $row) {
            $values = array_values($row->getAttributes());
            $result[$this->key($values[0])] = end($values);
        }
        return $result;
    }

    /**
     * Each group's average equals the exact average of its non-empty values, rounded to 2
     * places; a group without a value has none.
     *
     * @param array<string, array<int, mixed>> $expected group key => the values summarized
     * @param array<string, mixed> $result group key => the view's average
     */
    private function assertAverages(array $expected, array $result, string $message): void
    {
        $this->assertNotEmpty($expected, "$message: no test data");
        $this->assertSame(array_keys($expected), array_keys(array_intersect_key($expected, $result)), "$message: every group");
        foreach ($expected as $key => $values) {
            $values = array_values(array_filter($values, function ($v) {
                return isset($v) && $v !== '';
            }));
            if (count($values) === 0) {
                $this->assertNull($result[$key], "$message [$key]: no value, no average");
                continue;
            }
            $average = array_sum(array_map('floatval', $values)) / count($values);
            $this->assertEqualsWithDelta($average, (float) $result[$key], 0.005 + 1e-9, "$message [$key]");
            $this->assertMatchesRegularExpression('/^-?\d+(\.\d{1,2})?0*$/', (string) $result[$key], "$message [$key]: rounded to 2 places");
        }
    }

    private function key(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function createRecord(string $table_name, array $values): CustomValue
    {
        $this->be(\Exceedone\Exment\Model\LoginUser::find(\Exceedone\Exment\Tests\TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $record = CustomTable::getEloquent($table_name)->getValueModel();
        $record->setValue($values);
        $record->saved_notify(false);
        $record->save();
        return $record;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function createParent(string $table_name, string $group, array $values = []): CustomValue
    {
        return $this->createRecord($table_name, array_merge(['text' => $group, 'odd_even' => $group], $values));
    }

    private function createChild(string $table_name, CustomValue $parent, ?int $integer): CustomValue
    {
        $child = CustomTable::getEloquent($table_name)->getValueModel();
        $child->setValue(['text' => 'avg_child', 'integer' => $integer]);
        $child->parent_id = $parent->id;
        $child->parent_type = $parent->custom_table->table_name;
        $child->saved_notify(false);
        $child->save();
        return $child;
    }

    /**
     * @param CustomColumn|string $column a column of $table_name, or its name
     * @return mixed the column item, summarized as AVG
     */
    private function averageItem(string $table_name, $column)
    {
        $column = is_string($column) ? CustomColumn::getEloquent($column, $table_name) : $column;
        return CustomItem::getItem($column)->options(['summary' => true, 'summary_condition' => SummaryCondition::AVG]);
    }

    private function averageText(CustomColumn $column, ?string $value): ?string
    {
        $item = $this->averageItem('parent_table', $column);
        return $item->setCustomValue([$item->sqlAsName() => $value])->text();
    }

    private function chartItem(\Exceedone\Exment\Model\CustomView $view, string $chart_type): ChartItem
    {
        $summary = CustomViewSummary::where('custom_view_id', $view->id)->first();
        $x = CustomViewColumn::where('custom_view_id', $view->id)->orderBy('order')->first();
        $box = new DashboardBox();
        $box->options = [
            'target_table_id' => $view->custom_table_id,
            'target_view_id' => $view->id,
            'chart_type' => $chart_type,
            'chart_axisx' => ViewKindType::DEFAULT . '_' . $x->id,
            'chart_axisy' => ViewKindType::AGGREGATE . '_' . $summary->id,
        ];
        return new ChartItem($box);
    }

    private function invokeChart(ChartItem $item, string $method): mixed
    {
        $reflection = new \ReflectionMethod(ChartItem::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($item);
    }
}
