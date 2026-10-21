<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * FIXED (since v6): the view filter "exists / not exists" on a multiple-value column
 * (select_multiple). MariaDB and SQL Server keep the stored list as json_encode writes it —
 * every non-ASCII character as \uXXXX and "/" as "\/" — and the filter searched it for the
 * value escaped by unicode_encode(), which escapes 3-byte characters only and leaves "/"
 * alone: "東京" matched, "café" and "東京/大阪" never did ("not exists" kept them all).
 *
 * On MySQL the list is stored unescaped; the test passes there before and after the fix.
 * The rows and the view are saved into the test data's tables (no DDL) and rolled back.
 */
class CustomViewFilterMultipleEscapeTest extends UnitTestBase
{
    use CustomViewTrait;
    use DatabaseTransactions;

    /** @var array<string, int> */
    private $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $table = CustomTable::getEloquent('all_columns_table_fortest');
        if ($table === null) {
            $this->markTestSkipped('needs all_columns_table_fortest (exment:inittest)');
        }
        $this->be(\Exceedone\Exment\Model\LoginUser::find(1));
        foreach (['slash' => ['東京/大阪', 'foo'], 'accent' => ['café'], 'kanji' => ['東京']] as $key => $values) {
            $row = $table->getValueModel();
            $row->setValue('select_multiple', $values);
            $row->saved_notify(false);
            $row->save();
            $this->ids[$key] = (int) $row->id;
        }
    }

    public function test_exists_finds_a_value_with_a_slash_or_an_accent(): void
    {
        $this->assertSame([$this->ids['slash']], $this->filtered(FilterOption::SELECT_EXISTS, '東京/大阪'));
        $this->assertSame([$this->ids['accent']], $this->filtered(FilterOption::SELECT_EXISTS, 'café'));
        $this->assertSame([$this->ids['kanji']], $this->filtered(FilterOption::SELECT_EXISTS, '東京'), 'a 3-byte value, as before');
        $this->assertSame([$this->ids['slash']], $this->filtered(FilterOption::SELECT_EXISTS, 'foo'), 'an ASCII value, as before');
    }

    public function test_not_exists_leaves_out_a_value_with_a_slash_or_an_accent(): void
    {
        $this->assertSame([$this->ids['accent'], $this->ids['kanji']], $this->filtered(FilterOption::SELECT_NOT_EXISTS, '東京/大阪'));
        $this->assertSame([$this->ids['slash'], $this->ids['kanji']], $this->filtered(FilterOption::SELECT_NOT_EXISTS, 'café'));
    }

    /**
     * The ids of this test's rows a view filtered by $condition $value keeps.
     *
     * @return array<int, int>
     */
    private function filtered(int $condition, string $value): array
    {
        /** @var CustomTable $table */
        /** @var CustomView $view */
        [$table, $view] = $this->createCustomViewAll([
            'target_table_name' => 'all_columns_table_fortest',
            'filter_settings' => [['column_name' => 'select_multiple', 'filter_condition' => $condition, 'filter_value_text' => $value]],
        ]);
        $db = getDBTableName($table);
        $query = $table->getValueQuery()->whereIn("$db.id", array_values($this->ids));
        $view->filterModel($query);
        return $query->orderBy("$db.id")->pluck("$db.id")->map(function ($id) {
            return (int) $id;
        })->values()->all();
    }
}
