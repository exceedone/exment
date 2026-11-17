<?php

namespace Exceedone\Exment\Services\Workflow;

use Exceedone\Exment\Enums\WorkflowCommentType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\WorkflowAction;
use Illuminate\Support\Collection;

/**
 * Run a workflow action on the records ticked in the list (#1648).
 *
 * Each record goes through the same checks as the action button of its page
 * (CustomValueController::actionClick() and WorkflowAction::actionModal()): the
 * user can see the record, may run the action at its status and has not run it
 * already, and someone can do the next step. A record that does not pass is
 * skipped with the reason, the others are run one by one with
 * WorkflowAction::executeAction(), so notifications and plugins work as for one record.
 */
class WorkflowBulkService
{
    /** The most records one run takes (the list shows at most a few hundred per page). */
    public const MAX_COUNT = 500;

    public const SKIP_NOT_FOUND = 'not_found';
    public const SKIP_STATUS_CHANGED = 'status_changed';
    public const SKIP_NEXT_USER_NOT_FOUND = 'nextuser_not_found';
    public const SKIP_ERROR = 'error';

    /** @var CustomTable */
    protected $custom_table;

    /** @var array<int, int> the selected ids, in the order of the list */
    protected $ids;

    /** @var Collection<int, CustomValue>|null keyed by id */
    protected $custom_values;

    /** @var Collection<int, array{action: WorkflowAction, ids: array<int, int>}>|null keyed by action id */
    protected $candidates;

    /**
     * @param CustomTable $custom_table
     * @param mixed $ids "1,2,3" or [1, 2, 3]
     */
    public function __construct(CustomTable $custom_table, $ids)
    {
        $this->custom_table = $custom_table;
        $this->ids = static::parseIds($ids);
    }

    /**
     * @param mixed $ids
     * @return array<int, int>
     */
    public static function parseIds($ids): array
    {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        $result = [];
        foreach ((array)$ids as $id) {
            if (is_numeric($id) && (int)$id > 0 && !in_array((int)$id, $result, true)) {
                $result[] = (int)$id;
            }
        }
        return array_slice($result, 0, static::MAX_COUNT);
    }

    /**
     * @return array<int, int>
     */
    public function getIds(): array
    {
        return $this->ids;
    }

    /**
     * The selected records the user can see (the permission scope of the value model), keyed by id.
     *
     * @return Collection<int, CustomValue>
     */
    public function getCustomValues(): Collection
    {
        if (is_null($this->custom_values)) {
            $this->custom_values = count($this->ids) == 0
                ? collect()
                : $this->custom_table->getValueQuery()->whereIn('id', $this->ids)->get()->keyBy('id');
        }
        return $this->custom_values;
    }

    /**
     * The actions the user can run on at least one of the records, with those records.
     *
     * @return Collection<int, array{action: WorkflowAction, ids: array<int, int>}> keyed by action id
     */
    public function getCandidates(): Collection
    {
        if (!is_null($this->candidates)) {
            return $this->candidates;
        }

        /** @var array<int, array{action: WorkflowAction, ids: array<int, int>}> $candidates */
        $candidates = [];
        foreach ($this->getCustomValues() as $custom_value) {
            foreach ($custom_value->getWorkflowActions(true, false, true) as $workflow_action) {
                /** @var WorkflowAction $workflow_action */
                $actionId = (int)$workflow_action->id;
                if (!isset($candidates[$actionId])) {
                    $candidates[$actionId] = ['action' => $workflow_action, 'ids' => []];
                }
                $candidates[$actionId]['ids'][] = (int)$custom_value->id;
            }
        }
        uasort($candidates, function ($a, $b) {
            return [$a['action']->workflow_id, $a['action']->id] <=> [$b['action']->workflow_id, $b['action']->id];
        });

        return $this->candidates = collect($candidates);
    }

    /**
     * Whether one of the records needs the next work users chosen for the action.
     *
     * @param WorkflowAction $action
     * @return bool
     */
    public function needsNextWorkUsers(WorkflowAction $action): bool
    {
        $candidate = $this->getCandidates()->get($action->id);
        if (is_null($candidate)) {
            return false;
        }
        foreach ($candidate['ids'] as $id) {
            if ($action->getNextWorkUsersRequirement($this->getCustomValues()->get($id))['select']) {
                return true;
            }
        }
        return false;
    }

