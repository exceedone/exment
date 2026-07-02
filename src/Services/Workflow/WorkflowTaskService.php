<?php

namespace Exceedone\Exment\Services\Workflow;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\WorkflowTaskRead;
use Illuminate\Support\Collection;

/**
 * Feature 1: gather the current login user's un-actioned workflow tasks across
 * every workflow-enabled table, and manage the per-user "seen" state (so the
 * navbar icon can show an unseen-count badge, like the notification bell).
 */
class WorkflowTaskService
{
    /**
     * Build the task_key that identifies a single pending task instance.
     * Uses the current pending workflow value id when available (so moving to
     * the next status is treated as a new, unseen task); falls back to the
     * record identity for start-status tasks that have no workflow value yet.
     *
     * @param int|string $customTableId
     * @param int|string $morphId
     * @param int|string|null $workflowValueId
     * @return string
     */
    public static function taskKey($customTableId, $morphId, $workflowValueId = null): string
    {
        $suffix = is_nullorempty($workflowValueId) ? 'start' : ('wv' . $workflowValueId);
        return $customTableId . ':' . $morphId . ':' . $suffix;
    }

    /**
     * Gather all un-actioned workflow tasks of the CURRENT login user.
     *
     * @return Collection each item is an array of task fields (incl. task_key)
     */
    public function getTasks(): Collection
    {
        $rows = collect();

        $customTableIds = \DB::table(SystemTableName::WORKFLOW_TABLE)
            ->where('active_flg', 1)
            ->pluck('custom_table_id')
            ->unique();

        foreach ($customTableIds as $customTableId) {
            $custom_table = CustomTable::getEloquent($customTableId);
            if (is_nullorempty($custom_table)) {
                continue;
            }

            $tableName = getDBTableName($custom_table);
            $modelName = getModelName($custom_table->table_name);

            $query = $modelName::query();
            // reuse the existing, tested "records the current user must act on" logic
            RelationTable::setWorkflowWorkUsersSubQuery($query, $custom_table, false);

            $values = $query->with(['workflow_value'])
                ->select($tableName . '.*')
                ->distinct()
                ->get();

            foreach ($values as $value) {
                $workflowValueId = optional($value->workflow_value)->id;
                $rows->push([
                    'custom_table_id'   => $custom_table->id,
                    'table_view_name'   => $custom_table->table_view_name,
                    'icon'              => $custom_table->getOption('icon') ?: 'fa-tasks',
                    'color'             => $custom_table->getOption('color') ?: null,
                    'morph_id'          => $value->id,
                    'label'             => $value->getLabel(),
                    'url'               => $value->getUrl(),
                    'status_name'       => $value->workflow_status_name,
                    'status_tag'        => $value->workflow_status_tag,
                    'updated_at'        => $value->updated_at,
                    'workflow_value_id' => $workflowValueId,
                    'task_key'          => static::taskKey($custom_table->id, $value->id, $workflowValueId),
                ]);
            }
        }

        return $rows->sortByDesc('updated_at')->values();
    }

    /**
     * Task keys already seen by the current login user.
     *
     * @return array<string>
     */
    public function seenKeys(): array
    {
        return WorkflowTaskRead::where('target_user_id', \Exment::getUserId())
            ->pluck('task_key')
            ->all();
    }

    /**
     * Gather tasks and annotate each with a "seen" flag.
     *
     * @return Collection
     */
    public function getTasksWithSeen(): Collection
    {
        $seen = array_flip($this->seenKeys());
        return $this->getTasks()->map(function ($row) use ($seen) {
            $row['seen'] = array_key_exists($row['task_key'], $seen);
            return $row;
        });
    }

    /**
     * Number of pending tasks the current user has NOT seen yet.
     */
    public function countUnseen(): int
    {
        return $this->getTasksWithSeen()->filter(function ($row) {
            return !$row['seen'];
        })->count();
    }

    /**
     * Mark the given task keys as seen for the current login user (idempotent).
     *
     * @param array<string> $taskKeys
     * @return void
     */
    public function markSeen(array $taskKeys): void
    {
        $userId = \Exment::getUserId();
        foreach (array_unique($taskKeys) as $taskKey) {
            if (is_nullorempty($taskKey)) {
                continue;
            }
            WorkflowTaskRead::firstOrCreate([
                'target_user_id' => $userId,
                'task_key'       => $taskKey,
            ]);
        }
    }

    /**
     * Mark every currently-pending task as seen for the current login user.
     *
     * @return void
     */
    public function markAllSeen(): void
    {
        $this->markSeen($this->getTasks()->pluck('task_key')->all());
    }
}
