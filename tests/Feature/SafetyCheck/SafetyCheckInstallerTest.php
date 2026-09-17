<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestTrait;

class SafetyCheckInstallerTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testEnsureAllCreatesTablesMenuTemplate()
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

    public function testEnsureAllIsIdempotent()
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

    public function testEnsureMailTemplateSeedsSystemTemplate()
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

    public function testChannelSelectHasMailOption()
    {
        SafetyCheckInstaller::ensureAll();

        $answerTable = \Exceedone\Exment\Model\CustomTable::getEloquent('safety_check_answer');
        $channel = \Exceedone\Exment\Model\CustomColumn::getEloquent('channel', $answerTable);
        $this->assertStringContainsString('mail', (string) array_get($channel->options, 'select_item'));
    }

    public function testSentCountLabelRenamed()
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

    public function testEnsureAllDoesNotOverwriteAdminEditedColumnLabel()
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

    public function testEnsureAllRecreatesMissingAnswerColumn()
    {
        SafetyCheckInstaller::ensureAll();

        $answerTable = CustomTable::getEloquent('safety_check_answer');
        $column = CustomColumn::getEloquent('unlinked_flg', $answerTable);
        $this->assertNotNull($column);
        $column->delete();
        $this->assertNull(CustomColumn::getEloquent('unlinked_flg', CustomTable::getEloquent('safety_check_answer')));

        SafetyCheckInstaller::ensureAll();

        $recreated = CustomColumn::getEloquent('unlinked_flg', CustomTable::getEloquent('safety_check_answer'));
        $this->assertNotNull($recreated, 'ensureAll() must recreate a missing answer-table column');
        $this->assertEquals(exmtrans('safety.col_unlinked_flg'), $recreated->column_view_name);
    }
    public function testEnsureAllRefusesForeignTableWithSameName()
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
        $this->assertFalse(boolval(CustomTable::find($foreign->id)->system_flg), 'The foreign table must not be marked as a system table.');
        $this->assertEquals(0, CustomColumn::where('custom_table_id', $foreign->id)->count(), 'No feature column may be added to the foreign table.');
    }
}
