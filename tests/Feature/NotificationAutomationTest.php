<?php

namespace Tests\Feature;

use Tests\TestCase;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\SystemTableName;

class NotificationAutomationTest extends TestCase
{
    protected $adminLoginUser;
    protected $customTable;
    protected $tableName;
    protected $notify;
    protected $mailTemplateId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminLoginUser = LoginUser::find(1);
        if (!$this->adminLoginUser) {
            $this->markTestSkipped('Admin User not found for setup.');
        }

        // Clear system cache
        System::clearCache();

        // ---------------------------------------------------------
        // Setup: Reuse or create test table
        // ---------------------------------------------------------
        $reusedTable = CustomTable::where('table_name', 'like', 'uni_table_%')
            ->orderBy('id', 'desc')
            ->first();

        if ($reusedTable) {
            $this->customTable = $reusedTable;
            $this->tableName = $reusedTable->table_name;
        } else {
            $timestamp = date('YmdHis');
            $this->tableName = 'uni_table_' . $timestamp;
            $this->customTable = CustomTable::create([
                'table_name' => $this->tableName,
                'table_view_name' => 'Notification Test Table ' . $timestamp,
            ]);

            CustomColumn::create([
                'custom_table_id' => $this->customTable->id,
                'column_name' => 'test_name',
                'column_view_name' => 'Test Name',
                'column_type' => 'text',
            ]);

            CustomColumn::create([
                'custom_table_id' => $this->customTable->id,
                'column_name' => 'test_price',
                'column_view_name' => 'Test Price',
                'column_type' => 'integer',
            ]);
            System::clearCache();
        }

        // Clean up old notifications for this table to prevent noise
        Notify::where('target_id', $this->customTable->id)->delete();
        System::clearCache();

        // ---------------------------------------------------------
        // Setup: Create a dedicated Mail Template for E2E notifications
        // (Exment uses the mail template's subject and body as the content
        // source for both Emails and Navbar system alerts, parsed via
        // Exment's default placeholder format: ${value:column_name})
        // ---------------------------------------------------------
        $timestamp = date('YmdHis');
        $mailTemplateTable = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE);
        
        $dummyTemplate = $mailTemplateTable->getValueModel();
        $dummyTemplate->setValue('mail_template_name', 'E2E Notification Template ' . $timestamp);
        $dummyTemplate->setValue('mail_subject', 'Notice: New Entry ${value:test_name}');
        $dummyTemplate->setValue('mail_body', 'A new entry has been created with price ${value:test_price}.');
        $dummyTemplate->save();
        
        $this->mailTemplateId = $dummyTemplate->id;
        System::clearCache();
    }

    public function test_notification_trigger_on_create()
    {
        $timestamp = date('YmdHis');

        $this->actingAs($this->adminLoginUser, 'admin');
        \Exment::setGuard('admin');

        // ---------------------------------------------------------
        // Step 1 - Create Notify Configuration
        // ---------------------------------------------------------
        $this->notify = Notify::create([
            'notify_view_name' => 'E2E Notice ' . $timestamp,
            'notify_name' => 'e2e_notice_' . $timestamp,
            'notify_trigger' => NotifyTrigger::CREATE_UPDATE_DATA, // "2"
            'target_id' => $this->customTable->id,
            'mail_template_id' => $this->mailTemplateId, // Links to our custom mail template
            'trigger_settings' => [
                'notify_saved_trigger' => [NotifySavedType::CREATE], // ["created"]
                'notify_myself' => true,
            ],
            'action_settings' => [
                [
                    'notify_action' => NotifyAction::SHOW_PAGE, // "2" (System Navbar Alert)
                    'notify_action_target' => 'administrator',
                ]
            ],
            'active_flg' => 1,
            'custom_table_id' => 0, // exment's default saving requirement
        ]);

        System::clearCache();

        // Verify configuration is saved successfully
        $this->assertNotNull($this->notify->id, 'Notify configuration was not created.');

        // ---------------------------------------------------------
        // Step 2 - Create a new record to trigger the Notification
        // ---------------------------------------------------------
        $customTable = CustomTable::getEloquent($this->tableName);
        $model = $customTable->getValueModel();
        $model->setValue('test_name', 'Notify Test Entry ' . $timestamp);
        $model->setValue('test_price', 680000);
        
        // Save record - this must trigger notification events
        $model->save();
        $recordId = $model->id;

        $this->assertNotNull($recordId, 'Failed to create custom value record.');

        // ---------------------------------------------------------
        // Step 3 - Verify System Alert (Navbar Notify) is created
        // ---------------------------------------------------------
        System::clearCache();

        // Retrieve navbar alert record for this custom value
        $navbarAlert = NotifyNavbar::withoutGlobalScopes()
            ->where('parent_type', $this->tableName)
            ->where('parent_id', $recordId)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertNotNull($navbarAlert, 'Navbar Alert notification was not created.');
        $this->assertEquals(0, $navbarAlert->read_flg, 'Notification should be unread (read_flg = 0).');
        
        // Verify placeholders are resolved correctly
        $this->assertStringContainsString('Notify Test Entry ' . $timestamp, $navbarAlert->notify_subject, 
            'Placeholder ${value:test_name} in subject was not resolved correctly.');
        $this->assertStringContainsString('680', $navbarAlert->notify_body, 
            'Placeholder ${value:test_price} in body was not resolved correctly.');
    }
}
