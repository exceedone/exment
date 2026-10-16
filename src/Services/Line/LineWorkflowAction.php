<?php

namespace Exceedone\Exment\Services\Line;

use Exceedone\Exment\Enums\WorkflowGetAuthorityType;
use Exceedone\Exment\Enums\WorkflowNextType;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowValue;
use Illuminate\Support\Collection;

class LineWorkflowAction
{
    /**
     * @return array<mixed>
     */
    public static function parsePostback(string $data): array
    {
        parse_str($data, $out);
        return $out;
    }

    /**
     * @param array<mixed> $data parsed postback query: table, id, action
     */
    public static function handle(array $data, ?string $lineUserId): string
    {
        if (empty($lineUserId)) {
            return exmtrans('line.user_unidentified');
        }
        $tableKey = array_get($data, 'table');
        $valueId  = array_get($data, 'id');
        $actionId = array_get($data, 'action');
        if (is_nullorempty($tableKey) || is_nullorempty($valueId) || is_nullorempty($actionId)) {
            return exmtrans('line.invalid_action_data');
        }

        $userId = LineActingUser::userId($lineUserId);
        if ($userId === null) {
            return exmtrans('line.account_not_linked');
        }

        $loginUser = LineActingUser::loginUser($userId);
        if (!$loginUser) {
            return exmtrans('line.login_not_activated');
        }
        return LineActingUser::runAs($loginUser, function () use ($tableKey, $valueId, $actionId) {
            $custom_table = CustomTable::getEloquent($tableKey);
            if (!$custom_table) {
                return exmtrans('line.table_not_found');
            }
            $custom_value = $custom_table->getValueModel($valueId);
            if (!$custom_value) {
                return exmtrans('line.record_not_found');
            }

            $wfAction = $custom_value->getWorkflowActions(true, false)->first(function ($a) use ($actionId) {
                return (string) $a->id === (string) $actionId;
            });
            if (!$wfAction) {
                return exmtrans('line.action_unavailable');
            }

            if ($wfAction->comment_type === \Exceedone\Exment\Enums\WorkflowCommentType::REQUIRED) {
                return exmtrans('line.action_need_comment');
            }

            $rejectReason = static::rejectReason($wfAction, $custom_value);
            if ($rejectReason !== null) {
                return $rejectReason;
            }

            try {
                $wfAction->executeAction($custom_value, []);
            } catch (\Exception $e) {
                \Log::warning('LINE workflow executeAction failed', ['error' => $e->getMessage()]);
                return exmtrans('line.action_error');
            }

            return exmtrans('line.action_done', $wfAction->action_name);
        });
    }

    public static function rejectReason(WorkflowAction $action, CustomValue $custom_value): ?string
    {
        // the same user tapping again must not count as another approver
        if (WorkflowValue::isAlreadyExecuted($action->id, $custom_value, \Exment::user()->base_user)) {
            return exmtrans('line.action_unavailable');
        }

        // one of several approvals: the status does not move, so the next step is not asked yet
        if ($action->isActionNext($custom_value) !== true) {
            return null;
        }

        $nextActions = static::getAskedNextActions($action, $action->getStatusToId($custom_value));
        if (is_null($nextActions)) {
            return null;
        }
        if (static::hasSelectAction($nextActions)) {
            return exmtrans('line.action_need_next_user');
        }

        $toActionAuthorities = collect();
        foreach ($nextActions->filter(function ($nextAction) {
            return !boolval($nextAction->ignore_work);
        }) as $nextAction) {
            $toActionAuthorities = \Exment::uniqueCustomValues(
                $toActionAuthorities,
                $nextAction->getAuthorityTargets($custom_value, WorkflowGetAuthorityType::NEXT_USER_ON_EXECUTING_MODAL)
            );
        }
        if (is_nullorempty($toActionAuthorities)) {
            return exmtrans('workflow.message.nextuser_not_found');
        }

        return null;
    }

    public static function nextStepNeedsSelection(WorkflowAction $action, CustomValue $custom_value): bool
    {
        // only a single-approval action surely advances; a multi-approval one is checked when tapped
        if ($action->flow_next_type != WorkflowNextType::SOME || $action->getOption('flow_next_count', 1) != 1) {
            return false;
        }

        $condition = $action->getMatchedCondtionHeader($custom_value);
        if (is_null($condition)) {
            return false;
        }

        $nextActions = static::getAskedNextActions($action, $condition->status_to);
        return !is_null($nextActions) && static::hasSelectAction($nextActions);
    }

    /**
     * @param string|null $statusTo
     * @return Collection<int, WorkflowAction>|null
     */
    protected static function getAskedNextActions(WorkflowAction $action, $statusTo): ?Collection
    {
        if (WorkflowStatus::getWorkflowStatusCompleted($statusTo)) {
            return null;
        }

        $nextActions = WorkflowStatus::getActionsByFrom($statusTo, $action->workflow);
        $hasNormalAction = $nextActions->contains(function ($nextAction) {
            return !boolval($nextAction->ignore_work);
        });
        return $hasNormalAction ? $nextActions : null;
    }

    /**
     * @param Collection<int, WorkflowAction> $nextActions
     */
    protected static function hasSelectAction(Collection $nextActions): bool
    {
        return $nextActions->contains(function ($nextAction) {
            return $nextAction->getOption('work_target_type') == WorkflowWorkTargetType::ACTION_SELECT;
        });
    }
}
