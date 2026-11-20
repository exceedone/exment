<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Studio\BlankFormBuilder;
use Exceedone\Exment\Services\Studio\ExistingTableFormBuilder;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Studio, phase 2 (ISS00231): make an input screen from a blank page.
 *
 * Making a table creates its data table (DDL), which a test transaction cannot
 * roll back, so each test deletes the tables it made.
 */
class StudioBlankFormTest extends FeatureTestBase
{
    use TestTrait;

    /**
     * @var array<int, string>
     */
    protected $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $name) {
            System::clearRequestSession();
            $table = CustomTable::where('table_name', $name)->first();
            if (isset($table)) {
                $table->delete();
            }
        }
        parent::tearDown();
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $extra
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postScreen(array $fields, array $extra = [])
    {
        System::clearRequestSession();
        return $this->post(admin_url('studio'), array_merge([
            'table_view_name' => 'スタジオのテスト',
            'table_name' => 'studio_test_screen',
            'fields' => json_encode($fields),
        ], $extra));
    }

    public function testTheBuilderPageShowsThePalette(): void
    {
        $response = $this->get(admin_url('studio/create'));
        $response->assertOk();
        $response->assertSee(exmtrans('studio.create_header'));
        foreach (array_keys(BlankFormBuilder::FIELD_TYPES) as $type) {
            $response->assertSee('data-type="' . $type . '"', false);
        }

        // and the studio page links to it
        $this->get(admin_url('studio'))->assertSee(admin_urls('studio', 'create'), false);
    }

    public function testTheItemsBecomeTheTableColumnsFormAndView(): void
    {
        $this->made[] = 'studio_test_screen';
        $parent = Menu::where('menu_type', MenuType::PARENT_NODE)->firstOrFail();

        $response = $this->postScreen([
            ['type' => ColumnType::TEXT, 'label' => 'Company name', 'column_name' => '', 'required' => true],
            ['type' => ColumnType::SELECT, 'label' => '区分', 'column_name' => '', 'choices' => "A\nB\nC"],
            ['type' => ColumnType::DATE, 'label' => '契約日', 'column_name' => 'contract_date'],
        ], ['menu_parent_id' => $parent->id]);
        $response->assertRedirect(admin_urls('data', 'studio_test_screen', 'create'));

        System::clearRequestSession();
        $table = CustomTable::getEloquent('studio_test_screen');
        $this->assertNotNull($table);
        $this->assertSame('スタジオのテスト', $table->table_view_name);

        $columns = $table->custom_columns()->get();
        // a name from an English label, a numbered name for a Japanese one, and the given name
        $this->assertSame(['company_name', 'field_2', 'contract_date'], $columns->pluck('column_name')->all());
        $this->assertSame([ColumnType::TEXT, ColumnType::SELECT, ColumnType::DATE], $columns->pluck('column_type')->all());
        $this->assertSame('1', strval($columns[0]->getOption('required')));
        $this->assertSame('1', strval($columns[0]->getOption('use_label_flg')));
        $this->assertSame("A\nB\nC", $columns[1]->getOption('select_item'));

        // the default form has the columns in the order they were placed
        $form = CustomForm::where('custom_table_id', $table->id)->firstOrFail();
        $block = $form->custom_form_blocks()->where('form_block_type', FormBlockType::DEFAULT)->firstOrFail();
        $toInt = function ($id) {
            return intval($id);
        };
        $this->assertSame(
            $columns->pluck('id')->map($toInt)->all(),
            $block->custom_form_columns()->orderBy('order')->pluck('form_column_target_id')->map($toInt)->all()
        );
        $this->assertTrue(CustomView::where('custom_table_id', $table->id)->exists());

        $this->assertTrue(Menu::where('parent_id', $parent->id)->where('menu_type', MenuType::TABLE)->where('menu_target', $table->id)->exists());

        // the data can be entered at once
        $this->get(admin_urls('data', 'studio_test_screen', 'create'))->assertOk();
    }

    public function testMistakesAreShownAndNothingIsMade(): void
    {
        $response = $this->from(admin_url('studio/create'))->postScreen([
            ['type' => ColumnType::TEXT, 'label' => '', 'column_name' => ''],
            ['type' => ColumnType::SELECT, 'label' => '区分', 'column_name' => 'id', 'choices' => ''],
            ['type' => 'auto_number', 'label' => '番号', 'column_name' => 'no'],
        ], ['table_view_name' => '', 'table_name' => '1bad']);
        $response->assertRedirect(admin_url('studio/create'));

        $errors = session('studio_errors');
        $this->assertIsArray($errors);
        foreach (['table_view_name', 'table_name', 'fields.0.label', 'fields.1.column_name', 'fields.1.choices', 'fields.2.type'] as $key) {
            $this->assertArrayHasKey($key, $errors);
        }
        $this->assertFalse(CustomTable::where('table_name', '1bad')->exists());

        // an empty screen, and a table name in use
        $this->postScreen([], ['table_name' => 'parent_table']);
        $errors = session('studio_errors');
        $this->assertArrayHasKey('fields', $errors);
        $this->assertArrayHasKey('table_name', $errors);
    }

    public function testAnEmptyTableNameIsMadeAutomatically(): void
    {
        $screen = BlankFormBuilder::normalize([
            'table_view_name' => 'x',
            'table_name' => '',
            'fields' => [
                ['type' => ColumnType::TEXT, 'label' => 'Name'],
                ['type' => ColumnType::TEXT, 'label' => 'name'],
            ],
        ]);
        $this->assertMatchesRegularExpression('/^screen_\d+$/', $screen['table_name']);
        $this->assertFalse(CustomTable::where('table_name', $screen['table_name'])->exists());
        // two labels that give one name get different names
        $this->assertSame(['name', 'name_2'], array_column($screen['fields'], 'column_name'));
        $this->assertSame([], BlankFormBuilder::validate($screen));
    }

    public function testAScreenIsAddedToATableThatExistsWithoutMakingATable(): void
    {
        $table = CustomTable::getEloquent('parent_table');
        $this->assertNotNull($table);
        $columns = $table->custom_columns()->get();
        $this->assertGreaterThanOrEqual(2, $columns->count());
        // two columns, the second one first
        $placed = [intval($columns[1]->id), intval($columns[0]->id)];
        $tables = CustomTable::count();
        $forms = CustomForm::where('custom_table_id', $table->id)->count();

        System::clearRequestSession();
        $response = $this->post(admin_url('studio'), [
            'mode' => 'existing',
            'table_view_name' => 'スタジオの追加の画面',
            'table_id' => $table->id,
            'fields' => json_encode([['column_id' => $placed[0]], ['column_id' => $placed[1]]]),
        ]);

        $form = CustomForm::where('custom_table_id', $table->id)->where('form_view_name', 'スタジオの追加の画面')->firstOrFail();
        $response->assertRedirect(admin_urls('data', 'parent_table', 'create?formid=' . $form->suuid));
        $this->assertSame($tables, CustomTable::count());
        $this->assertSame($forms + 1, CustomForm::where('custom_table_id', $table->id)->count());
        $this->assertFalse(boolval($form->default_flg));

        $block = $form->custom_form_blocks()->where('form_block_type', FormBlockType::DEFAULT)->firstOrFail();
        $toInt = function ($id) {
            return intval($id);
        };
        $this->assertSame($placed, $block->custom_form_columns()->orderBy('order')->pluck('form_column_target_id')->map($toInt)->all());

        // the data can be entered with the new screen
        $this->get(admin_urls('data', 'parent_table', 'create?formid=' . $form->suuid))->assertOk();

        $form->delete();
    }

    public function testMistakesOfAScreenForATableThatExists(): void
    {
        $table = CustomTable::getEloquent('parent_table');
        $other = CustomTable::getEloquent('child_table');
        $this->assertNotNull($table);
        $this->assertNotNull($other);
        $own = intval($table->custom_columns()->firstOrFail()->id);
        $foreign = intval($other->custom_columns()->firstOrFail()->id);
        $forms = CustomForm::count();

        System::clearRequestSession();
        $this->from(admin_url('studio/create'))->post(admin_url('studio'), [
            'mode' => 'existing',
            'table_view_name' => 'x',
            'table_id' => $table->id,
            'fields' => json_encode([['column_id' => $own], ['column_id' => $own], ['column_id' => $foreign]]),
        ])->assertRedirect(admin_url('studio/create'));
        $errors = session('studio_errors');
        $this->assertIsArray($errors);
        $this->assertArrayHasKey('fields.1.column_id', $errors);
        $this->assertArrayHasKey('fields.2.column_id', $errors);

        System::clearRequestSession();
        $this->post(admin_url('studio'), ['mode' => 'existing', 'table_view_name' => 'x', 'table_id' => '', 'fields' => '[]']);
        $this->assertArrayHasKey('table_id', session('studio_errors'));

        $this->assertSame($forms, CustomForm::count());
    }

    public function testTheRequiredColumnsOfATableAreMarked(): void
    {
        // a screen of a table saves the data with a left-out required column empty,
        // so the builder marks the required columns and warns when one is not placed
        $this->made[] = 'studio_test_required';
        BlankFormBuilder::build(BlankFormBuilder::normalize([
            'table_view_name' => 'required test',
            'table_name' => 'studio_test_required',
            'fields' => [
                ['type' => ColumnType::TEXT, 'label' => 'title', 'required' => true],
                ['type' => ColumnType::TEXT, 'label' => 'memo'],
            ],
        ]));

        System::clearRequestSession();
        $table = collect(ExistingTableFormBuilder::tables())->firstWhere('table_view_name', 'required test');
        $this->assertNotNull($table);
        $required = array_column($table['columns'], 'required', 'column_view_name');
        $this->assertSame(['title' => true, 'memo' => false], $required);

        $response = $this->get(admin_url('studio/create'));
        // the warning text is in the JSON of the page script (\u escaped), so look for its key
        $response->assertSee('"missing_required":', false);
        $response->assertSee('"required":true', false);
    }

    public function testTheBuilderTellsWhatSavingMakes(): void
    {
        $response = $this->get(admin_url('studio/create'));
        $response->assertOk();
        // both ways are offered to the admin, and the page says what each makes
        $response->assertSee('name="mode" value="new"', false);
        $response->assertSee('name="mode" value="existing"', false);
        $response->assertSee(exmtrans('studio.mode_existing_help'));
        $response->assertSee('id="exm-builder-notice"', false);
    }

    public function testAUserWithoutTheTablePermissionCannotMakeATable(): void
    {
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->be($user);
        System::clearRequestSession();
        if (BlankFormBuilder::available()) {
            $this->markTestSkipped('user2 has the table permission in this test data');
        }

        // making a new table is refused
        $this->post(admin_url('studio'), [
            'mode' => 'new',
            'table_view_name' => 'x',
            'table_name' => 'studio_test_denied',
            'fields' => json_encode([['type' => ColumnType::TEXT, 'label' => 'a']]),
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
        $this->assertFalse(CustomTable::where('table_name', 'studio_test_denied')->exists());

        // the builder opens only for a user who may make a form of some table, and then
        // offers only the way of a table that exists
        System::clearRequestSession();
        $response = $this->get(admin_url('studio/create'), ['X-Requested-With' => 'XMLHttpRequest']);
        System::clearRequestSession();
        if (!ExistingTableFormBuilder::available()) {
            $response->assertForbidden();
            return;
        }
        $response->assertOk();
        $response->assertDontSee('name="mode" value="new"', false);
        $response->assertSee('name="mode" value="existing"', false);
        // the table picker offers exactly the tables the user may make a form of
        $this->assertSame(1, preg_match('/<select id="exm-builder-table-id"[^>]*>(.*?)<\/select>/s', (string) $response->getContent(), $picker));
        preg_match_all('/<option value="(\d+)"/', strval($picker[1] ?? ''), $offered);
        $allowed = CustomTable::all()->filter(function ($table) {
            return $table->showlist_flg && $table->hasPermission(\Exceedone\Exment\Enums\Permission::EDIT_CUSTOM_FORM);
        })->pluck('id')->map(function ($id) {
            return strval($id);
        })->sort()->values()->all();
        $offeredIds = $offered[1];
        sort($offeredIds);
        $this->assertSame($allowed, $offeredIds);
    }
}
