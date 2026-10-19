<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\CustomValueController;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Form\Field;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * The record actions under admin/data/{tableKey} that no test had sent: PATCH and DELETE of a
 * record and its restore from the trash, the preview, a revision restore, attachments and file
 * columns, comments, the import, the plugin buttons, the workflow action dialog, the copy,
 * operation, notify and share dialogs, and the QR code sheets.
 *
 * Exment keeps the records it read in a static request cache (System::requestSession), which a
 * new PHP process empties on every request in production; the tests empty it before each request
 * so a request does not see the records the previous one read.
 */
class CustomValueActionsCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

    /**
     * Files the tests put on the upload disk, which the transaction does not undo.
     *
     * @var array<string>
     */
    protected array $storedPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        // saving a record, a comment or an attachment notifies the table's notify targets
        Notification::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->storedPaths as $path) {
            Storage::disk(config('admin.upload.disk'))->delete($path);
        }
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testPatchDeleteAndRestoreARecord(): void
    {
        $custom_value = $this->createValue(['text' => 'cov patch before', 'index_text' => 'cov patch index']);
        $id = $custom_value->id;

        // the edit screen sends PUT; PATCH is its own route to the same update
        System::clearRequestSession();
        $response = $this->patch($this->url($id), [
            'value' => ['text' => 'cov patch after', 'index_text' => 'cov patch index'],
        ]);
        $response->assertRedirect()->assertSessionHasNoErrors();
        $location = (string) $response->headers->get('Location');
        $this->assertTrue(str_starts_with($location, $this->url()), "redirected to {$location}");
        $patched = $this->reload($id);
        $this->assertNotNull($patched);
        $this->assertSame('cov patch after', $patched->getValue('text'));
        $this->assertSame('cov patch index', $patched->getValue('index_text'));

        System::clearRequestSession();
        $this->delete($this->url($id))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);
        $this->assertNull($this->reload($id));
        $this->assertTrue((bool) $this->reload($id, true)?->trashed());

        // the list's restore button of the trash view
        System::clearRequestSession();
        $this->post($this->url('rowRestore'), ['id' => $id])
            ->assertOk()
            ->assertJson(['result' => true, 'message' => exmtrans('custom_value.message.restore_succeeded')]);
        $this->assertSame('cov patch after', $this->reload($id)?->getValue('text'));
    }

    public function testPreviewShowsThePostedValuesOnTheTableForm(): void
    {
        System::clearRequestSession();
        $this->post($this->url('preview'), ['value' => ['text' => 'cov preview new']])
            ->assertOk()
            ->assertSeeText('cov preview new');

        // editing starts from the stored record: a column not posted keeps its stored value
        $custom_value = $this->createValue(['text' => 'cov preview stored', 'index_text' => 'cov preview kept']);
        System::clearRequestSession();
        $this->post($this->url($custom_value->id, 'preview'), ['value' => ['text' => 'cov preview edited']])
            ->assertOk()
            ->assertSeeText('cov preview edited')
            ->assertSeeText('cov preview kept')
            ->assertDontSeeText('cov preview stored');
        // a preview saves nothing
        $this->assertSame('cov preview stored', $this->reload($custom_value->id)?->getValue('text'));
    }

    public function testRestoringARevisionPutsItsValuesBack(): void
    {
        $custom_value = $this->createValue(['text' => 'cov revision zero']);
        $custom_value->setValue('text', 'cov revision one')->save();
        $custom_value->setValue('text', 'cov revision two')->save();

        $revision = $custom_value->revisionHistory()->get()->first(function ($revision) {
            $value = json_decode((string) $revision->new_value, true);
            return is_array($value) && array_get($value, 'text') === 'cov revision one';
        });
        $this->assertNotNull($revision, 'saving the record wrote no revision');

        System::clearRequestSession();
        $this->post($this->url($custom_value->id, 'compare'), ['revision' => $revision->suuid])
            ->assertRedirect($custom_value->getUrl());
        $this->assertSame('cov revision one', $this->reload($custom_value->id)?->getValue('text'));
    }

    public function testAnAttachmentIsStoredAndAFileColumnValueDeleted(): void
    {
        $custom_value = $this->createValue(['text' => 'cov attachment']);
        $disk = Storage::disk(config('admin.upload.disk'));

        System::clearRequestSession();
        $this->post($this->url($custom_value->id, 'fileupload'), [
            'file_data' => UploadedFile::fake()->createWithContent('cov-attachment.txt', 'cov attachment body'),
        ])->assertOk()->assertJson(['result' => true, 'message' => trans('admin.update_succeeded')]);

        $attachment = ExmentFile::where('parent_type', static::TABLE)
            ->where('parent_id', $custom_value->id)
            ->where('filename', 'cov-attachment.txt')
            ->first();
        $this->assertNotNull($attachment);
        $this->storedPaths[] = $attachment->path;
        $this->assertSame('cov attachment body', $disk->get($attachment->path));
        // and the document row the record's attachment list reads
        $documents = CustomTable::getEloquent(SystemTableName::DOCUMENT)->getValueModel()->query()
            ->where('parent_type', static::TABLE)
            ->where('parent_id', $custom_value->id)
            ->count();
        $this->assertSame(1, $documents);

        // a file column's value, as saving the form leaves it
        $column = CustomColumn::getEloquent('file', static::TABLE);
        $this->assertNotNull($column);
        $file = ExmentFile::storeAs(FileType::CUSTOM_VALUE_COLUMN, 'cov file column body', static::TABLE, 'cov-column.txt');
        $file->saveCustomValueAndColumn($custom_value->id, $column, $this->table());
        $this->storedPaths[] = $file->path;
        $custom_value->setValue('file', $file->path)->save();

        System::clearRequestSession();
        $this->put($this->url($custom_value->id, 'filedelete'), [
            Field::FILE_DELETE_FLAG => 'file',
            'key' => $file->path,
        ])->assertOk()->assertJson(['result' => true, 'message' => trans('admin.delete_succeeded'), 'reload' => false]);

        $this->assertNull($this->reload($custom_value->id)?->getValue('file'));
        $this->assertFalse(ExmentFile::where('uuid', $file->uuid)->exists());
        $this->assertFalse($disk->exists($file->path));
    }

    public function testACommentIsAddedAndDeleted(): void
    {
        $custom_value = $this->createValue(['text' => 'cov comment']);

        System::clearRequestSession();
        $this->post($this->url($custom_value->id, 'addcomment'), ['comment' => 'cov comment body'])
            ->assertRedirect($this->url($custom_value->id));

        $comment = CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel()->query()
            ->where('parent_type', static::TABLE)
            ->where('parent_id', $custom_value->id)
            ->first();
        $this->assertNotNull($comment);
        $this->assertSame('cov comment body', $comment->getValue('comment_detail'));

        System::clearRequestSession();
        $this->delete($this->url($custom_value->id, 'deletecomment', $comment->suuid))
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => trans('admin.delete_succeeded')]);
        $this->assertFalse(CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel()->query()
            ->where('suuid', $comment->suuid)
            ->exists());
    }

    public function testACsvImportAddsItsRows(): void
    {
        // the layout the export writes: keys, then labels, then one record per line (no id: new)
        $csv = implode("\n", [
            'id,value.text,value.index_text',
            'ID,text,index_text',
            ',cov import one,cov import key',
            ',cov import two,cov import key',
        ]) . "\n";

        System::clearRequestSession();
        $this->post($this->url('import'), [
            'custom_table_file' => UploadedFile::fake()->createWithContent(static::TABLE . '.csv', $csv),
            'select_primary_key' => 'id',
        ])->assertOk()->assertJson(['result' => true, 'toastr' => exmtrans('common.message.import_success')]);

        $texts = CustomTable::getEloquent(static::TABLE)->getValueModel()->query()
            ->where('value->index_text', 'cov import key')
            ->get()
            ->map(fn ($custom_value) => $custom_value->getValue('text'))
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['cov import one', 'cov import two'], $texts);
    }

    public function testPluginButtonsOfARecordAndOfTheList(): void
    {
        $match = $this->createValue(['text' => 'cov plugin match', 'multiples_of_3' => 1]);
        $other = $this->createValue(['text' => 'cov plugin other', 'multiples_of_3' => 0]);

        // TestPluginButton (a record's button) answers by the record's multiples_of_3
        System::clearRequestSession();
        $this->post($this->url($match->id, 'pluginClick'), [
            'uuid' => '6f0777c0-8e36-11ea-ab12-0800200c9a66',
            'plugin_type' => PluginType::BUTTON,
        ])->assertOk()->assertJson(['result' => true, 'swaltext' => '正常です。']);

        System::clearRequestSession();
        $this->post($this->url($other->id, 'pluginClick'), [
            'uuid' => '6f0777c0-8e36-11ea-ab12-0800200c9a66',
            'plugin_type' => PluginType::BUTTON,
        ])->assertStatus(400)->assertJson(['result' => false, 'swaltext' => 'エラーです。']);

        // TestPluginGridButton (the list's button) gets the records ticked on the list
        $ids = [$match->id, $other->id];
        sort($ids);
        System::clearRequestSession();
        $this->post($this->url('pluginClick'), [
            'uuid' => '8c1f7a3e-5b2d-4e6a-9f10-3c4d5e6f7a81',
            'plugin_type' => PluginType::BUTTON,
            'select_ids' => $ids,
        ])->assertOk()->assertJson(['result' => true, 'swaltext' => 'selected: ' . implode(',', $ids)]);
    }

    public function testAWorkflowActionIsOfferedRunAndListedInTheHistory(): void
    {
        $workflow = Workflow::where('workflow_view_name', 'workflow_common_company')->first();
        $this->assertNotNull($workflow);
        $action = WorkflowAction::where('workflow_id', $workflow->id)->where('action_name', 'middle_action')->first();
        $middle = WorkflowStatus::where('workflow_id', $workflow->id)->where('status_name', 'middle')->first();
        $this->assertNotNull($action);
        $this->assertNotNull($middle);

        // the table's workflow starts with an action of the record's creator: the admin creates it
        $custom_value = $this->createValue(['text' => 'cov workflow']);
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        System::clearRequestSession();
        $modal = $this->get($this->url($custom_value->id, 'actionModal') . '?action_id=' . $action->id, $ajax)
            ->assertOk()
            ->assertJson(['title' => 'middle_action']);
        $this->assertStringContainsString($this->url($custom_value->id, 'actionClick'), (string) $modal->json('body'));

        System::clearRequestSession();
        $this->post($this->url($custom_value->id, 'actionClick'), [
            'action_id' => $action->id,
            'comment' => 'cov workflow comment',
        ], $ajax)->assertOk()->assertJson(['result' => true, 'toastr' => exmtrans('common.message.success_execute')]);

        $latest = WorkflowValue::where('morph_type', static::TABLE)
            ->where('morph_id', $custom_value->id)
            ->where('latest_flg', true)
            ->first();
        $this->assertNotNull($latest);
        $this->assertEquals($middle->id, $latest->workflow_status_to_id);
        $this->assertEquals($action->id, $latest->workflow_action_id);

        System::clearRequestSession();
        $history = $this->get($this->url($custom_value->id, 'workflowHistoryModal'), $ajax)
            ->assertOk()
            ->assertJson(['title' => exmtrans('common.workflow_history')]);
        $this->assertStringContainsString('middle_action', (string) $history->json('body'));
        $this->assertStringContainsString('cov workflow comment', (string) $history->json('body'));
    }

    public function testTheCopyAndOperationDialogs(): void
    {
        $table = $this->table();
        $custom_value = $this->createValue(['text' => 'cov dialogs']);
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $copy = new CustomCopy();
        $copy->from_custom_table_id = $table->id;
        $copy->to_custom_table_id = $table->id;
        $copy->setOption('label', 'cov copy label');
        $copy->save();

        System::clearRequestSession();
        $dialog = $this->get($this->url($custom_value->id, 'copyModal') . '?uuid=' . $copy->suuid, $ajax)
            ->assertOk()
            ->assertJson(['title' => 'cov copy label']);
        $this->assertStringContainsString($this->url($custom_value->id, 'copyClick'), (string) $dialog->json('body'));

        $operation = new CustomOperation();
        $operation->custom_table_id = $table->id;
        $operation->operation_name = 'cov operation';
        $operation->operation_type = [CustomOperationType::BUTTON];
        $operation->setOption('label', 'cov operation label');
        $operation->save();

        // a record's button
        System::clearRequestSession();
        $dialog = $this->get($this->url($custom_value->id, 'operationModal') . '?suuid=' . $operation->suuid, $ajax)
            ->assertOk()
            ->assertJson(['title' => 'cov operation label']);
        $this->assertStringContainsString($this->url($custom_value->id, 'operationClick'), (string) $dialog->json('body'));

        // the list's button, for the ticked records
        System::clearRequestSession();
        $dialog = $this->get($this->url('operationModal') . '?suuid=' . $operation->suuid . '&id=' . $custom_value->id, $ajax)
            ->assertOk()
            ->assertJson(['title' => 'cov operation label']);
        $body = (string) $dialog->json('body');
        $this->assertStringContainsString($this->url('operationClick'), $body);
        $this->assertMatchesRegularExpression('/<input(?=[^>]*name="id")(?=[^>]*value="' . $custom_value->id . '")[^>]*>/', $body);
    }

    public function testTheNotifyDialogForChosenUsersAndSavingShares(): void
    {
        // the test data's notify of the "button" trigger, as NotifyTest::testNotifyButton uses it
        $notify = Notify::where('notify_trigger', NotifyTrigger::BUTTON)->first();
        $this->assertNotNull($notify);
        $notify->active_flg = 1;
        $notify->save();
        $notify_table = CustomTable::getEloquent($notify->target_id);
        $this->assertNotNull($notify_table);
        $target = $notify_table->getValueModel()->query()->where('created_user_id', '<>', \Exment::getUserId())->first();
        $this->assertNotNull($target);

        $keys = [];
        foreach ($notify->action_settings as $action_setting) {
            foreach ($notify->getNotifyTargetUsers($target, $action_setting) as $user) {
                $keys[] = $user->notifyKey();
            }
        }
        $keys = array_values(array_unique($keys));
        $this->assertNotEmpty($keys);

        System::clearRequestSession();
        $dialog = $this->post(admin_urls('data', $notify_table->table_name, $target->id, 'sendTargetUsers'), [
            'mail_template_id' => $notify->suuid,
            'target_users' => $keys,
        ])->assertOk()->assertJson(['title' => exmtrans('custom_value.sendmail.title')]);
        $this->assertStringContainsString(admin_urls('data', $notify_table->table_name, $target->id, 'sendMail'), (string) $dialog->json('body'));

        // sharing a record with a user stores the share
        $shared = $this->createValue(['text' => 'cov share']);
        $user2 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertNotNull($user2);
        System::clearRequestSession();
        $this->post($this->url($shared->id, 'sendShares'), [
            'custom_value_edit' => ['user_' . $user2->base_user_id],
        ])->assertOk()->assertJson(['result' => true, 'toastr' => trans('admin.save_succeeded')]);
        $this->assertDatabaseHas(SystemTableName::CUSTOM_VALUE_AUTHORITABLE, [
            'parent_type' => static::TABLE,
            'parent_id' => $shared->id,
            'authoritable_type' => 'custom_value_edit',
            'authoritable_user_org_type' => 'user',
            'authoritable_target_id' => $user2->base_user_id,
        ]);
    }

    public function testTheQrCodeDialogAndTheRequestsItRefuses(): void
    {
        System::clearRequestSession();
        $dialog = $this->get($this->url('form_create_qrcode'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJson(['title' => exmtrans('custom_table.qr_code.form_title'), 'submitlabel' => exmtrans('common.save')]);
        $this->assertStringContainsString('name="qr_number"', (string) $dialog->json('body'));
        $this->assertStringContainsString($this->url('create_qrcode'), (string) $dialog->json('body'));

        // no sheet is built for zero codes, nor for a download without ticked records
        System::clearRequestSession();
        $this->post($this->url('create_qrcode'), ['qr_number' => 0])
            ->assertOk()
            ->assertJson(['result' => false, 'message' => exmtrans('custom_table.qr_code.validate_qr_number')]);
        System::clearRequestSession();
        $this->post($this->url('qrcode_download'), [])
            ->assertStatus(400)
            ->assertJson(['result' => false, 'message' => exmtrans('custom_table.no_selected')]);
    }

    public function testTheQrCodeSheetsAreBuiltAsPdf(): void
    {
        // qrCreateOrDownloadResponse() sends its answer itself and then ends the process with exit,
        // which PHPUnit cannot survive. The controller is swapped for one whose deleteTmpFile() -
        // the last call before exit - stops the request there, so the answer it sent can be read.
        $this->app->bind(CustomValueController::class, function (Application $app) {
            return new class ($app->make(CustomTable::class), $app->make(Request::class)) extends CustomValueController {
                /**
                 * @param string $file_path
                 */
                protected function deleteTmpFile($file_path): never
                {
                    parent::deleteTmpFile($file_path);
                    throw new HttpResponseException(response()->noContent());
                }
            };
        });
        $table = $this->table();
        $before = $table->getValueModel()->query()->count();

        System::clearRequestSession();
        $sent = $this->sentBy(fn () => $this->post($this->url('create_qrcode'), ['qr_number' => 2])->assertNoContent());
        $this->assertSame(exmtrans('custom_table.qr_code.created'), $sent['toastr']);
        $this->assertPdf($sent);
        // a new sheet is made for new, empty records, and turns the table's QR code on
        $this->assertSame($before + 2, $table->getValueModel()->query()->count());
        System::clearCache();
        $this->assertTrue(boolval(CustomTable::getEloquent(static::TABLE)?->getOption('qr_use')));

        $custom_value = $this->createValue(['text' => 'cov qr download']);
        System::clearRequestSession();
        $sent = $this->sentBy(fn () => $this->post($this->url('qrcode_download'), ['select_ids' => [$custom_value->id]])->assertNoContent());
        $this->assertSame(exmtrans('custom_table.qr_code.download_complete'), $sent['toastr']);
        $this->assertPdf($sent);
    }

    /**
     * What a request echoed on its own (Response::send()), decoded as json.
     *
     * @return array<string, mixed>
     */
    protected function sentBy(\Closure $request): array
    {
        ob_start();
        try {
            $request();
        } finally {
            $output = (string) ob_get_clean();
        }
        $sent = json_decode($output, true);
        $this->assertIsArray($sent, 'the request sent no json: ' . substr($output, 0, 300));

        return $sent;
    }

    /**
     * @param array<string, mixed> $sent
     */
    protected function assertPdf(array $sent): void
    {
        $this->assertSame('application/pdf', $sent['fileContentType']);
        $this->assertStringEndsWith('.pdf', (string) $sent['fileName']);
        $this->assertStringStartsWith('%PDF', (string) base64_decode((string) $sent['fileBase64'], true));
    }

    protected function table(): CustomTable
    {
        $table = CustomTable::getEloquent(static::TABLE);
        if (!$table instanceof CustomTable) {
            $this->fail('the test data has no ' . static::TABLE . ' table');
        }

        return $table;
    }

    /**
     * A record of the table, created by the logged-in admin.
     *
     * @param array<string, mixed> $value
     */
    protected function createValue(array $value): CustomValue
    {
        $custom_value = $this->table()->getValueModel();
        if (!$custom_value instanceof CustomValue) {
            $this->fail('no value model for ' . static::TABLE);
        }
        $custom_value->setValue($value);
        $custom_value->save();

        return $custom_value;
    }

    protected function reload(int $id, bool $withTrashed = false): ?CustomValue
    {
        System::clearRequestSession();

        return $this->table()->getValueModel($id, $withTrashed);
    }

    protected function url(string|int ...$paths): string
    {
        return admin_urls('data', static::TABLE, ...$paths);
    }
}