    /**
     * The error of the input, before any record is run.
     *
     * @param WorkflowAction|null $action
     * @param array<string, mixed> $data comment, next_work_users
     * @return string|null
     */
    public function validate(?WorkflowAction $action, array $data): ?string
    {
        if (is_null($action) || !$this->getCandidates()->has($action->id)) {
            return exmtrans('workflow.message.status_changed');
        }
        if ($action->comment_type == WorkflowCommentType::REQUIRED && is_nullorempty(array_get($data, 'comment'))) {
            return exmtrans('workflow.bulk.message.comment_required', $action->action_name);
        }
        if ($this->needsNextWorkUsers($action) && count(static::nextWorkUsers($data)) == 0) {
            return exmtrans('workflow.bulk.message.next_work_users_required', $action->action_name);
        }
        return null;
    }

    /**
     * Run the action on each selected record that can take it.
     * Call validate() first.
     *
     * @param WorkflowAction $action
     * @param array<string, mixed> $data comment, next_work_users
     * @return array{executed: array<int, int>, skipped: array<int, string>} skipped: id => SKIP_*
     */
    public function execute(WorkflowAction $action, array $data): array
    {
        $comment = $action->comment_type == WorkflowCommentType::NOTUSE ? null : array_get($data, 'comment');
        $nextWorkUsers = static::nextWorkUsers($data);
        $candidate = $this->getCandidates()->get($action->id);
        $candidateIds = $candidate['ids'] ?? [];

        $executed = [];
        $skipped = [];
        foreach ($this->ids as $id) {
            $custom_value = $this->getCustomValues()->get($id);
            if (is_null($custom_value)) {
                $skipped[$id] = static::SKIP_NOT_FOUND;
                continue;
            }
            if (!in_array($id, $candidateIds, true)) {
                $skipped[$id] = static::SKIP_STATUS_CHANGED;
                continue;
            }

            try {
                // an earlier record of this run may have changed this one (e.g. a plugin), check again
                $custom_value = $custom_value->fresh() ?? $custom_value;
                if (!$custom_value->getWorkflowActions(true, false, true)->contains(function ($workflow_action) use ($action) {
                    return $workflow_action->id == $action->id;
                })) {
                    $skipped[$id] = static::SKIP_STATUS_CHANGED;
                    continue;
                }

                $requirement = $action->getNextWorkUsersRequirement($custom_value);
                if (!$requirement['select'] && !$requirement['found']) {
                    $skipped[$id] = static::SKIP_NEXT_USER_NOT_FOUND;
                    continue;
                }

                $action->executeAction($custom_value, [
                    'comment' => $comment,
                    // as on the record page: the users are chosen only when the next step asks for them
                    'next_work_users' => $requirement['select'] ? $nextWorkUsers : null,
                ]);
                $executed[] = $id;
            } catch (\Exception $ex) {
                \Log::error($ex);
                $skipped[$id] = static::SKIP_ERROR;
            }
        }

        return ['executed' => $executed, 'skipped' => $skipped];
    }

    /**
     * The chosen next work users, as executeAction() reads them ("user_1", "organization_2").
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    public static function nextWorkUsers(array $data): array
    {
        $values = array_get($data, 'next_work_users');
        return collect(is_array($values) ? $values : [])->filter(function ($value) {
            return is_string($value) && preg_match('/^(user|organization)_[0-9]+$/', $value) === 1;
        })->unique()->values()->all();
    }

    /**
     * The message of the result.
     *
     * @param array{executed: array<int, int>, skipped: array<int, string>} $result
     * @return string
     */
    public static function skippedText(array $result): string
    {
        if (count($result['skipped']) == 0) {
            return '';
        }
        $reasons = collect($result['skipped'])->countBy()->map(function ($count, $reason) {
            return exmtrans('workflow.bulk.skip_reasons.' . $reason) . ': ' . $count;
        })->implode(exmtrans('common.separate_word'));

        return exmtrans('workflow.bulk.message.skipped', count($result['skipped']), $reasons);
    }
}
