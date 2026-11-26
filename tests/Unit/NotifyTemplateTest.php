<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\TemplateExportTarget;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\TemplateImportExport\NotifyTemplate;
use Exceedone\Exment\Services\TemplateImportExport\TemplateExporter;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * A notify in a template (NotifyTemplate): its table, the date column of its
 * schedule and the columns it sends to travel by name and come back as the
 * ids of the site; what belongs to one site (fixed users, the webhook URL)
 * stays behind.
 */
class NotifyTemplateTest extends UnitTestBase
{
    use DatabaseTransactions;
    use CustomTableTrait;

    /**
     * Remove what a run before left. The test makes a table, and making a table (DDL) commits the
     * transaction of the test on MySQL, so its table and notification stayed: the next run on the
     * same database found two. Deleting the table deletes its notifications too.
     */
    protected function tearDown(): void
    {
        // and what this run made (it was committed with the table)
        foreach (['notify_template_test', 'notify_template_lang'] as $table_name) {
            $this->removeLeftovers($table_name);
        }
        parent::tearDown();
    }

    protected function removeLeftovers(string $table_name): void
    {
        foreach (CustomTable::where('table_name', $table_name)->get() as $table) {
            $table->delete();
        }
        System::clearCache();
    }

    protected function makeNotify(CustomTable $table, CustomColumn $due, CustomColumn $owner, ?int $mailId): Notify
    {
        $notify = new Notify();
        $notify->notify_view_name = 'Due soon';
        $notify->target_id = $table->id;
        $notify->notify_trigger = NotifyTrigger::TIME;
        $notify->mail_template_id = $mailId;
        $notify->trigger_settings = [
            'notify_target_table_id' => $table->id,
            'notify_target_column' => $due->id,
            'notify_day' => '3',
            'notify_beforeafter' => '-1',
            'notify_hour' => '9',
        ];
        $notify->action_settings = [[
            'notify_action' => NotifyAction::SHOW_PAGE,
            'notify_action_target' => [NotifyActionTarget::CREATED_USER, strval($owner->id), NotifyActionTarget::FIXED_USER],
            'target_users' => ['1'],
            'webhook_url' => 'https://hooks.example.com/secret',
        ]];
        $notify->save();
        return $notify;
    }

    public function testANotifyTravelsByNamesAndComesBackAsIds(): void
    {
        $this->removeLeftovers('notify_template_test');
        $table = $this->_createSimpleTable('notify_template_test');
        $due = CustomColumn::create(['custom_table_id' => $table->id, 'column_name' => 'due', 'column_view_name' => 'due', 'column_type' => ColumnType::DATE, 'options' => []]);
        $owner = CustomColumn::create(['custom_table_id' => $table->id, 'column_name' => 'owner', 'column_view_name' => 'owner', 'column_type' => ColumnType::USER, 'options' => []]);
        System::clearCache();
        $mail = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', MailKeyName::TIME_NOTIFY)->first();
        $notify = $this->makeNotify($table, $due, $owner, $mail ? intval($mail->id) : null);

        $items = NotifyTemplate::export(['notify_template_test'], []);
        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertSame('notify_template_test', $item['target_name']);
        $this->assertSame('due', $item['trigger_settings']['notify_target_column_name']);
        $this->assertSame('notify_template_test', $item['trigger_settings']['notify_target_table_name']);
        $this->assertArrayNotHasKey('notify_target_column', $item['trigger_settings'], 'no id');
        $this->assertSame([NotifyActionTarget::CREATED_USER, 'column:notify_template_test.owner'], $item['action_settings'][0]['notify_action_target']);
        $this->assertArrayNotHasKey('webhook_url', $item['action_settings'][0], 'the webhook URL is a secret');
        $this->assertArrayNotHasKey('target_users', $item['action_settings'][0], 'fixed users belong to one site');
        if ($mail) {
            $this->assertSame(MailKeyName::TIME_NOTIFY, $item['mail_key_name']);
        }

        // another table is not in the template
        $this->assertSame([], NotifyTemplate::export(['no_such_table'], []));

        // back on a site: the ids of that site
        $suuid = $notify->suuid;
        $notify->delete();
        $this->assertSame(1, NotifyTemplate::import($items));
        /** @var Notify $back */
        $back = Notify::where('suuid', $suuid)->firstOrFail();
        $this->assertSame(intval($table->id), intval($back->target_id));
        $settings = json_decode(strval($back->getRawOriginal('trigger_settings')), true);
        $this->assertSame(intval($due->id), intval(array_get($settings, 'notify_target_column')));
        $this->assertSame([NotifyActionTarget::CREATED_USER, strval($owner->id)], $back->action_settings[0]['notify_action_target']);
        $this->assertSame($mail ? intval($mail->id) : null, is_null($back->mail_template_id) ? null : intval($back->mail_template_id));

        // the same template again does not make a second one, and does not change it unless updating
        $this->assertSame(0, NotifyTemplate::import($items));
        $this->assertSame(1, Notify::where('suuid', $suuid)->count());
    }

    public function testTheLanguageFileHasOnlyTheNames(): void
    {
        $this->removeLeftovers('notify_template_lang');
        $table = $this->_createSimpleTable('notify_template_lang');
        $due = CustomColumn::create(['custom_table_id' => $table->id, 'column_name' => 'due', 'column_view_name' => 'due', 'column_type' => ColumnType::DATE, 'options' => []]);
        System::clearCache();
        $notify = $this->makeNotify($table, $due, $due, null);

        $config = TemplateExporter::getExportData('x', 'x', '', ['export_target' => [TemplateExportTarget::NOTIFY], 'target_tables' => ['notify_template_lang']], true);
        $this->assertSame([['suuid' => $notify->suuid, 'notify_view_name' => 'Due soon']], $config['notifies']);
    }
}
