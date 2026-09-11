<?php

namespace Exceedone\Exment\Services\Notify;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\WorkflowGetAuthorityType;
use Exceedone\Exment\Model\CustomRelation;
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
     * Column limits of notify_navbars (notify_subject varchar(200), notify_body varchar(2000)).
     * The insert below runs AFTER the workflow status has been committed, so an overflow is
     * either a 500 on an action that already succeeded (MySQL in strict mode) or silent data
     * loss (non-strict). Neither is left to the database.
     */
    private const MAX_SUBJECT_LENGTH = 200;
    private const MAX_BODY_LENGTH = 2000;

    /**
     * How much of the record label the sentence may carry. The label is the only unbounded
     * part of the message - any column can be a label column, including a textarea - so it is
     * cut here, which keeps the sentence readable instead of stopping it mid-word.
     */
    private const MAX_LABEL_LENGTH = 300;

    /**
     * Collect the user ids of the OTHER members of the organizations that are
     * authorities of the action being executed (excluding the executer and
     * excluding individually-assigned users).
     *
     * IMPORTANT: must be called BEFORE the workflow value is forwarded, because
     * for ACTION_SELECT actions the assignees are read from the CURRENT
     * (pre-forward) workflow value.
     *
     * That placement is also why this is guarded: it runs before the transaction that
     * executes the action, so anything thrown here would refuse a workflow action that has
     * nothing wrong with it. Failing to work out who to notify means notifying nobody.
     *
     * @param WorkflowAction $action action being executed
     * @param CustomValue $custom_value
     * @return array<int|string> user ids
     */
    public static function getOtherOrgMemberIds(WorkflowAction $action, CustomValue $custom_value): array
    {
        try {
            return self::collectOtherOrgMemberIds($action, $custom_value);
        } catch (\Throwable $ex) {
            \Log::error($ex);

            return [];
        }
    }

    /**
     * The member lookup itself. See getOtherOrgMemberIds() for why it is wrapped.
     *
     * @param WorkflowAction $action
     * @param CustomValue $custom_value
     * @return array<int|string> user ids
     */
    private static function collectOtherOrgMemberIds(WorkflowAction $action, CustomValue $custom_value): array
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

        // The "users" relation of an organization is a plain belongsToMany over this pivot
        // table (OrganizationTrait::users), so every organization can be expanded with ONE
        // query instead of one query per organization - and only ids are read, no user model
        // is built for a list that is only used as "who to notify".
        $pivotTableName = CustomRelation::getRelationNameByTables(
            SystemTableName::ORGANIZATION,
            SystemTableName::USER
        );
        if (is_nullorempty($pivotTableName)) {
            return [];
        }

        // The join on the user table replaces the soft-delete scope belongsToMany would have
        // applied: somebody who left the company must not be notified.
        // What is deliberately NOT applied is CustomValueModelScope, which filters the user
        // table by the EXECUTER's "filter_multi_user" visibility - with it the same
        // organization would notify a different set of colleagues depending on who pressed
        // the button.
        $userTableName = getDBTableName(SystemTableName::USER);

        $memberIds = \DB::table($pivotTableName)
            ->join($userTableName, $userTableName . '.id', '=', $pivotTableName . '.child_id')
            ->whereIn($pivotTableName . '.parent_id', $orgs->pluck('id')->all())
            ->whereNull($userTableName . '.deleted_at')
            ->distinct()
            ->pluck($pivotTableName . '.child_id');

        // the executer is dropped here, not in SQL: getUserId() can be null and
        // "child_id <> null" would silently match nothing
        return $memberIds->reject(function ($id) use ($executerId) {
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

        // this notification is not configurable per workflow (unlike Notify records), so give
        // the administrator a way to turn it off - the record label ends up on other people's navbar.
        if (!boolval(config('exment.same_org_workflow_notify', true))) {
            return;
        }

        // WorkflowAction::executeAction() closes its transaction before calling this, so the
        // status change is already committed. Everything below is a courtesy message about it
        // and must not be able to answer 500 for an action that succeeded:
        //   - exmtrans() ends in vsprintf(), which throws ValueError in PHP 8 when the format
        //     string wants more %s than it is given ("The arguments array must contain N
        //     items, M given"). The shipped translations are pinned by test, but Laravel reads
        //     resources/lang/vendor/exment/{locale}/exment.php over them, so the format string
        //     is ultimately editable on the installation.
        //   - the insert can still fail on its own (deadlock, packet size, a user row deleted
        //     between the lookup and the write).
        // Not being told about a colleague's approval is a small loss; being unable to approve
        // is not.
        try {
            // reload to get the status AFTER the action has been executed
            $custom_value->load(['workflow_value']);

            $executerId = \Exment::getUserId();
            $custom_table = $custom_value->custom_table;

            $subject = self::fit(exmtrans('workflow.same_org_notify.subject'), self::MAX_SUBJECT_LENGTH);
            $body = self::fit(exmtrans(
                'workflow.same_org_notify.body',
                // getUserName() takes a string id (or the user record) while getUserId() is
                // int|string|null. Casting keeps a null executer a null name: the helper's
                // first act is is_nullorempty(), and "" fails that the same way null does.
                getUserName((string)$executerId),
                $custom_table->table_view_name,
                // cut the LABEL, not the finished sentence: this is the only unbounded part,
                // and trimming the end of the sentence would drop the status it reports
                self::fit($custom_value->getLabel(), self::MAX_LABEL_LENGTH),
                $custom_value->workflow_status_name
            ), self::MAX_BODY_LENGTH);

            // One INSERT for the whole organization instead of one per member: a 200-person
            // organization used to fire 200 INSERTs inside the request that executes the action.
            // insert() skips the model events, so the columns ModelBase::setUser() and the
            // timestamps would have filled are written here by hand (read_flg has a DB default).
            $now = \Carbon\Carbon::now();
            $rows = [];

            foreach ($userIds as $userId) {
                $rows[] = [
                    // notify_navbars.notify_id is INT UNSIGNED NOT NULL. This notification has no
                    // parent Notify record, so use 0 (same placeholder as ApiController). A negative
                    // value throws on a strict-mode MySQL - AFTER the workflow status was committed.
                    'notify_id'       => 0,
                    'parent_id'       => $custom_value->id,
                    'parent_type'     => $custom_table->table_name,
                    'notify_subject'  => $subject,
                    'notify_body'     => $body,
                    'target_user_id'  => $userId,
                    'trigger_user_id' => $executerId,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                    'created_user_id' => $executerId,
                    'updated_user_id' => $executerId,
                ];
            }

            // chunked so a very large organization cannot build a statement over max_allowed_packet
            foreach (array_chunk($rows, 500) as $chunk) {
                NotifyNavbar::insert($chunk);
            }
        } catch (\Throwable $ex) {
            \Log::error($ex);
        }
    }

    /**
     * Cut $value down to $limit CHARACTERS, marking that something was dropped.
     *
     * Characters, not bytes: MySQL counts a varchar length in characters, so mb_substr() is
     * what matches the column. substr() would cut a multi-byte character in half and could
     * still leave the value over the limit in bytes.
     *
     * @param mixed $value
     * @param int $limit
     * @return string
     */
    private static function fit($value, int $limit): string
    {
        $value = (string)$value;

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        // the marker is part of the budget, not added on top of it: a method whose whole job
        // is "never longer than the column" must not return $limit + 3 characters
        $marker = '...';
        $keep = $limit - mb_strlen($marker);

        return $keep > 0 ? mb_substr($value, 0, $keep) . $marker : mb_substr($value, 0, $limit);
    }
}
