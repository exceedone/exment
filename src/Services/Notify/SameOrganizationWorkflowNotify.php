<?php

namespace Exceedone\Exment\Services\Notify;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\WorkflowGetAuthorityType;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\WorkflowAction;

/**
 * Feature 1 (part B):
 * When a workflow task is assigned to an organization and ONE member of that
 * organization changes the status, the OTHER members of the same organization
 * also have to be notified (shown on the navbar / task list screen), so the
 * whole team knows the shared task has been handled.
 *
 * This is intentionally limited to authorities of type "organization"
 * (fixed authorities, column/system authorities resolving to an organization,
 * and dynamically selected next-work organizations). Tasks assigned directly
 * to individual users are not in scope here, because there is no "colleague"
 * relationship to notify.
 */
class SameOrganizationWorkflowNotify
{
    /**
     * Collect the user ids of the OTHER members of the organizations that are
     * authorities of the action being executed (excluding the executer and
     * excluding individually-assigned users).
     *
     * IMPORTANT: must be called BEFORE the workflow value is forwarded, because
     * for ACTION_SELECT actions the assignees are read from the CURRENT
     * (pre-forward) workflow value.
     *
     * @param WorkflowAction $action action being executed
     * @param CustomValue $custom_value
     * @return array<int|string> user ids
     */
    public static function getOtherOrgMemberIds(WorkflowAction $action, CustomValue $custom_value): array
    {
        if (!System::organization_available()) {
            return [];
        }

        // current assignees of the action (users + organizations; orgs are NOT expanded to users here)
        $targets = $action->getAuthorityTargets($custom_value, WorkflowGetAuthorityType::CURRENT_WORK_USER);

        // keep only organizations
        $orgs = collect($targets)->filter(function ($target) {
            return isset($target->custom_table)
                && isMatchString($target->custom_table->table_name, SystemTableName::ORGANIZATION);
        });
        if ($orgs->isEmpty()) {
            return [];
        }

        $executerId = \Exment::getUserId();

        // expand organizations to members, drop the executer and de-duplicate
        $memberIds = collect();
        foreach ($orgs as $org) {
            foreach ($org->users as $user) {
                $memberIds->push($user->id);
            }
        }

        return $memberIds->unique()
            ->reject(function ($id) use ($executerId) {
                return isMatchString($id, $executerId);
            })
            ->values()
            ->all();
    }

    /**
     * Create navbar notifications for the other organization members.
     *
     * @param WorkflowAction $action
     * @param CustomValue $custom_value
     * @param array<int|string> $userIds target user ids (captured before forward by getOtherOrgMemberIds)
     * @return void
     */
    public static function notify(WorkflowAction $action, CustomValue $custom_value, array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        // reload to get the status AFTER the action has been executed
        $custom_value->load(['workflow_value']);

        $executerId = \Exment::getUserId();
        $custom_table = $custom_value->custom_table;

        $subject = exmtrans('workflow.same_org_notify.subject');
        $body = exmtrans(
            'workflow.same_org_notify.body',
            getUserName($executerId),
            $custom_table->table_view_name,
            $custom_value->getLabel(),
            $custom_value->workflow_status_name
        );

        foreach ($userIds as $userId) {
            $notify_navbar = new NotifyNavbar();
            $notify_navbar->notify_id = -1;
            $notify_navbar->parent_id = $custom_value->id;
            $notify_navbar->parent_type = $custom_table->table_name;
            $notify_navbar->notify_subject = $subject;
            $notify_navbar->notify_body = $body;
            $notify_navbar->target_user_id = $userId;
            $notify_navbar->trigger_user_id = $executerId;
            $notify_navbar->save();
        }
    }
}
