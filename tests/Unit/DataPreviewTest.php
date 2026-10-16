<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\CustomValueController;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Layout\Column;
use ExmentAdminCore\Admin\Layout\Content;
use ExmentAdminCore\Admin\Layout\Row;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

/**
 * The data preview, list review finding P-1: it shows what the detail page
 * would show. A column the form hides stays hidden, and a child table's
 * block - whose rows the detail page lists on their own - is left out
 * instead of being filled with the parent record's values.
 *
 * Everything a test writes is rolled back.
 */
class DataPreviewTest extends UnitTestBase
{
    use DatabaseTransactions;

    private const TABLE = 'parent_table';

    private const CHILD_TABLE = 'child_table';

    private const CHILD_BLOCK_NAME = 'preview-child-block';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'admin');
        System::clearRequestSession();
        System::clearCache();

        \Admin::bootstrap();
        Initialize::registeredLaravelAdmin();
        view()->share('errors', new ViewErrorBag());
    }

    public function testPreviewShowsWhatTheDetailPageShows(): void
    {
        $table = CustomTable::getEloquent(self::TABLE);
        $child = CustomTable::getEloquent(self::CHILD_TABLE);
        $form = $table->getPriorityForm();

        $block = CustomFormBlock::where('custom_form_id', $form->id)
            ->where('form_block_type', FormBlockType::DEFAULT)
            ->first();
        $this->assertNotNull($block, 'fixture: the form has a default block');

        $hidden = $this->formColumn($block, CustomColumn::getEloquent('text', $table));
        $this->formColumn($block, CustomColumn::getEloquent('index_text', $table));
        \DB::table('custom_form_columns')->where('id', $hidden->id)
            ->update(['options' => json_encode(array_merge($hidden->options ?? [], ['hidden' => 1]))]);

        $childBlock = CustomFormBlock::create([
            'custom_form_id' => $form->id,
            'form_block_view_name' => self::CHILD_BLOCK_NAME,
            'form_block_type' => FormBlockType::ONE_TO_MANY,
            'form_block_target_table_id' => $child->id,
            'available' => 1,
        ]);
        CustomFormColumn::create([
            'custom_form_block_id' => $childBlock->id,
            'form_column_type' => FormColumnType::COLUMN,
            'form_column_target_id' => CustomColumn::getEloquent('index_text', $child)->id,
            'row_no' => 1,
            'column_no' => 1,
            'order' => 1,
        ]);
        System::clearRequestSession();
        System::clearCache();

        $groups = $this->preview($table, ['text' => 'hidden-value-p1', 'index_text' => 'shown-value-p1']);

        $titles = array_column($groups, 'title');
        $this->assertNotContains(self::CHILD_BLOCK_NAME, $titles, 'a child block is not filled from the parent record');

        $labels = [];
        $html = '';
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $labels[] = $row['label'];
                $html .= strval($row['html']);
            }
        }
        $this->assertContains(CustomColumn::getEloquent('index_text', $table)->column_view_name, $labels);
        $this->assertNotContains(CustomColumn::getEloquent('text', $table)->column_view_name, $labels, 'a column the form hides is not shown');
        $this->assertStringContainsString('shown-value-p1', $html);
        $this->assertStringNotContainsString('hidden-value-p1', $html, 'the value of a hidden column never reaches the page');
        $this->assertSame(1, substr_count($html, 'shown-value-p1'), 'a value is shown once, not again under a child block');
    }

    // helpers -----------------------------------------------------------

    private function formColumn(CustomFormBlock $block, CustomColumn $column): CustomFormColumn
    {
        $form_column = CustomFormColumn::where('custom_form_block_id', $block->id)
            ->where('form_column_type', FormColumnType::COLUMN)
            ->where('form_column_target_id', $column->id)
            ->first();
        $this->assertNotNull($form_column, "fixture: the form shows {$column->column_name}");

        return $form_column;
    }

    /**
     * Post a value to the preview of a new record and read back the groups
     * the page is drawn from.
     *
     * @param array<string, mixed> $value
     * @return array<int, array{title: mixed, rows: array<int, array{label: mixed, html: mixed}>}>
     */
    private function preview(CustomTable $table, array $value): array
    {
        $request = Request::create(admin_urls('data', $table->table_name, 'preview'), 'POST', ['value' => $value]);
        $this->app->instance('request', $request);

        $content = (new CustomValueController($table, $request))->preview($request, new Content(), $table->table_name);
        $this->assertInstanceOf(Content::class, $content);

        foreach ((new \ReflectionProperty(Content::class, 'rows'))->getValue($content) as $row) {
            foreach ((new \ReflectionProperty(Row::class, 'columns'))->getValue($row) as $column) {
                foreach ((new \ReflectionProperty(Column::class, 'contents'))->getValue($column) as $item) {
                    if ($item instanceof View && $item->name() === 'exment::form.preview') {
                        return $item->getData()['groups'];
                    }
                }
            }
        }
        $this->fail('the preview page is drawn');
    }
}
