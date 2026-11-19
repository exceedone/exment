<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\CopyColumnType;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\OperationUpdateType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Str;

/**
 * Form, view, copy and operation settings that no test reached: the form designer's setting
 * modal, the previews and the PATCH/DELETE of a form (admin/form/{tableKey}), the previews,
 * the sharing and the PATCH of a view (admin/view/{tableKey}), the PATCH/DELETE of a data copy
 * (admin/copy/{tableKey}) and the PUT/PATCH/DELETE of an operation (admin/operation/{tableKey}).
 * Each answers json, a rendered page or a redirect; none of them exports or exits.
 */
class FormViewSettingsCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    // CustomFormController (admin/form/{tableKey}) ---------------------------------

    public function testFormSettingModal(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $column = CustomColumn::getEloquent('index_text', $table);

        $response = $this->post(admin_url("form/{$table->table_name}/settingModal"), [
            'form_column_type' => FormColumnType::COLUMN,
            'form_column_target_id' => $column->id,
            'header_column_name' => 'custom_form_blocks[NEW__1][custom_form_columns][NEW__1]',
            'form_block_type' => FormBlockType::DEFAULT,
            'form_block_target_table_id' => $table->id,
            'options' => [],
            'widgetmodal_uuid' => 'claude-cov-modal-uuid',
        ])->assertOk()->assertJson([
            'result' => true,
            'title' => trans('admin.setting'),
            'modalClass' => 'modal-customform',
            'preventSubmit' => true,
        ]);

        $body = (string) $response->json('body');
        $this->assertStringContainsString('form_column_view_name', $body);
        $this->assertStringContainsString('claude-cov-modal-uuid', $body, 'the modal keeps the uuid of the column it edits');
    }

    public function testFormPreviewBySuuid(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $form = CustomForm::getDefault($table);

        $this->get(admin_url("form/{$table->table_name}/preview/{$form->suuid}"))
            ->assertOk()
            ->assertSee('name="value[index_text]"', false);
    }

    public function testFormPreviewOfNewSettings(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        // the create screen posts its blocks and columns under NEW_ keys
        $this->post(admin_url("form/{$table->table_name}/preview"), $this->formPayload($table, true))
            ->assertOk()
            ->assertSee('name="value[index_text]"', false);
    }

    public function testFormPreviewOfEditedSettings(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        // the edit screen sends the same preview with PUT and the saved ids as keys
        $this->put(admin_url("form/{$table->table_name}/preview"), $this->formPayload($table, false))
            ->assertOk()
            ->assertSee('name="value[index_text]"', false);
    }

    public function testFormPatch(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $form = CustomForm::getDefault($table);
        $columnIds = $this->formColumnIds($form);
        $this->assertNotEmpty($columnIds);
        $payload = $this->formPayload($table, false, false);
        $payload['form_view_name'] = 'form_default (claude patch)';

        $this->patch(admin_url("form/{$table->table_name}/{$form->id}"), $payload)
            ->assertRedirect(admin_url("form/{$table->table_name}"))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('custom_forms', [
            'id' => $form->id,
            'form_view_name' => 'form_default (claude patch)',
        ]);
        // the columns were not posted, so the form keeps every one of them
        $this->assertSame($columnIds, $this->formColumnIds($form));
    }

    public function testFormDelete(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $form = CustomForm::create([
            'custom_table_id' => $table->id,
            'form_view_name' => 'Claude form to delete',
            'default_flg' => false,
        ]);

        $this->delete(admin_url("form/{$table->table_name}/{$form->id}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);

        $this->assertDatabaseMissing('custom_forms', ['id' => $form->id]);
    }

    // CustomViewController (admin/view/{tableKey}) ---------------------------------

    public function testViewPreviewOfNewSettings(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $payload = $this->viewPayload($table, ViewKindType::DEFAULT, 'Claude preview header (post)');

        $this->post(admin_url("view/{$table->table_name}/preview"), $payload)
            ->assertOk()
            ->assertSee('Claude preview header (post)');
    }

    public function testViewPreviewOfNewSettingsByPut(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $payload = $this->viewPayload($table, ViewKindType::DEFAULT, 'Claude preview header (put)');

        $this->put(admin_url("view/{$table->table_name}/preview"), $payload)
            ->assertOk()
            ->assertSee('Claude preview header (put)');
    }

    public function testViewPreviewOfASavedView(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $view = $this->allDataView($table);
        $payload = $this->viewPayload($table, ViewKindType::ALLDATA, 'Claude preview header (saved post)');

        $this->post(admin_url("view/{$table->table_name}/{$view->id}/preview"), $payload)
            ->assertOk()
            ->assertSee('Claude preview header (saved post)');
        // a preview never saves
        $this->assertDatabaseMissing('custom_view_columns', ['view_column_name' => 'Claude preview header (saved post)']);
    }

    public function testViewPreviewOfASavedViewByPut(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $view = $this->allDataView($table);
        $payload = $this->viewPayload($table, ViewKindType::ALLDATA, 'Claude preview header (saved put)');

        $this->put(admin_url("view/{$table->table_name}/{$view->id}/preview"), $payload)
            ->assertOk()
            ->assertSee('Claude preview header (saved put)');
    }

    /**
     * A setting row still half-filled on screen - a column with no target, a filter with no
     * condition - is left out of the preview, which says how many rows it left out and draws
     * the finished ones as usual.
     */
    public function testViewPreviewLeavesOutUnfinishedSettingRowsAndSaysHowMany(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $payload = $this->viewPayload($table, ViewKindType::DEFAULT, 'Claude preview header (finished)');
        $payload['custom_view_columns']['new_2'] = [
            'view_column_target' => '',
            'order' => 2,
            'view_column_name' => 'Claude preview header (no target)',
            '_remove_' => 0,
        ];
        $payload['custom_view_filters'] = [
            'new_1' => [
                'view_column_target' => $this->allDataViewColumn($table, 'odd_even')->view_column_target,
                'view_filter_condition' => '',
                '_remove_' => 0,
            ],
        ];

        $this->post(admin_url("view/{$table->table_name}/preview"), $payload)
            ->assertOk()
            ->assertSee('Claude preview header (finished)')
            ->assertSee(exmtrans('custom_view.message.preview_skipped', ['count' => 2]), false);
    }

    /**
     * The warning counts setting rows, not records: a column that is empty in the records is
     * drawn as empty cells, as on the data screen, and nothing is said to be left out.
     */
    public function testViewPreviewOfAColumnEmptyInTheRecordsLeavesNothingOut(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        // the seeder fills "user" and "odd_even" of every record; this one has neither
        $record = $table->getValueModel();
        $this->assertInstanceOf(CustomValue::class, $record);
        $record->setValue(['text' => 'Claude preview record (no user)'])->save();

        $response = $this->post(admin_url("view/{$table->table_name}/preview"), [
            'view_view_name' => 'Claude preview',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
            'custom_view_columns' => [
                'new_1' => [
                    'view_column_target' => $this->allDataViewColumn($table, 'text')->view_column_target,
                    'order' => 1,
                    'view_column_name' => 'Claude preview header (text)',
                    '_remove_' => 0,
                ],
                'new_2' => [
                    'view_column_target' => $this->allDataViewColumn($table, 'user')->view_column_target,
                    'order' => 2,
                    'view_column_name' => 'Claude preview header (user)',
                    '_remove_' => 0,
                ],
            ],
            // only the records with no odd_even, so the one made above is on the first page
            'custom_view_filters' => [
                'new_1' => [
                    'view_column_target' => $this->allDataViewColumn($table, 'odd_even')->view_column_target,
                    'view_filter_condition' => FilterOption::NULL,
                    '_remove_' => 0,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertSee('Claude preview header (user)')
            ->assertSee('Claude preview record (no user)')
            // the page draws its messages, so the warning would be on it
            ->assertSee(exmtrans('common.message.preview'), false);
        // the words of the warning after its count, in the language the page was drawn in
        $skipped = exmtrans('custom_view.message.preview_skipped');
        $this->assertIsString($skipped);
        $this->assertStringContainsString(':count', $skipped);
        $response->assertDontSee(trim(Str::after($skipped, ':count')), false);
    }

    /**
     * A sort row whose column the list can no longer offer - here a column with no search index -
     * stays on the edit screen with its target, named with a label that says it cannot be chosen
     * in this setting, instead of being dropped from the form.
     */
    public function testViewEditKeepsASortTargetTheListNoLongerOffersWithALabel(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $column = CustomColumn::getEloquent('integer', $table);
        $this->assertNotNull($column);
        $this->assertFalse(boolval($column->index_enabled), 'a sort offers indexed columns only');

        $view = CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'Claude view sorted by a column it cannot offer',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
        ]);
        $sort = new CustomViewSort();
        $sort->custom_view_id = $view->id;
        $sort->view_column_type = ConditionType::COLUMN;
        $sort->view_column_table_id = $table->id;
        $sort->view_column_target_id = $column->id;
        $sort->sort = 1;
        $sort->priority = 1;
        $sort->save();
        // exment caches records for a request, and the view above is new to it
        System::clearRequestSession();

        $this->get(admin_url("view/{$table->table_name}/{$view->id}/edit"), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee(exmtrans('custom_view.column_target_unavailable', $column->column_view_name), false);
    }

    public function testViewSendShares(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        // a user view of the admin (created_user_id is the logged-in user): the one who may share it
        $view = CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'Claude shared view',
            'view_type' => ViewType::USER,
            'view_kind_type' => ViewKindType::DEFAULT,
        ]);
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $this->post(admin_url("view/{$table->table_name}/{$view->id}/sendShares"), [
            Permission::DATA_SHARE_VIEW => ['user_' . $user2->base_user_id],
        ])->assertOk()->assertJson(['result' => true, 'toastr' => trans('admin.save_succeeded')]);

        $this->assertDatabaseHas('data_share_authoritables', [
            'parent_type' => '_custom_view',
            'parent_id' => $view->id,
            'authoritable_type' => Permission::DATA_SHARE_VIEW,
            'authoritable_user_org_type' => 'user',
            'authoritable_target_id' => $user2->base_user_id,
        ]);
    }

    public function testViewPatch(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $view = $this->allDataView($table);

        $this->patch(admin_url("view/{$table->table_name}/{$view->id}"), [
            'view_view_name' => 'view-all (claude patch)',
        ])->assertRedirect(admin_url("view/{$table->table_name}"))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('custom_views', ['id' => $view->id, 'view_view_name' => 'view-all (claude patch)']);
    }

    // CustomCopyController (admin/copy/{tableKey}) ---------------------------------

    public function testCopyPatchAndDelete(): void
    {
        $from = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $to = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $fromText = CustomColumn::getEloquent('text', $from);
        $toText = CustomColumn::getEloquent('text', $to);
        $mapping = [
            'from_column_target' => "{$fromText->id}?table_id={$from->id}",
            'to_column_target' => "{$toText->id}?table_id={$to->id}",
            'copy_column_type' => CopyColumnType::DEFAULT,
            '_remove_' => 0,
        ];

        // set up through the create screen, as CCustomCopyTest does: "text" is required in the
        // copy target, so the copy has to fill it
        $this->post(admin_url("copy/{$from->table_name}"), [
            'from_custom_table_id' => $from->id,
            'to_custom_table_id' => $to->id,
            'options' => ['label' => 'Claude copy', 'icon' => 'fa-copy'],
            'custom_copy_columns' => ['new_1' => $mapping],
        ])->assertRedirect(admin_url("copy/{$from->table_name}"))->assertSessionHasNoErrors();
        $copy = CustomCopy::query()->where('from_custom_table_id', $from->id)->orderByDesc('id')->firstOrFail();
        $row = $copy->custom_copy_columns()->firstOrFail();

        $this->patch(admin_url("copy/{$from->table_name}/{$copy->id}"), [
            'options' => ['label' => 'Claude copy (patch)', 'icon' => 'fa-copy'],
            'custom_copy_columns' => [$row->id => array_merge(['id' => $row->id], $mapping)],
        ])->assertRedirect(admin_url("copy/{$from->table_name}"))->assertSessionHasNoErrors();
        $this->assertSame('Claude copy (patch)', CustomCopy::query()->where('id', $copy->id)->firstOrFail()->getOption('label'));
        $this->assertSame(1, $copy->custom_copy_columns()->count(), 'the posted mapping row is kept, not added again');

        $this->delete(admin_url("copy/{$from->table_name}/{$copy->id}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);
        $this->assertDatabaseMissing('custom_copies', ['id' => $copy->id]);
        $this->assertDatabaseMissing('custom_copy_columns', ['custom_copy_id' => $copy->id]);
    }

    // CustomOperationController (admin/operation/{tableKey}) -----------------------

    public function testOperationPutPatchAndDelete(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $date = CustomColumn::getEloquent('date', $table);

        // set up through the create screen, as CCustomOperationTest does
        $this->post(admin_url("operation/{$table->table_name}"), [
            'custom_table_id' => $table->id,
            'operation_name' => 'Claude operation',
            'operation_type' => [CustomOperationType::BUTTON],
            'options' => ['button_label' => 'Claude operation button', 'condition_join' => 'and'],
            'custom_operation_columns' => [[
                'operation_update_type' => OperationUpdateType::DEFAULT,
                'view_column_target' => "{$date->id}?table_id={$table->id}",
                'update_value_text' => '2021-01-01',
                '_remove_' => 0,
            ]],
            'custom_operation_conditions' => [],
        ])->assertRedirect(admin_url("operation/{$table->table_name}"))->assertSessionHasNoErrors();
        $operation = CustomOperation::query()->where('custom_table_id', $table->id)->orderByDesc('id')->firstOrFail();

        $this->put(admin_url("operation/{$table->table_name}/{$operation->id}"), [
            'operation_name' => 'Claude operation (put)',
            'operation_type' => [CustomOperationType::BUTTON],
        ])->assertRedirect(admin_url("operation/{$table->table_name}"))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('custom_operations', ['id' => $operation->id, 'operation_name' => 'Claude operation (put)']);

        $this->patch(admin_url("operation/{$table->table_name}/{$operation->id}"), [
            'operation_name' => 'Claude operation (patch)',
            'operation_type' => [CustomOperationType::BUTTON],
        ])->assertRedirect(admin_url("operation/{$table->table_name}"))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('custom_operations', ['id' => $operation->id, 'operation_name' => 'Claude operation (patch)']);
        // the update columns were not posted, so they are kept
        $this->assertSame(1, $operation->custom_operation_columns()->count());

        $this->delete(admin_url("operation/{$table->table_name}/{$operation->id}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);
        $this->assertDatabaseMissing('custom_operations', ['id' => $operation->id]);
        $this->assertDatabaseMissing('custom_operation_columns', ['custom_operation_id' => $operation->id]);
    }

    // helpers -------------------------------------------------------------------

    /**
     * The form designer's post, built from the table's default form.
     *
     * @param CustomTable $table
     * @param bool $asNew post the keys as the create screen does (NEW_...) instead of the saved ids
     * @param bool $withColumns false: post the blocks only, as a save that leaves the columns alone
     * @return array<string, mixed>
     */
    protected function formPayload(CustomTable $table, bool $asNew, bool $withColumns = true): array
    {
        $form = CustomForm::getDefault($table);
        $blocks = [];
        foreach ($form->custom_form_blocks()->get() as $block) {
            $columns = [];
            if ($withColumns) {
                foreach ($block->custom_form_columns()->get() as $column) {
                    $columns[$asNew ? "NEW__{$column->id}" : (string) $column->id] = [
                        'form_column_type' => $column->form_column_type,
                        'form_column_target_id' => $column->form_column_target_id,
                        'row_no' => $column->row_no,
                        'column_no' => $column->column_no,
                        'width' => $column->width,
                        'options' => json_encode($column->options ?? []),
                    ];
                }
            }
            $blocks[$asNew ? "NEW__{$block->id}" : (string) $block->id] = [
                'form_block_type' => $block->form_block_type,
                'form_block_target_table_id' => $block->form_block_target_table_id,
                'available' => 1,
                'form_block_view_name' => $block->form_block_view_name,
                'options' => $block->options ?? [],
                'custom_form_columns' => $columns,
            ];
        }
        $this->assertNotEmpty($blocks, 'the default form of ' . $table->table_name . ' has blocks');

        return [
            'form_view_name' => $form->form_view_name,
            'default_flg' => $form->default_flg,
            'custom_form_blocks' => $blocks,
        ];
    }

    /**
     * The ids of the columns of a form, read from the database.
     *
     * @param CustomForm $form
     * @return array<int, mixed>
     */
    protected function formColumnIds(CustomForm $form): array
    {
        $ids = [];
        foreach ($form->custom_form_blocks()->get() as $block) {
            foreach ($block->custom_form_columns()->orderBy('id')->pluck('id') as $id) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * The view edit screen's post, with one column whose header the preview has to show.
     *
     * @param CustomTable $table
     * @param string $viewKindType
     * @param string $header the header label of the posted column
     * @return array<string, mixed>
     */
    protected function viewPayload(CustomTable $table, string $viewKindType, string $header): array
    {
        $column = CustomViewColumn::query()
            ->where('custom_view_id', $this->allDataView($table)->id)
            ->where('view_column_type', ConditionType::COLUMN)
            ->orderBy('order')
            ->firstOrFail();

        return [
            'view_view_name' => 'Claude preview',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => $viewKindType,
            'custom_view_columns' => [
                'new_1' => [
                    'view_column_target' => $column->view_column_target,
                    'order' => 1,
                    'view_column_name' => $header,
                    '_remove_' => 0,
                ],
            ],
        ];
    }

    /**
     * The "all data" view the seeder creates for every table, with a column per custom column.
     */
    protected function allDataView(CustomTable $table): CustomView
    {
        return CustomView::query()
            ->where('custom_table_id', $table->id)
            ->where('view_kind_type', ViewKindType::ALLDATA)
            ->where('view_view_name', $table->table_name . '-view-all')
            ->firstOrFail();
    }

    /**
     * The column of the "all data" view that shows this custom column: its view_column_target
     * is what the view edit screen posts for it.
     */
    protected function allDataViewColumn(CustomTable $table, string $columnName): CustomViewColumn
    {
        $customColumn = CustomColumn::getEloquent($columnName, $table);
        $this->assertNotNull($customColumn, "seed data must have the column $columnName");

        return CustomViewColumn::query()
            ->where('custom_view_id', $this->allDataView($table)->id)
            ->where('view_column_type', ConditionType::COLUMN)
            ->where('view_column_target_id', $customColumn->id)
            ->firstOrFail();
    }
}
