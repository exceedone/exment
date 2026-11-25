<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DataItems\Grid\KanbanGrid;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Form\Navbar\WorkflowTaskNav;
use Exceedone\Exment\Grid\Tools\GridInlineEditor;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Validator\KanbanWipLimitRule;
use Illuminate\Support\Facades\Notification;

/**
 * The bug reports of GitHub of the evening of 2026-11-25 (#2178, #2179, #2181, #2182, #2184,
 * #2186, #2187): one test each or two.
 */
class GithubReports2026NovEveningTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'admin');
        System::clearRequestSession();
        System::clearCache();
    }

    protected function tearDown(): void
    {
        request()->replace([]);
        System::clearRequestSession();
        parent::tearDown();
    }

    private function read(string $relative): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        $this->assertNotFalse($content, $relative);
        return strval($content);
    }

    /**
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function invoke(object $object, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }

    private function runMigration(string $file): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/' . $file;
        $migration->up();
        System::clearRequestSession();
    }

    // ---------------------------------------------------------------- #2178

    /**
     * #2178: a site updated from 7.0.x gets "Display presets" under "Settings: Data & Screens",
     * after "Notify", as a new site; the other menus stay where the site put them.
     */
    public function testUpdateAddsTheDisplayPresetsMenu(): void
    {
        $file = '2026_12_05_000000_add_cell_style_preset_menu.php';
        Menu::where('menu_type', 'system')->where(function ($query) {
            $query->where('menu_name', 'cell_style_preset')->orWhere('menu_target', 'cell_style_preset')->orWhere('uri', 'cell_style_preset');
        })->delete();
        $parent = Menu::where('menu_type', 'parent_node')->where('menu_name', 'admin_data')->first()
            ?? Menu::where('menu_type', 'parent_node')->where('menu_name', 'admin')->first();
        $this->assertNotNull($parent, 'the test site has a menu of the admin settings');
        $others = Menu::where('menu_name', '<>', 'cell_style_preset')->pluck('parent_id', 'id')->all();

        $this->runMigration($file);

        $menu = Menu::where('menu_type', 'system')->where('menu_name', 'cell_style_preset')->first();
        $this->assertNotNull($menu, 'the menu is added');
        $this->assertSame(intval($parent->id), intval($menu->parent_id));
        $this->assertSame('cell_style_preset', $menu->uri);
        $this->assertSame('fa-paint-brush', $menu->icon);
        $notify = Menu::where('menu_type', 'system')->where('menu_name', 'notify')->first();
        if (isset($notify) && intval($notify->parent_id) === intval($parent->id)) {
            $this->assertSame(intval($notify->order) + 1, intval($menu->order), 'right after Notify');
        }
        $this->assertSame($others, Menu::where('menu_name', '<>', 'cell_style_preset')->pluck('parent_id', 'id')->all(), 'no other menu moves to another parent');

        // again: not twice
        $this->runMigration($file);
        $this->assertSame(1, Menu::where('menu_type', 'system')->where('menu_name', 'cell_style_preset')->count());
    }

    /**
     * #2178: a menu to the page that an administrator added by hand (with a name of their own) is
     * kept, and no second one is added.
     */
    public function testUpdateKeepsADisplayPresetsMenuAddedByHand(): void
    {
        Menu::where('menu_type', 'system')->where('menu_name', 'cell_style_preset')->delete();
        $menu = Menu::create([
            'parent_id' => 0,
            'order' => 99,
            'title' => 'my presets',
            'icon' => 'fa-star',
            'uri' => 'cell_style_preset',
            'menu_type' => 'system',
            'menu_name' => 'my_presets',
            'menu_target' => 'cell_style_preset',
        ]);

        $this->runMigration('2026_12_05_000000_add_cell_style_preset_menu.php');

        $this->assertSame(0, Menu::where('menu_type', 'system')->where('menu_name', 'cell_style_preset')->count());
        $kept = Menu::find($menu->id);
        $this->assertNotNull($kept);
        $this->assertSame(0, intval($kept->parent_id));
        $this->assertSame('my presets', $kept->title);
    }

    /**
     * #2178: the header shows the icon of the workflow tasks only on a site that uses a workflow.
     */
    public function testTheIconOfTheWorkflowTasksNeedsAWorkflow(): void
    {
        System::clearRequestSession();
        if (WorkflowTaskNav::used()) {
            $this->assertStringContainsString('navbar-workflow-task', (new WorkflowTaskNav())->render());
        }

        Workflow::query()->update(['setting_completed_flg' => 0]);
        System::clearCache();
        System::clearRequestSession();

        $this->assertFalse(WorkflowTaskNav::used());
        $this->assertSame('', (new WorkflowTaskNav())->render(), 'no icon, so no polling');
    }

    // ---------------------------------------------------------------- #2179

    /**
     * #2179: the choice columns of the safety check show names, not the stored values. A site made
     * before it gets them by the update; a value an administrator added stays, as its own name.
     */
    public function testTheChoicesOfTheSafetyCheckHaveNames(): void
    {
        $answer = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        $event = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        if (!$answer || !$event) {
            $this->markTestSkipped('the safety check is not installed on the test site');
        }
        $locale = Menu::siteLocale();

        // as a site made before #2179
        $status = CustomColumn::getEloquent('answer_status', $answer);
        $status->column_type = ColumnType::SELECT;
        $status->setOption('select_item', "not_answered\nsafe\nminor_injury\nneed_help\nevacuated");
        $status->save();
        $channel = CustomColumn::getEloquent('channel', $answer);
        $channel->column_type = ColumnType::SELECT;
        $channel->setOption('select_item', 'line');
        $channel->save();
        System::clearCache();

        SafetyCheckInstaller::ensureChannelMailOption();
        $this->runMigration('2026_12_05_000001_safety_check_choices_with_names.php');
        System::clearCache();

        $status->refresh();
        $this->assertSame(ColumnType::SELECT_VALTEXT, $status->column_type);
        $options = $status->createSelectOptions();
        $this->assertSame(trans('exment::exment.safety.status_safe', [], $locale), $options['safe']);
        $this->assertSame(trans('exment::exment.safety.status_not_answered', [], $locale), $options['not_answered']);
        $this->assertSame('evacuated', $options['evacuated'], 'a value the site added is its own name');

        $channel->refresh();
        $this->assertSame(ColumnType::SELECT_VALTEXT, $channel->column_type);
        $this->assertSame(trans('exment::exment.safety.channel_mail', [], $locale), $channel->createSelectOptions()['mail']);

        foreach (['trigger_type' => 'manual', 'event_status' => 'open'] as $name => $value) {
            $column = CustomColumn::getEloquent($name, $event);
            $this->assertSame(ColumnType::SELECT_VALTEXT, $column->column_type, $name);
            $this->assertArrayHasKey($value, $column->createSelectOptions(), $name);
        }

        // the stored value stays what the code compares, and the list shows its name
        Notification::fake();
        $record = $answer->getValueModel();
        $record->setValue(['answer_status' => 'safe', 'channel' => 'mail']);
        $record->save();
        $record = $answer->getValueModel($record->id);
        $this->assertSame('safe', $record->getValue('answer_status'));
        $this->assertSame(trans('exment::exment.safety.status_safe', [], $locale), strval($record->getValue('answer_status', true)));
    }

    // ---------------------------------------------------------------- #2181

    /**
     * #2181: the quick preview closes when "Open the whole record" changes the page (pjax), and
     * stops listening when it is closed otherwise.
     */
    public function testTheQuickPreviewClosesWhenThePageChanges(): void
    {
        $js = $this->read('public/vendor/exment/js/grid_tools.js');
        $start = strpos($js, 'function openPeekModal(');
        $this->assertNotFalse($start);
        $body = substr($js, $start, 4000);
        $this->assertStringContainsString("window.\$(document).one('pjax:send.exmPeek', close)", $body);
        $this->assertStringContainsString("window.\$(document).off('pjax:send.exmPeek')", $body);
    }

    // ---------------------------------------------------------------- #2182

    /**
     * #2182: a date (and time) column the save fills ("Register the run time on save" or "on
     * creation") is not edited in a list, and a write from a list is refused.
     */
    public function testAColumnTheSaveFillsIsNotEditedInAList(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        foreach ([ColumnType::DATE, ColumnType::DATETIME] as $type) {
            $column = CustomColumn::where('custom_table_id', $table->id)->where('column_type', $type)->first();
            $this->assertNotNull($column, "the test table has a {$type} column");
            $this->assertTrue(GridInlineEditor::isEditable($column), "{$type}: edited without the option");

            foreach (['datetime_now_saving', 'datetime_now_creating'] as $option) {
                $copy = clone $column;
                $copy->setOption($option, '1');
                $this->assertTrue(GridInlineEditor::isSetBySaving($copy));
                $this->assertFalse(GridInlineEditor::isEditable($copy), "{$type} {$option}: no editor");
                $this->assertFalse(GridInlineEditor::isFormEditableColumn($table, null, $copy), "{$type} {$option}: a write is refused");
            }
        }
    }

    // ---------------------------------------------------------------- #2184

    /**
     * #2184: the WIP limit is at most 999 for a count of cards, and has no upper limit for a total
     * of a number column.
     */
    public function testTheWipLimitOfATotalMayBeMoreThan999(): void
    {
        $rule = new KanbanWipLimitRule();

        request()->replace(['kanban_wip_column_id' => '']);
        $this->assertTrue($rule->passes('kanban_wips.0.kanban_wip_limit', '999'));
        $this->assertFalse($rule->passes('kanban_wips.0.kanban_wip_limit', '1200'), 'a count of cards');

        request()->replace(['kanban_wip_column_id' => '12']);
        $this->assertTrue($rule->passes('kanban_wips.0.kanban_wip_limit', '1200'), 'a total of a number column');

        $this->assertStringNotContainsString('->max(999)', $this->read('src/DataItems/Grid/KanbanGrid.php'));
        $this->assertStringContainsString('fmtNum(col.wip,', $this->read('resources/views/widgets/kanban/script.blade.php'));
    }

    // ---------------------------------------------------------------- #2186

    /**
     * #2186: the cover of a card is the first image of a column that allows several.
     */
    public function testTheCoverOfAColumnOfSeveralImages(): void
    {
        $files = [];
        foreach (['first.png', 'second.png'] as $name) {
            $file = new ExmentFile();
            $file->uuid = make_uuid();
            $file->local_dirname = 'kanban2186';
            $file->local_filename = $name;
            $file->filename = $name;
            $file->save();
            $files[] = $file;
        }
        $record = new class () {
            /** @return \Illuminate\Support\Collection<int, string> */
            public function getValue(mixed $column): \Illuminate\Support\Collection
            {
                return collect(['kanban2186/first.png', 'kanban2186/second.png']);
            }
        };
        $grid = (new \ReflectionClass(KanbanGrid::class))->newInstanceWithoutConstructor();

        $url = $this->invoke($grid, 'buildCoverUrl', [$record, null]);

        $this->assertNotSame('', $url);
        $this->assertStringContainsString($files[0]->uuid, $url);
    }

    // ---------------------------------------------------------------- #2187

    /**
     * #2187: a card the user may not edit says so, and the board opens the drawer for it without
     * opening the edit form first.
     */
    public function testACardThatCannotBeEditedOpensTheDrawer(): void
    {
        Notification::fake();
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $view = CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'kanban-2187-' . uniqid(),
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::KANBAN,
            'options' => ['kanban_editable' => '1'],
        ]);
        $free = $table->getValueModel();
        $free->setValue(['text' => 'issue 2187 free']);
        $free->save();
        $locked = $table->getValueModel();
        $locked->setValue(['text' => 'issue 2187 locked']);
        $locked->save();
        $this->lock($table, $locked);

        $grid = new KanbanGrid($table, CustomView::withoutGlobalScopes()->whereKey($view->id)->firstOrFail());
        $records = collect([$table->getValueModel($free->id), $table->getValueModel($locked->id)]);
        $built = $this->invoke($grid, 'buildCards', [$records, [], [], null]);
        $this->assertIsArray($built);
        $cards = collect($built)->keyBy('id');

        $this->assertArrayNotHasKey('noedit', $cards[$free->id]);
        $this->assertTrue($cards[$locked->id]['noedit'] ?? false, 'locked by the workflow');

        $js = $this->read('resources/views/widgets/kanban/script.blade.php');
        $this->assertStringContainsString('if (card.noedit || formNoEdit[id]) { noEditFallback(card); return; }', $js);
    }

    /**
     * Move the record to the status "temp" of the workflow of custom_value_edit_all, which locks
     * the data (as OperationButtonWorkflowLockedTest).
     */
    private function lock(CustomTable $table, CustomValue $custom_value): void
    {
        $workflow = Workflow::getWorkflowByTable($table);
        $this->assertInstanceOf(Workflow::class, $workflow);
        $status = WorkflowStatus::where('workflow_id', $workflow->id)->where('status_name', 'temp')->first();
        $this->assertInstanceOf(WorkflowStatus::class, $status);
        $action = WorkflowAction::where('workflow_id', $workflow->id)->first();
        $this->assertInstanceOf(WorkflowAction::class, $action);

        $value = new WorkflowValue();
        $value->workflow_id = $workflow->id;
        $value->morph_type = $table->table_name;
        $value->morph_id = $custom_value->id;
        $value->workflow_action_id = $action->id;
        $value->workflow_status_from_id = null;
        $value->workflow_status_to_id = $status->id;
        $value->action_executed_flg = 0;
        $value->latest_flg = 1;
        $value->save();
        System::clearRequestSession();
    }
}
