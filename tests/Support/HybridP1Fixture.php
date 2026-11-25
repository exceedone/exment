<?php

namespace Exceedone\Exment\Tests\Support;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowAuthority;
use Exceedone\Exment\Model\WorkflowConditionHeader;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowTable;

/**
 * Hybrid storage P1 test fixture: custom table p10_workflow_multi and an individual-table
 * workflow on it, for the workflow "column" authority cases on multi-value user and organization
 * columns (R3MultiValueWorkflowAuthorityTest).
 *
 * Creating the columns runs index DDL through the normal model events, so this cannot run inside
 * a test transaction. Provision it once on a test database, after exment:inittest, with
 * tests/Support/provision-hybrid-p1-fixture.php. It adds one workflow, so tests that count the
 * workflows of the seeded data (Feature\Api3WorkflowTest) must run on a database without it.
 */
class HybridP1Fixture
{
    public const TABLE = 'p10_workflow_multi';

    public static function exists(): bool
    {
        return CustomTable::getEloquent(static::TABLE) !== null;
    }

    /**
     * Create the table, its columns and its workflow when they do not exist yet.
     *
     * @return array<int, string> report lines
     */
    public static function provision(): array
    {
        if (!app()->environment('testing')) {
            throw new \RuntimeException('HybridP1Fixture only provisions a testing environment.');
        }

        System::clearCache();
        if (static::exists()) {
            return array_merge(['exists'], static::describe());
        }

        $name = static::TABLE;
        $table = CustomTable::create([
            'table_name' => $name,
            'table_view_name' => $name,
            'options' => ['search_enabled' => 1, 'all_user_editable_flg' => '1'],
        ]);
        System::clearRequestSession();
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap([$name => ltrim(getModelName($table, true), '\\')]);

        $columns = [
            ['index_text', ColumnType::TEXT, ['index_enabled' => '1']],
            ['users_multi', ColumnType::USER, ['index_enabled' => '1', 'multiple_enabled' => '1']],
            ['users_multi_noidx', ColumnType::USER, ['multiple_enabled' => '1']],
            ['org', ColumnType::ORGANIZATION, ['index_enabled' => '1']],
            ['orgs_multi', ColumnType::ORGANIZATION, ['index_enabled' => '1', 'multiple_enabled' => '1']],
        ];
        foreach ($columns as [$column_name, $type, $options]) {
            $column = CustomColumn::create([
                'custom_table_id' => $table->id,
                'column_name' => $column_name,
                'column_view_name' => $column_name,
                'column_type' => $type,
                'options' => $options,
            ]);
            if ($column_name === 'index_text') {
                CustomColumnMulti::create([
                    'custom_table_id' => $table->id,
                    'multisetting_type' => MultisettingType::TABLE_LABELS,
                    'options' => ['table_label_id' => $column->id],
                ]);
            }
        }

        $workflow = new Workflow();
        $workflow->workflow_view_name = $name;
        $workflow->workflow_type = 1;
        $workflow->setting_completed_flg = 1;
        $workflow->start_status_name = 'start';
        $workflow->save();

        $statuses = [];
        foreach (['p10_waiting', 'p10_done'] as $i => $status_name) {
            $status = new WorkflowStatus();
            $status->workflow_id = $workflow->id;
            $status->status_name = $status_name;
            $status->datalock_flg = 0;
            $status->completed_flg = $status_name === 'p10_done' ? 1 : 0;
            $status->order = $i;
            $status->save();
            $statuses[$status_name] = $status;
        }

        $actions = [
            ['p10_send', 'start', 'p10_waiting', [['related_id' => 0, 'related_type' => 'system']]],
            // the authorities of p10_approve are added by each test, inside its transaction
            ['p10_approve', 'p10_waiting', 'p10_done', []],
        ];
        foreach ($actions as [$action_name, $from, $to, $authorities]) {
            $action = new WorkflowAction();
            $action->workflow_id = $workflow->id;
            $action->action_name = $action_name;
            $action->ignore_work = 0;
            $action->status_from = $from === 'start' ? 'start' : $statuses[$from]->id;
            foreach (['comment_type' => 'nullable', 'flow_next_type' => 'some', 'flow_next_count' => '1',
                'work_target_type' => WorkflowWorkTargetType::FIX] as $k => $v) {
                $action->setOption($k, $v);
            }
            $action->save();
            foreach ($authorities as $authority) {
                WorkflowAuthority::insert($authority + ['workflow_action_id' => $action->id]);
            }
            $header = new WorkflowConditionHeader();
            $header->enabled_flg = true;
            $header->workflow_action_id = $action->id;
            $header->status_to = $statuses[$to]->id;
            $header->save();
        }

        $wfTable = new WorkflowTable();
        $wfTable->workflow_id = $workflow->id;
        $wfTable->custom_table_id = $table->id;
        $wfTable->active_flg = true;
        $wfTable->save();
        System::clearCache();

        return array_merge(['created'], static::describe());
    }

    /** @return array<int, string> */
    protected static function describe(): array
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $db = getDBTableName($table);
        $lines = [];
        foreach ($table->custom_columns as $col) {
            $lines[] = $col->column_name . ' ' . $col->column_type . ' idx=' . ($col->index_enabled ? 1 : 0)
                . ' multi=' . ($col->isMultipleEnabled() ? 1 : 0)
                . ' has_index_column=' . (hasColumn($db, 'column_' . $col->suuid) ? 1 : 0);
        }
        return $lines;
    }
}
