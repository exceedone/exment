<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\Feature\Line\ForeignTableTestHelpers;
use Exceedone\Exment\Tests\TestTrait;

class SafetyCheckInstallerTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use ForeignTableTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testEnsureAllCreatesTablesMenuTemplate(): void
    {
        SafetyCheckInstaller::ensureAll();

        $event = CustomTable::getEloquent('safety_check_event');
        $this->assertNotNull($event);
        $this->assertTrue(boolval($event->system_flg));
        foreach (['title', 'trigger_type', 'event_status', 'triggered_at', 'jma_event_id', 'quake_info', 'target_count', 'sent_count', 'resent_at'] as $col) {
            $this->assertNotNull(CustomColumn::getEloquent($col, $event), "missing column {$col}");
        }

        $answer = CustomTable::getEloquent('safety_check_answer');
        $this->assertNotNull($answer);
        foreach (['event', 'user', 'answer_status', 'comment', 'answered_at', 'channel', 'unlinked_flg'] as $col) {
            $this->assertNotNull(CustomColumn::getEloquent($col, $answer), "missing column {$col}");
        }

        $this->assertTrue(Menu::where('menu_name', 'safety_check')->exists());

        $tmpl = getModelName('line_flex_template')::withoutGlobalScopes()
            ->where('value->flex_key', SafetyCheckInstaller::FLEX_KEY)->first();
        $this->assertNotNull($tmpl);
    }

    public function testEnsureAllIsIdempotent(): void
    {
        SafetyCheckInstaller::ensureAll();
        SafetyCheckInstaller::ensureAll();

        $this->assertEquals(1, CustomTable::where('table_name', 'safety_check_event')->count());
        $this->assertEquals(1, Menu::where('menu_name', 'safety_check')->count());
        $this->assertEquals(
            1,
            getModelName('line_flex_template')::withoutGlobalScopes()
                ->where('value->flex_key', SafetyCheckInstaller::FLEX_KEY)->count()
        );
    }

    public function testEnsureMailTemplateSeedsSystemTemplate(): void
    {
        SafetyCheckInstaller::ensureAll();

        $tmpl = getModelName(\Exceedone\Exment\Enums\SystemTableName::MAIL_TEMPLATE)::withoutGlobalScopes()
            ->where('value->mail_key_name', \Exceedone\Exment\Enums\MailKeyName::SAFETY_CHECK_MAIL)->first();
        $this->assertNotNull($tmpl);
        $this->assertStringContainsString('${answer_url}', $tmpl->getValue('mail_body'));
        $this->assertStringContainsString('${safety_title}', $tmpl->getValue('mail_subject'));

        SafetyCheckInstaller::ensureAll();
        $count = getModelName(\Exceedone\Exment\Enums\SystemTableName::MAIL_TEMPLATE)::withoutGlobalScopes()
            ->where('value->mail_key_name', \Exceedone\Exment\Enums\MailKeyName::SAFETY_CHECK_MAIL)->count();
        $this->assertEquals(1, $count);
    }

    public function testChannelSelectHasMailOption(): void
    {
        SafetyCheckInstaller::ensureAll();

        $answerTable = \Exceedone\Exment\Model\CustomTable::getEloquent('safety_check_answer');
        $channel = \Exceedone\Exment\Model\CustomColumn::getEloquent('channel', $answerTable);
        $this->assertStringContainsString('mail', (string) array_get($channel->options, 'select_item'));
    }

    public function testSentCountLabelRenamed(): void
    {
        SafetyCheckInstaller::ensureAll();

        $eventTable = \Exceedone\Exment\Model\CustomTable::getEloquent('safety_check_event');
        $sentCount = \Exceedone\Exment\Model\CustomColumn::getEloquent('sent_count', $eventTable);
        $sentCount->column_view_name = 'LINE送信数';
        $sentCount->save();

        SafetyCheckInstaller::ensureSentCountLabel();

        $sentCount = \Exceedone\Exment\Model\CustomColumn::getEloquent('sent_count', $eventTable);
        $this->assertEquals(exmtrans('safety.col_sent_count'), $sentCount->column_view_name);
    }

    public function testEnsureAllDoesNotOverwriteAdminEditedColumnLabel(): void
    {
        SafetyCheckInstaller::ensureAll();

        $eventTable = CustomTable::getEloquent('safety_check_event');
        $sentCount = CustomColumn::getEloquent('sent_count', $eventTable);
        $sentCount->column_view_name = '送信数（管理者が変更）';
        $sentCount->save();

        SafetyCheckInstaller::ensureAll();

        $sentCount = CustomColumn::getEloquent('sent_count', $eventTable);
        $this->assertEquals('送信数（管理者が変更）', $sentCount->column_view_name);
    }

    public function testEnsureAllRecreatesMissingAnswerColumn(): void
    {
        SafetyCheckInstaller::ensureAll();

        $answerTable = CustomTable::getEloquent('safety_check_answer');
        $column = CustomColumn::getEloquent('unlinked_flg', $answerTable);
        $this->assertNotNull($column);
        $column->delete();
        $this->assertNull(CustomColumn::getEloquent('unlinked_flg', CustomTable::getEloquent('safety_check_answer')));

        SafetyCheckInstaller::ensureAll();

        /** @var CustomColumn|null $recreated phpstan remembers the identical call above as null; this is a fresh lookup */
        $recreated = CustomColumn::getEloquent('unlinked_flg', CustomTable::getEloquent('safety_check_answer'));
        $this->assertNotNull($recreated, 'ensureAll() must recreate a missing answer-table column');
        $this->assertEquals(exmtrans('safety.col_unlinked_flg'), $recreated->column_view_name);
    }
    public function testEnsureAllRefusesForeignTableWithSameName(): void
    {
        SafetyCheckInstaller::ensureAll();
        $real = CustomTable::getEloquent('safety_check_event');
        $real->table_name = 'zz_safety_check_event_backup';
        $real->save();
        \Exceedone\Exment\Model\System::clearCache();

        $foreign = CustomTable::create([
            'table_name'      => 'safety_check_event',
            'table_view_name' => 'Customer table',
            'options'         => [],
        ]);
        \Exceedone\Exment\Model\System::clearCache();

        $thrown = null;
        try {
            SafetyCheckInstaller::ensureAll();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'ensureAll() must refuse a same-name table that is not the feature\'s.');
        $this->assertFalse(boolval(CustomTable::where('id', $foreign->id)->firstOrFail()->system_flg), 'The foreign table must not be marked as a system table.');
        $this->assertEquals(0, CustomColumn::where('custom_table_id', $foreign->id)->count(), 'No feature column may be added to the foreign table.');
    }

    public function testNotInstalledMessageNamesPatchCommandNotInstallSeeder(): void
    {
        $locale = app()->getLocale();
        try {
            foreach (['ja', 'en'] as $lang) {
                app()->setLocale($lang);
                $message = exmtrans('safety.message_not_installed');
                $this->assertStringContainsString('php artisan exment:patchdata line_safety_check', $message, $lang);
                $this->assertStringNotContainsString('InstallSeeder', $message, $lang);
                $this->assertStringNotContainsString('db:seed', $message, $lang);
            }
        } finally {
            app()->setLocale($locale);
        }
    }

    public function testPatchLineSafetyCheckRecreatesMissingColumnWithoutTouchingMenus(): void
    {
        SafetyCheckInstaller::ensureAll();
        CustomColumn::getEloquent('unlinked_flg', CustomTable::getEloquent('safety_check_answer'))->delete();
        $menuIds = Menu::orderBy('id')->pluck('id')->all();

        $this->assertEquals(0, \Artisan::call('exment:patchdata', ['action' => 'line_safety_check']));

        $this->assertNotNull(CustomColumn::getEloquent('unlinked_flg', CustomTable::getEloquent('safety_check_answer')), 'The patch must recreate the missing column.');
        $this->assertEquals($menuIds, Menu::orderBy('id')->pluck('id')->all(), 'The patch must not delete or recreate menus.');
    }

    public function testPatchLineSafetyCheckFailsOnForeignTable(): void
    {
        SafetyCheckInstaller::ensureAll();
        // Exment command constructors call getModelName() on every custom table, which runs CREATE TABLE
        // for the foreign table below and commits the test transaction. Build the commands first.
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->all();
        $foreign = $this->replaceWithForeignTable('safety_check_event', ['event_status']);

        $this->assertEquals(1, \Artisan::call('exment:patchdata', ['action' => 'line_safety_check']));

        $this->assertFalse(boolval(CustomTable::where('id', $foreign->id)->firstOrFail()->system_flg));
    }

    public function testOwnedMarkersListEveryColumnTheInstallerCreates(): void
    {
        SafetyCheckInstaller::ensureAll();

        foreach (SafetyCheckInstaller::OWNED_MARKERS as $tableName => $columns) {
            $created = CustomColumn::where('custom_table_id', CustomTable::getEloquent($tableName)->id)
                ->pluck('column_name')->sort()->values()->all();
            $this->assertEquals($created, collect($columns)->sort()->values()->all(), "OWNED_MARKERS[{$tableName}] must list every installer column.");
        }
    }

    /**
     * A customer's own safety_check_answer that merely shares answer_status must be refused before
     * ensureColumns() alters it and markSystem() lets removeAll() drop it.
     */
    public function testEnsureAllRefusesForeignTableWithOneFeatureColumn(): void
    {
        SafetyCheckInstaller::ensureAll();
        $foreign = $this->replaceWithForeignTable('safety_check_answer', ['answer_status']);

        $thrown = null;
        try {
            SafetyCheckInstaller::ensureAll();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'ensureAll() must refuse a same-name table that only shares one column.');
        $this->assertFalse(boolval(CustomTable::where('id', $foreign->id)->firstOrFail()->system_flg), 'The foreign table must not be marked as a system table.');
        $this->assertEquals(1, CustomColumn::where('custom_table_id', $foreign->id)->count(), 'No feature column may be added to the foreign table.');
    }
}
