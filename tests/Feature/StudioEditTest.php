<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Studio\BlankFormBuilder;
use Exceedone\Exment\Services\Studio\ScreenEditor;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Studio: a screen is changed in the builder it was made in (ScreenEditor).
 *
 * Each test makes its own table and deletes it.
 */
class StudioEditTest extends FeatureTestBase
{
    use TestTrait;

    protected const TABLE = 'studio_test_edit';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        $table = CustomTable::where('table_name', static::TABLE)->first();
        if (isset($table)) {
            $table->delete();
        }
        parent::tearDown();
    }

    /**
     * A table of four items made in the Studio, and its screen (the default form).
     *
     * @return array{0: CustomTable, 1: CustomForm, 2: array<int, CustomColumn>}
     */
    protected function made(): array
    {
        $table = BlankFormBuilder::build(BlankFormBuilder::normalize([
            'table_view_name' => 'edit test',
            'table_name' => static::TABLE,
            'fields' => [
                ['type' => ColumnType::TEXT, 'label' => 'title'],
                ['type' => ColumnType::SELECT, 'label' => 'status', 'choices' => "open\nclosed"],
                ['type' => ColumnType::TEXTAREA, 'label' => 'memo'],
                ['type' => ColumnType::INTEGER, 'label' => 'level'],
            ],
        ]));
        System::clearRequestSession();
        $table = CustomTable::getEloquent($table->id);
        $form = CustomForm::getDefault($table);
        /** @var array<int, CustomColumn> $columns */
        $columns = $table->custom_columns()->orderBy('id')->get()->all();
        return [$table, $form, $columns];
    }

    protected function formOf(int $id): CustomForm
    {
        $form = CustomForm::find($id);
        $this->assertInstanceOf(CustomForm::class, $form);
        return $form;
    }

    protected function formColumnOf(int $id): CustomFormColumn
    {
        $formColumn = CustomFormColumn::find($id);
        $this->assertInstanceOf(CustomFormColumn::class, $formColumn);
        return $formColumn;
    }

    protected function block(CustomForm $form): CustomFormBlock
    {
        /** @var CustomFormBlock $block */
        $block = $form->custom_form_blocks()->where('form_block_type', FormBlockType::DEFAULT)->firstOrFail();
        return $block;
    }

    /**
     * @param array<string, mixed> $data
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function save(CustomForm $form, array $data)
    {
        System::clearRequestSession();
        return $this->post(admin_urls('studio', 'screens', $form->id), $data + ['table_view_name' => 'edit test']);
    }

    public function testTheListOpensAScreenInTheBuilder(): void
    {
        [$table, $form] = $this->made();
        $response = $this->get(admin_url('studio'));
        $response->assertOk();
        $response->assertSee(admin_urls('studio', 'screens', $form->id, 'edit'), false);
        // the form setting page is still there
        $response->assertSee(admin_urls('form', $table->table_name, $form->id, 'edit'), false);

        System::clearRequestSession();
        $response = $this->get(admin_urls('studio', 'screens', $form->id, 'edit'));
        $response->assertOk();
        $response->assertSee('name="mode" value="edit"', false);
        $response->assertSee(exmtrans('studio.save_submit'));
        $response->assertSee(admin_urls('studio', 'screens', $form->id), false);
        // a calculation is not offered as a new part of a screen that exists
        $response->assertDontSee('data-type="calc"', false);
    }

    public function testTheBuilderOpensWithTheItemsWhereTheyAre(): void
    {
        [, $form, $columns] = $this->made();
        // title | status on the first row, memo under them
        foreach ($this->block($form)->custom_form_columns()->get() as $formColumn) {
            $index = array_search(intval($formColumn->form_column_target_id), array_map(function ($c) {
                return intval($c->id);
            }, $columns), true);
            $formColumn->row_no = $index < 2 ? 1 : 2;
            $formColumn->column_no = $index === 1 ? 2 : 1;
            $formColumn->width = $index < 2 ? 2 : 4;
            if ($index === 2) {
                $formColumn->options = ['visible_if' => ['all' => [['column' => $columns[1]->column_name, 'operator' => 'eq', 'value' => 'closed']]]];
            }
            $formColumn->save();
        }

        System::clearRequestSession();
        $screen = ScreenEditor::load($this->formOf(intval($form->id)));
        $this->assertSame(strval($form->form_view_name), $screen['table_view_name']);
        $this->assertSame([[2, 2], [4]], $screen['layout']['rows']);
        $ids = array_map(function ($field) {
            return $field['column_id'] ?? null;
        }, $screen['fields']);
        $this->assertSame([intval($columns[0]->id), intval($columns[1]->id), intval($columns[2]->id), intval($columns[3]->id)], $ids);
        $this->assertSame([['row' => 0, 'column' => 0], ['row' => 0, 'column' => 1], ['row' => 1, 'column' => 0], ['row' => 1, 'column' => 0]], $screen['layout']['places']);
        $this->assertSame([2 => ['target' => 1, 'operator' => 'eq', 'value' => 'closed']], $screen['conditions']);
        $this->assertFalse($screen['adjusted']);
    }

    public function testSavingMovesTakesOffAndKeepsTheConditions(): void
    {
        [$table, $form, $columns] = $this->made();
        $this->save($form, [
            'table_view_name' => '直した画面',
            'fields' => json_encode([
                ['column_id' => intval($columns[1]->id), 'row' => 0, 'column' => 0],
                ['column_id' => intval($columns[0]->id), 'row' => 0, 'column' => 1],
                ['column_id' => intval($columns[2]->id), 'row' => 1, 'column' => 0, 'visible_if' => ['target' => 0, 'operator' => 'eq', 'value' => 'closed']],
            ]),
            'layout' => json_encode([['widths' => [2, 2]], ['widths' => [4]]]),
        ])->assertRedirect(admin_urls('studio', 'screens', $form->id, 'edit'));
        $this->assertSame([], session('studio_errors', []));

        System::clearRequestSession();
        $form = $this->formOf(intval($form->id));
        $this->assertSame('直した画面', $form->form_view_name);
        $placed = $this->block($form)->custom_form_columns()->orderBy('order')->get()->map(function ($formColumn) {
            return [intval($formColumn->form_column_target_id), intval($formColumn->row_no), intval($formColumn->column_no), intval($formColumn->width)];
        })->all();
        $this->assertSame([
            [intval($columns[1]->id), 1, 1, 2],
            [intval($columns[0]->id), 1, 2, 2],
            [intval($columns[2]->id), 2, 1, 4],
        ], $placed);
        $memo = $this->block($form)->custom_form_columns()->where('form_column_target_id', $columns[2]->id)->firstOrFail();
        $this->assertEquals(['all' => [['column' => $columns[1]->column_name, 'operator' => 'eq', 'value' => 'closed']]], array_get($memo->options, 'visible_if'));
        // "level" is off the screen, and still a column of the table
        $this->assertNotNull(CustomColumn::find($columns[3]->id));
        $this->assertSame(4, $table->custom_columns()->count());

        // the condition is taken off again
        $this->save($form, [
            'fields' => json_encode([
                ['column_id' => intval($columns[1]->id)],
                ['column_id' => intval($columns[2]->id)],
            ]),
        ])->assertRedirect();
        System::clearRequestSession();
        $memo = $this->formColumnOf(intval($memo->id));
        $this->assertNull(array_get($memo->options, 'visible_if'));
        // the data can be entered with the changed screen
        System::clearRequestSession();
        $this->get(admin_urls('data', static::TABLE, 'create?formid=' . $form->suuid))->assertOk();
    }

    public function testAnItemTheBuilderDoesNotMakeIsKept(): void
    {
        [, $form, $columns] = $this->made();
        $header = new CustomFormColumn();
        $header->custom_form_block_id = $this->block($form)->id;
        $header->form_column_type = FormColumnType::OTHER;
        $header->form_column_target_id = 1; // header
        $header->options = ['text' => '基本の情報'];
        $header->row_no = 1;
        $header->column_no = 1;
        $header->width = 4;
        $header->order = 0;
        $header->save();

        System::clearRequestSession();
        $screen = ScreenEditor::load($this->formOf(intval($form->id)));
        $kept = array_values(array_filter($screen['fields'], function ($field) {
            return isset($field['form_column_id']);
        }));
        $this->assertCount(1, $kept);
        $this->assertSame(intval($header->id), $kept[0]['form_column_id']);
        $this->assertStringContainsString('基本の情報', $kept[0]['text']);

        // moved under the title: kept with its text
        $this->save($form, ['fields' => json_encode([
            ['column_id' => intval($columns[0]->id)],
            ['form_column_id' => intval($header->id)],
            ['column_id' => intval($columns[1]->id)],
        ])])->assertRedirect();
        $this->assertSame([], session('studio_errors', []));
        System::clearRequestSession();
        $header = $this->formColumnOf(intval($header->id));
        $this->assertSame(2, intval($header->order));
        $this->assertSame('基本の情報', array_get($header->options, 'text'));

        // taken off
        $this->save($form, ['fields' => json_encode([['column_id' => intval($columns[0]->id)]])])->assertRedirect();
        $this->assertNull(CustomFormColumn::find($header->id));
    }

    public function testANewPartMakesItsColumn(): void
    {
        [$table, $form, $columns] = $this->made();
        $this->save($form, ['fields' => json_encode([
            ['column_id' => intval($columns[0]->id)],
            ['type' => ColumnType::DATE, 'label' => 'due', 'required' => false],
            ['type' => ColumnType::SELECT, 'label' => 'rank', 'choices' => "A\nB"],
        ])])->assertRedirect();
        $this->assertSame([], session('studio_errors', []));

        System::clearRequestSession();
        $due = CustomColumn::getEloquent('due', $table);
        $rank = CustomColumn::getEloquent('rank', $table);
        $this->assertNotNull($due);
        $this->assertNotNull($rank);
        $this->assertSame(ColumnType::DATE, $due->column_type);
        $this->assertSame("A\nB", $rank->getOption('select_item'));
        $targets = $this->block($this->formOf(intval($form->id)))->custom_form_columns()->orderBy('order')->pluck('form_column_target_id')->map(function ($id) {
            return intval($id);
        })->all();
        $this->assertSame([intval($columns[0]->id), intval($due->id), intval($rank->id)], $targets);
        // in the list too
        $view = CustomView::getDefault($table, false);
        $this->assertTrue($view->custom_view_columns()->where('view_column_type', ConditionType::COLUMN)->where('view_column_target_id', $due->id)->exists());

        // a name the table has already is a mistake
        $this->save($form, ['fields' => json_encode([
            ['type' => ColumnType::TEXT, 'label' => 'x', 'column_name' => 'title'],
        ])])->assertRedirect();
        $this->assertSame(exmtrans('studio.error.column_name_used'), session('studio_errors')['fields.0.column_name'] ?? null);
    }

    public function testMistakesChangeNothing(): void
    {
        [$table, $form, $columns] = $this->made();
        $before = $this->block($form)->custom_form_columns()->count();
        $this->save($form, [
            'table_view_name' => '',
            'fields' => json_encode([
                ['column_id' => intval($columns[0]->id)],
                ['column_id' => intval($columns[0]->id)],
                ['type' => BlankFormBuilder::CALC, 'label' => 'sum', 'formula' => '1+1'],
                ['form_column_id' => 999999],
            ]),
        ])->assertRedirect();
        $errors = session('studio_errors');
        $this->assertArrayHasKey('table_view_name', $errors);
        $this->assertSame(exmtrans('studio.error.column_placed'), $errors['fields.1.column_id'] ?? null);
        $this->assertSame(exmtrans('studio.error.field_type'), $errors['fields.2.type'] ?? null);
        $this->assertSame(exmtrans('studio.error.column'), $errors['fields.3.form_column_id'] ?? null);

        $this->save($form, ['fields' => json_encode([])])->assertRedirect();
        $this->assertSame(exmtrans('studio.error.no_field'), session('studio_errors')['fields'] ?? null);

        System::clearRequestSession();
        $this->assertSame($before, $this->block($this->formOf(intval($form->id)))->custom_form_columns()->count());
        $this->assertSame(4, $table->custom_columns()->count());
    }

    public function testARowSplitInAnotherWayIsShownAsTheNearest(): void
    {
        [, $form] = $this->made();
        foreach ($this->block($form)->custom_form_columns()->get() as $formColumn) {
            $formColumn->width = 3;
            $formColumn->save();
        }
        System::clearRequestSession();
        $screen = ScreenEditor::load($this->formOf(intval($form->id)));
        $this->assertSame([[4]], $screen['layout']['rows']);
        $this->assertTrue($screen['adjusted']);
    }

    public function testAButtonCanBeAddedWhenChanging(): void
    {
        [$table, $form, $columns] = $this->made();
        $this->save($form, [
            'fields' => json_encode([
                ['column_id' => intval($columns[0]->id)],
                ['column_id' => intval($columns[1]->id)],
            ]),
            'actions' => json_encode([['label' => '閉じる', 'target' => 1, 'value' => 'closed']]),
        ])->assertRedirect();
        $this->assertSame([], session('studio_errors', []));
        $this->assertSame(['閉じる'], CustomOperation::where('custom_table_id', $table->id)->pluck('operation_name')->all());
    }

    public function testAUserWhoMayNotChangeTheFormsCannotOpenIt(): void
    {
        [, $form] = $this->made();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        // a new table made by the administrator: no role gives user2 its forms
        $this->get(admin_urls('studio', 'screens', $form->id, 'edit'), ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
        System::clearRequestSession();
        $this->post(admin_urls('studio', 'screens', $form->id), [
            'table_view_name' => 'x',
            'fields' => json_encode([]),
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
        System::clearRequestSession();
        $this->assertSame(strval($form->form_view_name), $this->formOf(intval($form->id))->form_view_name);
    }
}
