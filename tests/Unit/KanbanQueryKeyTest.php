<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DataItems\Grid\KanbanGrid;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Database\QueryException;

/**
 * Hybrid storage P1 slice 6: query key of the board's own read-only expressions
 * (KanbanGrid::columnKey(): sqlNumeric() sums, group / swimlane grouping, KPI expressions,
 * board-key and lane predicates, keyword search).
 *
 * Drives the production callers KanbanGrid::columnStats(), moreCards() and searchCards()
 * on the all-columns fixture table, with the columns indexed (index column) and with
 * index_enabled turned off in the options only (JSON path). Pins SQL fragments, bindings,
 * figures and rows (admin and a restricted user) and when the index-column DDL runs.
 * Expected strings are written out literally (MySQL / MariaDB grammar).
 */
class KanbanQueryKeyTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    protected function table(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
    }

    protected function db(): string
    {
        return getDBTableName($this->table());
    }

    protected function column(string $name): CustomColumn
    {
        return CustomColumn::getEloquent($name, $this->table());
    }

    protected function idx(string $name): string
    {
        return '`' . $this->db() . '`.`column_' . $this->column($name)->suuid . '`';
    }

    protected function jsonKey(string $name): string
    {
        return 'json_unquote(json_extract(`' . $this->db() . "`.`value`, '$.\"" . $name . "\"'))";
    }

    /** index_enabled turned off in the options only (no DDL, no model events). */
    protected function unindex(string $name): void
    {
        $column = $this->column($name);
        $options = $column->options ?? [];
        unset($options['index_enabled']);
        \DB::table('custom_columns')->where('id', $column->id)->update(['options' => json_encode($options)]);
        System::clearCache();
        System::clearRequestSession();
        $this->assertFalse((bool)$this->column($name)->index_enabled, "precondition: $name not indexed");
    }

    /**
     * @param array<string, mixed> $options column names for *_column_id
     * @param array<int, string> $card_columns
     */
    protected function kanbanView(array $options, array $card_columns = [], ?string $sort = null): CustomView
    {
        foreach ($options as $key => $value) {
            if (substr($key, -10) === '_column_id' && is_string($value)) {
                $options[$key] = $this->column($value)->id;
            }
        }
        $view = CustomView::create([
            'custom_table_id' => $this->table()->id,
            'view_view_name' => 'kanban-p6-' . uniqid(),
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::KANBAN,
            'options' => array_merge(['kanban_col_count' => 200], $options),
        ]);
        foreach ($card_columns as $index => $name) {
            CustomViewColumn::create([
                'custom_view_id' => $view->id,
                'view_column_type' => ConditionType::COLUMN,
                'view_column_table_id' => $this->table()->id,
                'view_column_target_id' => $this->column($name)->id,
                'order' => $index + 1,
            ]);
        }
        if (isset($sort)) {
            CustomViewSort::create([
                'custom_view_id' => $view->id,
                'view_column_type' => ConditionType::COLUMN,
                'view_column_table_id' => $this->table()->id,
                'view_column_target_id' => $this->column($sort)->id,
                'sort' => 1,
                'priority' => 1,
            ]);
        }
        return $view;
    }

    protected function grid(CustomView $view): KanbanGrid
    {
        System::clearRequestSession();
        System::clearCache();
        $view = CustomView::withoutGlobalScopes()->find($view->id);
        return new KanbanGrid($view->custom_table, $view);
    }

    /**
     * Runs the callback with the query log on; returns the queries whose sql contains $needle.
     *
     * @return array<int, array{query: string, bindings: array<mixed>}>
     */
    protected function queries(string $needle, \Closure $callback, &$result = null): array
    {
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $result = $callback();
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        return array_values(array_filter($log, function ($q) use ($needle) {
            return strpos($q['query'], $needle) !== false;
        }));
    }

    /** @return array<string, array<string, mixed>> */
    protected function flat(KanbanGrid $grid): array
    {
        return json_decode(json_encode($grid->columnStats()['flat']), true);
    }

    /** Board key of a row, the way the board files it. */
    protected function boardKey($raw, array $real_keys): string
    {
        $raw = is_null($raw) ? null : strval($raw);
        return (is_null($raw) || $raw === '' || !in_array($raw, $real_keys, true)) ? KanbanGrid::EMPTY_KEY : $raw;
    }

    /** @return array<int, string> */
    protected function realKeys(): array
    {
        $grid = $this->grid($this->kanbanView(['kanban_group_column_id' => 'select']));
        $method = new \ReflectionMethod($grid, 'getRealBoardKeys');
        $method->setAccessible(true);
        return $method->invoke($grid, $this->column('select'));
    }

    /**
     * Count / sum / load per board key, read row by row from the rows the current user sees.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function expected(string $sum, string $wip): array
    {
        $real = $this->realKeys();
        $result = [];
        foreach ($this->table()->getValueQuery()->get() as $row) {
            $key = $this->boardKey(array_get($row->value, 'select'), $real);
            $result[$key] = $result[$key] ?? ['total' => 0, 'sum' => 0.0, 'load' => 0.0];
            $result[$key]['total']++;
            $result[$key]['sum'] += floatval(array_get($row->value, $sum));
            $result[$key]['load'] += floatval(array_get($row->value, $wip));
        }
        ksort($result);
        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    protected function figures(array $flat): array
    {
        $result = [];
        foreach ($flat as $key => $stat) {
            $result[$key] = ['total' => $stat['total'], 'sum' => floatval($stat['sum']), 'load' => floatval($stat['load'])];
        }
        ksort($result);
        return $result;
    }

    protected function assertFigures(array $expected, array $actual): void
    {
        $this->assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $key => $stat) {
            $this->assertSame($stat['total'], $actual[$key]['total'], "total of $key");
            $this->assertEqualsWithDelta($stat['sum'], $actual[$key]['sum'], 0.0001, "sum of $key");
            $this->assertEqualsWithDelta($stat['load'], $actual[$key]['load'], 0.0001, "load of $key");
        }
    }

    protected function statsOptions(): array
    {
        return [
            'kanban_group_column_id' => 'select',
            'kanban_sum_column_id' => 'integer',
            'kanban_wip_column_id' => 'decimal',
        ];
    }

    // ------------------------------------------------------------- sqlNumeric

    public function testStatsSumIndexedNumberColumns(): void
    {
        $view = $this->kanbanView($this->statsOptions());
        $stats = $this->queries('kb_count', function () use ($view) {
            return $this->flat($this->grid($view));
        }, $flat);

        $this->assertCount(1, $stats);
        $sql = $stats[0]['query'];
        $this->assertStringContainsString('select ' . $this->idx('select') . ' as kb_key, count(*) as kb_count, ', $sql);
        $this->assertStringContainsString(', sum(' . $this->idx('integer') . ') as kb_sum, sum(' . $this->idx('decimal') . ') as kb_load from `' . $this->db() . '`', $sql);
        $this->assertStringEndsWith(' group by ' . $this->idx('select'), $sql);
        $this->assertSame([], $stats[0]['bindings']);
        $this->assertFigures($this->expected('integer', 'decimal'), $this->figures($flat));
    }

    public function testStatsSumJsonNumberColumns(): void
    {
        $this->unindex('integer');
        $this->unindex('decimal');
        $view = $this->kanbanView($this->statsOptions());
        $stats = $this->queries('kb_count', function () use ($view) {
            return $this->flat($this->grid($view));
        }, $flat);

        $this->assertCount(1, $stats);
        $sql = $stats[0]['query'];
        $this->assertStringContainsString(', sum(' . $this->jsonKey('integer') . ') as kb_sum, sum(' . $this->jsonKey('decimal') . ') as kb_load from `' . $this->db() . '`', $sql);
        $this->assertStringEndsWith(' group by ' . $this->idx('select'), $sql);
        $this->assertFigures($this->expected('integer', 'decimal'), $this->figures($flat));
    }

    /**
     * Blank, missing and non-choice values: same column, same figures, indexed or JSON.
     */
    public function testEmptyValuesGiveTheSameFiguresIndexedOrJson(): void
    {
        $model = $this->table()->getValueModel();
        $model->setValue(['integer' => 5])->save();
        $model = $this->table()->getValueModel();
        $model->setValue(['select' => 'not-a-choice', 'decimal' => 1.5])->save();
        $model = $this->table()->getValueModel();
        $model->setValue(['text' => 'p6-empty'])->save();

        $view = $this->kanbanView($this->statsOptions());
        $indexed = $this->figures($this->flat($this->grid($view)));
        $expected = $this->expected('integer', 'decimal');
        $this->assertArrayHasKey(KanbanGrid::EMPTY_KEY, $expected);
        $this->assertFigures($expected, $indexed);

        $this->unindex('select');
        $this->unindex('integer');
        $this->unindex('decimal');
        $this->assertFigures($expected, $this->figures($this->flat($this->grid($view))));
    }

    // ---------------------------------------------------------- grouping / KPI

    public function testStatsGroupByJsonColumnAndSwimlane(): void
    {
        $this->unindex('select');
        $view = $this->kanbanView($this->statsOptions() + ['kanban_swimlane_column_id' => 'select_valtext']);
        $stats = $this->queries('kb_count', function () use ($view) {
            return $this->grid($view)->columnStats();
        }, $result);

        $this->assertCount(1, $stats);
        $sql = $stats[0]['query'];
        $this->assertStringContainsString('select ' . $this->jsonKey('select') . ' as kb_key, count(*) as kb_count, ', $sql);
        $this->assertStringContainsString(', ' . $this->idx('select_valtext') . ' as kb_lane from `' . $this->db() . '`', $sql);
        $this->assertStringEndsWith(' group by ' . $this->jsonKey('select') . ', ' . $this->idx('select_valtext'), $sql);

        $lanes = json_decode(json_encode($result['lanes']), true);
        $flat = json_decode(json_encode($result['flat']), true);
        $this->assertFigures($this->expected('integer', 'decimal'), $this->figures($flat));
        $count = 0;
        foreach ($lanes as $per_key) {
            foreach ($per_key as $stat) {
                $count += $stat['total'];
            }
        }
        $this->assertSame($this->table()->getValueQuery()->count(), $count, 'every row in one lane');
    }

    public function testKpiExpressionsIndexedAndJson(): void
    {
        $options = $this->statsOptions() + [
            'kanban_assignee_column_id' => 'user',
            'kanban_limit_column_id' => 'date',
            'kanban_age_column_id' => 'date',
        ];
        $view = $this->kanbanView($options);
        $stats = $this->queries('kb_count', function () use ($view) {
            return $this->flat($this->grid($view));
        }, $indexed);
        $u = $this->idx('user');
        $d = $this->idx('date');
        $sql = $stats[0]['query'];
        $this->assertStringContainsString("sum(case when $u is null or $u = '' then 1 else 0 end) as kb_unassigned", $sql);
        $this->assertStringContainsString("sum(case when $d is not null and $d <> '' and $d < cast('", $sql);
        $this->assertStringContainsString("sum(case when $d is null or $d = '' then 0 when timestampdiff(second, $d, cast('", $sql);

        $unassigned = 0;
        $breach = 0;
        $now = \Carbon\Carbon::now()->format('Y-m-d H:i:s');
        foreach ($this->table()->getValueQuery()->get() as $row) {
            $user = array_get($row->value, 'user');
            $unassigned += (is_null($user) || $user === '') ? 1 : 0;
            $date = array_get($row->value, 'date');
            $breach += (!is_null($date) && $date !== '' && $date < $now) ? 1 : 0;
        }
        $this->assertSame($unassigned, array_sum(array_column($indexed, 'unassigned')));
        $this->assertSame($breach, array_sum(array_column($indexed, 'breach')));

        $this->unindex('user');
        $this->unindex('date');
        $stats = $this->queries('kb_count', function () use ($view) {
            return $this->flat($this->grid($view));
        }, $json);
        $u = $this->jsonKey('user');
        $d = $this->jsonKey('date');
        $sql = $stats[0]['query'];
        $this->assertStringContainsString("sum(case when $u is null or $u = '' then 1 else 0 end) as kb_unassigned", $sql);
        $this->assertStringContainsString("sum(case when $d is not null and $d <> '' and $d < cast('", $sql);
        foreach ($indexed as $key => $stat) {
            foreach (['total', 'unassigned', 'breach', 'agen'] as $name) {
                $this->assertSame($stat[$name], $json[$key][$name], "$name of $key");
            }
        }
    }

    // --------------------------------------------------- board keys and lanes

    public function testHiddenKeyPredicateInStats(): void
    {
        $hide = $this->realKeys()[0];
        $view = $this->kanbanView($this->statsOptions() + ['kanban_hide_keys' => [$this->column('select')->id . '::' . $hide]]);
        $stats = $this->queries('kb_count', function () use ($view) {
            return $this->flat($this->grid($view));
        }, $flat);

        $c = $this->idx('select');
        $this->assertStringEndsWith(" from `{$this->db()}` where ($c not in (?) or $c is null) and `{$this->db()}`.`deleted_at` is null group by $c", $stats[0]['query']);
        $this->assertSame([$hide], $stats[0]['bindings']);
        $expected = $this->expected('integer', 'decimal');
        unset($expected[$hide]);
        $this->assertFigures($expected, $this->figures($flat));
    }

    public function testMoreCardsNarrowsToBoardKeyAndLane(): void
    {
        $key = $this->realKeys()[0];
        $view = $this->kanbanView(['kanban_group_column_id' => 'select', 'kanban_swimlane_column_id' => 'select_valtext']);
        $lane = strval(array_get($this->table()->getValueQuery()->get()->first(function ($row) use ($key) {
            return strval(array_get($row->value, 'select')) === $key && !is_nullorempty(array_get($row->value, 'select_valtext'));
        })->value, 'select_valtext'));

        foreach ([false, true] as $json) {
            if ($json) {
                $this->unindex('select');
                $this->unindex('select_valtext');
            }
            $g = $json ? $this->jsonKey('select') : $this->idx('select');
            $l = $json ? $this->jsonKey('select_valtext') : $this->idx('select_valtext');
            $q = $this->queries(' limit ', function () use ($view, $key, $lane) {
                return $this->grid($view)->moreCards($key, 0, $lane);
            }, $result);
            $q = array_values(array_filter($q, function ($q) {
                return strpos($q['query'], 'from `' . $this->db() . '`') !== false && strpos($q['query'], $this->db() . '`.`id` asc') !== false;
            }));
            $this->assertCount(1, $q);
            $db = $this->db();
            $this->assertSame("select `$db`.* from `$db` where ($g in (?)) and $l = ? and `$db`.`deleted_at` is null order by `$db`.`id` asc limit 201 offset 0", $q[0]['query']);
            $this->assertSame([$key, $lane], $q[0]['bindings']);

            $expected = $this->table()->getValueQuery()->get()->filter(function ($row) use ($key, $lane) {
                return strval(array_get($row->value, 'select')) === $key && strval(array_get($row->value, 'select_valtext')) === $lane;
            })->pluck('id')->sort()->values()->all();
            $this->assertSame($expected, collect($result['cards'])->pluck('id')->all(), $json ? 'json' : 'indexed');

            // the empty board column and the empty lane
            $q = $this->queries(' limit ', function () use ($view) {
                return $this->grid($view)->moreCards(KanbanGrid::EMPTY_KEY, 0, KanbanGrid::EMPTY_KEY);
            });
            $q = array_values(array_filter($q, function ($q) {
                return strpos($q['query'], $this->db() . '`.`id` asc') !== false;
            }));
            // whereNull() on a JSON path is Laravel's JSON null check, not "is null" on the unquoted value
            $null = $json
                ? "(json_extract(`$db`.`value`, '$.\"select\"') is null OR json_type(json_extract(`$db`.`value`, '$.\"select\"')) = 'NULL')"
                : "$g is null";
            $this->assertStringContainsString("select `$db`.* from `$db` where ($null or $g not in (", $q[0]['query']);
            $this->assertStringEndsWith(")) and ($l is null or $l = '') and `$db`.`deleted_at` is null order by `$db`.`id` asc limit 201 offset 0", $q[0]['query']);
        }
    }

    // ---------------------------------------------------------------- sorting

    public function testMoreCardsKeepTheViewSortThenTheId(): void
    {
        $key = $this->realKeys()[0];
        $view = $this->kanbanView(['kanban_group_column_id' => 'select'], [], 'integer');
        $q = $this->queries(' limit ', function () use ($view, $key) {
            return $this->grid($view)->moreCards($key, 0);
        }, $result);
        $q = array_values(array_filter($q, function ($q) {
            return strpos($q['query'], $this->db() . '`.`id` asc') !== false;
        }));
        $this->assertCount(1, $q);
        $db = $this->db();
        $this->assertSame("select `$db`.* from `$db` where ({$this->idx('select')} in (?)) and `$db`.`deleted_at` is null order by CAST({$this->idx('integer')} AS signed) asc, `$db`.`id` asc limit 201 offset 0", $q[0]['query']);
        $this->assertSame([$key], $q[0]['bindings']);

        $expected = $this->table()->getValueQuery()->get()->filter(function ($row) use ($key) {
            return strval(array_get($row->value, 'select')) === $key;
        })->sort(function ($a, $b) {
            return [intval(array_get($a->value, 'integer')), $a->id] <=> [intval(array_get($b->value, 'integer')), $b->id];
        })->pluck('id')->values()->all();
        $this->assertSame($expected, collect($result['cards'])->pluck('id')->all());
    }

    // ---------------------------------------------------------------- keyword

    public function testSearchCardsOnIndexedAndJsonColumns(): void
    {
        $key = $this->realKeys()[0];
        $row = $this->table()->getValueQuery()->get()->first(function ($row) use ($key) {
            return strval(array_get($row->value, 'select')) === $key && !is_nullorempty(array_get($row->value, 'text'));
        });
        $keyword = strval(array_get($row->value, 'text'));
        $view = $this->kanbanView(['kanban_group_column_id' => 'select', 'kanban_col_count' => 500], ['text', 'textarea']);

        $q = $this->queries(' LIKE ', function () use ($view, $keyword) {
            return $this->grid($view)->searchCards($keyword);
        }, $result);
        $this->assertNotEmpty($q);
        $sql = $q[0]['query'];
        $this->assertStringContainsString($this->idx('text') . ' LIKE ?', $sql);
        $this->assertStringContainsString($this->jsonKey('textarea') . ' LIKE ?', $sql);
        $this->assertContains('%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword) . '%', $q[0]['bindings']);

        $ids = collect($result['cards'])->pluck('id')->sort()->values()->all();
        $expected = $this->table()->getValueQuery()->get()->filter(function ($row) use ($keyword) {
            return mb_stripos(strval(array_get($row->value, 'text')), $keyword) !== false
                || mb_stripos(strval(array_get($row->value, 'textarea')), $keyword) !== false;
        })->pluck('id')->sort()->values()->all();
        $this->assertContains($row->id, $ids);
        $this->assertSame($expected, $ids);

        $this->unindex('text');
        $q = $this->queries(' LIKE ', function () use ($view, $keyword) {
            return $this->grid($view)->searchCards($keyword);
        }, $result);
        $this->assertStringContainsString($this->jsonKey('text') . ' LIKE ?', $q[0]['query']);
        $this->assertSame($expected, collect($result['cards'])->pluck('id')->sort()->values()->all());
    }

    // --------------------------------------------------------- missing index

    /**
     * An indexed number column whose index column does not exist: alterColumn (here a counter)
     * runs once, from columnKey(), before the aggregate runs - and the aggregate still names
     * the index column, as before.
     */
    public function testMissingIndexColumnRunsTheDdlOnce(): void
    {
        $counting = new class () extends CustomColumn {
            public int $alterCount = 0;

            // @phpstan-ignore-next-line
            public function alterColumn($forceDropIndex = false)
            {
                $this->alterCount++;
            }
        };
        $counting->id = 999999;
        $counting->custom_table_id = $this->table()->id;
        $counting->column_name = 'p6_missing';
        $counting->column_type = ColumnType::INTEGER;
        $counting->suuid = 'p6missing0000000000';
        $counting->options = ['index_enabled' => '1'];

        $view = $this->kanbanView(['kanban_group_column_id' => 'select', 'kanban_sum_column_id' => 999999]);
        System::clearRequestSession();
        System::clearCache();
        $view = CustomView::withoutGlobalScopes()->find($view->id);
        $grid = new class ($view, $counting) extends KanbanGrid {
            protected $counting;

            public function __construct(CustomView $view, CustomColumn $counting)
            {
                parent::__construct($view->custom_table, $view);
                $this->counting = $counting;
            }

            protected function getColumnById($column_id)
            {
                return strval($column_id) === '999999' ? $this->counting : parent::getColumnById($column_id);
            }
        };

        try {
            $grid->columnStats();
            $this->fail('expected the missing index column to fail the aggregate');
        } catch (QueryException $ex) {
            $this->assertStringContainsString('sum(`' . $this->db() . '`.`column_p6missing0000000000`) as kb_sum', $ex->getSql());
        }
        $this->assertSame(1, $counting->alterCount);
    }

    // ------------------------------------------------------- restricted user

    public function testRestrictedUserFiguresCoverOnlyVisibleRows(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        $all = $this->table()->getValueModel()->query()->withoutGlobalScopes()->count();
        $visible = $this->table()->getValueQuery()->count();
        $this->assertGreaterThan(0, $visible);
        $this->assertLessThan($all, $visible, 'precondition: user2 sees only part of the table');

        $view = $this->kanbanView($this->statsOptions());
        $indexed = $this->figures($this->flat($this->grid($view)));
        $this->assertSame($visible, array_sum(array_column($indexed, 'total')));
        $this->assertFigures($this->expected('integer', 'decimal'), $indexed);

        $this->unindex('integer');
        $this->assertFigures($this->expected('integer', 'decimal'), $this->figures($this->flat($this->grid($view))));
    }
}
