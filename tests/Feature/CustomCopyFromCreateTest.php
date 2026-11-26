<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomCopyColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The new-record form starts from a record of another table (#1828).
 *
 * A data copy setting with "use_on_create" puts a "Copy from (table)" button on the
 * new-record form of its copy-to table. The user picks a record of the copy-from table,
 * and the form opens with its values. Nothing is saved until the user saves.
 *
 * user2 of the test data may only read the records of custom_value_view_all, may edit
 * every record of custom_value_edit_all, and may not see no_permission.
 *
 * Everything a test writes is rolled back.
 */
class CustomCopyFromCreateTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected const FROM = TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL;
    protected const TO = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        // only the settings a test makes (rolled back with the rest)
        CustomCopy::query()->get()->each(function ($copy) {
            $copy->delete();
        });
    }

    /**
     * New controllers and an empty request cache for every request, as in production.
     *
     * @param string $method
     * @param string $uri
     * @param array<mixed> $parameters
     * @param array<mixed> $cookies
     * @param array<mixed> $files
     * @param array<mixed> $server
     * @param string|null $content
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }
        System::clearRequestSession();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testTheFormStartsFromThePickedRecordAndNothingIsSaved(): void
    {
        $copy = $this->copy(static::FROM, static::TO, true);
        $record = $this->record(static::FROM, 'copy me 1828');
        $label = exmtrans('custom_copy.copy_from.button');
        $search = admin_urls_query('data', static::FROM, ['modalframe' => 1]);

        // the button opens the search dialog of the copy-from table
        $this->get(admin_urls('data', static::TO, 'create'))
            ->assertStatus(200)
            ->assertSee($label, false)
            ->assertSee($search)
            ->assertSee('copy_from_target_' . $copy->suuid, false);

        $this->get($search . '&' . http_build_query([
            'target_column_class' => 'copy_from_target_' . $copy->suuid,
            'target_column_multiple' => 0,
            'display_table_id' => $this->table(static::TO)->id,
        ]), static::AJAX)
            ->assertStatus(200)
            ->assertJsonStructure(['title', 'body']);

        $before = $this->rows(static::TO);
        $this->get(admin_urls_query('data', static::TO, 'create', ['copy_from' => $copy->suuid, 'copy_from_id' => $record->id]))
            ->assertStatus(200)
            ->assertSee('copy me 1828', false);
        $this->assertSame($before, $this->rows(static::TO), 'opening the form saves nothing');
    }

    public function testASettingNotOnTheCreateFormGivesNoButton(): void
    {
        $copy = $this->copy(static::FROM, static::TO, false);
        $record = $this->record(static::FROM, 'not on the form 1828');

        $this->get(admin_urls('data', static::TO, 'create'))
            ->assertStatus(200)
            ->assertDontSee('copy_from_target_', false);
        $this->get(admin_urls_query('data', static::TO, 'create', ['copy_from' => $copy->suuid, 'copy_from_id' => $record->id]))
            ->assertStatus(200)
            ->assertDontSee('not on the form 1828', false);
    }

    public function testTheSettingOfAnotherTableIsNotUsed(): void
    {
        // a setting into custom_value_view_all, asked on the form of custom_value_edit_all
        $copy = $this->copy(static::TO, static::FROM, true);
        $record = $this->record(static::TO, 'other table 1828');

        $this->get(admin_urls_query('data', static::TO, 'create', ['copy_from' => $copy->suuid, 'copy_from_id' => $record->id]))
            ->assertStatus(200)
            ->assertDontSee('other table 1828', false);
    }

    public function testAUserWhoMayNotSeeTheSourceTableGetsNothingFromIt(): void
    {
        $copy = $this->copy(TestDefine::TESTDATA_TABLE_NO_PERMISSION, static::TO, true);
        $record = $this->record(TestDefine::TESTDATA_TABLE_NO_PERMISSION, 'secret 1828');

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->get(admin_urls('data', static::TO, 'create'))
            ->assertStatus(200)
            ->assertDontSee('copy_from_target_', false);
        $this->get(admin_urls_query('data', static::TO, 'create', ['copy_from' => $copy->suuid, 'copy_from_id' => $record->id]))
            ->assertStatus(200)
            ->assertDontSee('secret 1828', false);
    }

    public function testWithTwoSourcesTheButtonsSayTheTable(): void
    {
        $this->copy(static::FROM, static::TO, true);
        $this->copy(static::TO, static::TO, true);

        $this->get(admin_urls('data', static::TO, 'create'))
            ->assertStatus(200)
            ->assertSee(exmtrans('custom_copy.copy_from.button_table', $this->table(static::FROM)->table_view_name), false)
            ->assertSee(exmtrans('custom_copy.copy_from.button_table', $this->table(static::TO)->table_view_name), false);
    }

    public function testTheAdminsOwnLabelIsUsed(): void
    {
        $copy = $this->copy(static::FROM, static::TO, true);
        $copy->setOption('create_label_type', CustomCopy::CREATE_LABEL_LABEL);
        $copy->setOption('create_label', '前年度 1828');
        $copy->save();
        $this->copy(static::TO, static::TO, true);

        $this->get(admin_urls('data', static::TO, 'create'))
            ->assertStatus(200)
            ->assertSee(exmtrans('custom_copy.copy_from.button_table', '前年度 1828'), false)
            ->assertDontSee(exmtrans('custom_copy.copy_from.button_table', $this->table(static::FROM)->table_view_name), false)
            ->assertSee(exmtrans('custom_copy.copy_from.button_table', $this->table(static::TO)->table_view_name), false);
    }

    public function testTheBracketsSayWhatTheAdminPicked(): void
    {
        $copy = $this->copy(static::FROM, static::TO, true);
        $from_name = $this->table(static::FROM)->table_view_name;

        // auto, one button: just "Copy from"
        $this->assertSame(exmtrans('custom_copy.copy_from.button'), CustomCopy::getCreateLabel($copy, false));
        // auto, several buttons: the table
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', $from_name), CustomCopy::getCreateLabel($copy, true));

        $copy->setOption('create_label_type', CustomCopy::CREATE_LABEL_TABLE);
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', $from_name), CustomCopy::getCreateLabel($copy, false));

        // a label typed but not picked is not used
        $copy->setOption('create_label', 'last year');
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', $from_name), CustomCopy::getCreateLabel($copy, false));

        $copy->setOption('create_label_type', CustomCopy::CREATE_LABEL_LABEL);
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', 'last year'), CustomCopy::getCreateLabel($copy, false));

        // the label picked but left empty: as auto
        $copy->setOption('create_label', '');
        $this->assertSame(exmtrans('custom_copy.copy_from.button'), CustomCopy::getCreateLabel($copy, false));
    }

    /**
     * Two settings that copy from the same table: the button label of each (#2274); the
     * default label "コピー" or none keeps the table's name; another table keeps its name.
     */
    public function testTwoSettingsFromTheSameTableUseTheirButtonLabels(): void
    {
        $a = $this->copy(static::FROM, static::TO, true);
        $b = $this->copy(static::FROM, static::TO, true);
        $a->setOption('label', 'from A');
        $b->setOption('label', 'from A, the other way');
        $from_name = $this->table(static::FROM)->table_view_name;
        $copies = [$a, $b];

        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', 'from A'), CustomCopy::getCreateLabel($a, true, $copies));
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', 'from A, the other way'), CustomCopy::getCreateLabel($b, true, $copies));
        // without the list, as before
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', $from_name), CustomCopy::getCreateLabel($a, true));

        // the default label, or none: the table's name
        $b->setOption('label', exmtrans('common.copy'));
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', $from_name), CustomCopy::getCreateLabel($b, true, $copies));
        $b->setOption('label', '');
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', $from_name), CustomCopy::getCreateLabel($b, true, $copies));

        // another table's setting on the same form: each its table's name
        $other = $this->copy(static::TO, static::FROM, true);
        $other->setOption('label', 'other');
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', $this->table(static::TO)->table_view_name), CustomCopy::getCreateLabel($other, true, [$a, $other]));
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', $from_name), CustomCopy::getCreateLabel($a, true, [$a, $other]));

        // "copy-from table name" and "label" picked: as before
        $a->setOption('create_label_type', CustomCopy::CREATE_LABEL_TABLE);
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', $from_name), CustomCopy::getCreateLabel($a, true, $copies));
        $a->setOption('create_label_type', CustomCopy::CREATE_LABEL_LABEL);
        $a->setOption('create_label', 'typed');
        $this->assertSame(exmtrans('custom_copy.copy_from.button_table', 'typed'), CustomCopy::getCreateLabel($a, true, $copies));
    }

    public function testANewSettingCanStartWithTheColumnsOfTheSameNamePaired(): void
    {
        $from = $this->table(static::FROM);
        $to = $this->table(static::TO);
        $options = ['append_table' => true, 'include_system' => false, 'ignore_attachment' => true];

        $pairs = \Exceedone\Exment\Controllers\CustomCopyController::getSameNameColumnPairs(
            $from->getColumnsSelectOptions($options),
            $to->getColumnsSelectOptions($options)
        );
        $this->assertNotEmpty($pairs);

        $names = [];
        foreach ($pairs as $pair) {
            $from_column = CustomColumn::getEloquent(explode('?', $pair['from_column_target'])[0]);
            $to_column = CustomColumn::getEloquent(explode('?', $pair['to_column_target'])[0]);
            $this->assertInstanceOf(CustomColumn::class, $from_column);
            $this->assertInstanceOf(CustomColumn::class, $to_column);
            $this->assertEquals($from->id, $from_column->custom_table_id);
            $this->assertEquals($to->id, $to_column->custom_table_id);
            $this->assertSame($from_column->column_name, $to_column->column_name);
            $this->assertNotSame(\Exceedone\Exment\Enums\ColumnType::AUTO_NUMBER, $from_column->column_type);
            $this->assertSame(CopyColumnType::DEFAULT, $pair['copy_column_type']);
            $names[] = $from_column->column_name;
        }
        $this->assertContains('text', $names);

        $this->get(admin_urls_query('copy', static::FROM, 'create', ['to_custom_table' => $to->suuid, 'match_columns' => 1]))
            ->assertStatus(200)
            ->assertSee(exmtrans('custom_copy.match_columns.message', count($pairs)), false);
        $this->get(admin_urls_query('copy', static::FROM, 'create', ['to_custom_table' => $to->suuid]))
            ->assertStatus(200)
            ->assertDontSee(exmtrans('custom_copy.match_columns.message', count($pairs)), false);
    }

    public function testTheCopyButtonOfTheRecordPageStillSavesTheCopy(): void
    {
        $copy = $this->copy(static::FROM, static::TO, true);
        $record = $this->record(static::FROM, 'saved copy 1828');

        $before = $this->rows(static::TO);
        $response = $copy->execute($record);
        $this->assertTrue($response['result']);
        $this->assertSame($before + 1, $this->rows(static::TO));

        $made = $copy->makeNewModel($record);
        $this->assertFalse($made->exists);
        $this->assertSame('saved copy 1828', $made->getValue('text'));
    }

    // helpers ----------------------------------------------------

    protected function actAs(string $login_id): void
    {
        $user = LoginUser::find($login_id);
        if (!$user instanceof LoginUser) {
            $this->fail("the test data has no login user $login_id");
        }
        $this->be($user);
        System::clearRequestSession();
    }

    protected function table(string $table_name): CustomTable
    {
        $table = CustomTable::getEloquent($table_name);
        if (!$table instanceof CustomTable) {
            $this->fail("the test data has no $table_name table");
        }

        return $table;
    }

    protected function text(CustomTable $table): CustomColumn
    {
        $text = CustomColumn::getEloquent('text', $table);
        if (!$text instanceof CustomColumn) {
            $this->fail("the table {$table->table_name} has no text column");
        }

        return $text;
    }

    protected function record(string $table_name, string $text): CustomValue
    {
        $row = $this->table($table_name)->getValueModel();
        if (!$row instanceof CustomValue) {
            $this->fail("no value model for $table_name");
        }
        $row->setValue(['text' => $text]);
        $row->save();

        return $row;
    }

    protected function rows(string $table_name): int
    {
        return getModelName($this->table($table_name))::query()->withoutGlobalScopes()->count();
    }

    /**
     * A copy setting that copies the "text" column.
     */
    protected function copy(string $from_table_name, string $to_table_name, bool $use_on_create): CustomCopy
    {
        $from = $this->table($from_table_name);
        $to = $this->table($to_table_name);

        $copy = CustomCopy::create([
            'from_custom_table_id' => $from->id,
            'to_custom_table_id' => $to->id,
            'options' => ['label' => 'copy 1828', 'use_on_create' => $use_on_create ? '1' : '0'],
        ]);
        CustomCopyColumn::create([
            'custom_copy_id' => $copy->id,
            'from_column_type' => ConditionType::COLUMN,
            'from_column_table_id' => $from->id,
            'from_column_target_id' => $this->text($from)->id,
            'to_column_type' => ConditionType::COLUMN,
            'to_column_table_id' => $to->id,
            'to_column_target_id' => $this->text($to)->id,
            'copy_column_type' => CopyColumnType::DEFAULT,
        ]);

        $copy = CustomCopy::find($copy->id);
        if (!$copy instanceof CustomCopy) {
            $this->fail('the copy setting was not saved');
        }

        return $copy;
    }
}
