<?php

namespace Exceedone\Exment\Services\TemplateImportExport;

use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Workflow;

/**
 * Notifications in a template ("notifies"). A notify holds ids of this site
 * everywhere: its table or workflow, the date column of a schedule, the
 * columns it sends to, its view and its mail template. In the template they
 * are names, and an import turns them back into the ids of that site:
 *
 * - target_name: the table_name (notify_trigger of a table) or the
 *   workflow_view_name (notify_trigger "workflow")
 * - trigger_settings.notify_target_table_name / notify_target_column_name
 * - action_settings.*.notify_action_target: a column as "column:<table>.<column>"
 * - custom_view_suuid, mail_key_name
 *
 * Left out, because they belong to one site: fixed users and organizations
 * (target_users, target_organizations), the users to mention, and the
 * webhook URL of Slack / Microsoft Teams (a secret). A notify of a relation
 * column (view_pivot_*) and the notifies of public forms are not exported.
 */
class NotifyTemplate
{
    public const COLUMN_PREFIX = 'column:';

    /** the keys of an action setting that travel in a template */
    protected const ACTION_KEYS = ['notify_action', 'notify_action_target', 'target_emails', 'webhook_name', 'webhook_icon', 'mention_here'];

    /**
     * @param array<string> $tableNames the tables of the template (empty: all)
     * @param array<string> $workflowNames the workflows of the template
     * @return array<int, array<string, mixed>>
     */
    public static function export(array $tableNames, array $workflowNames, bool $isLang = false): array
    {
        $result = [];
        foreach (Notify::query()->orderBy('id')->get() as $notify) {
            $item = static::exportOne($notify, $tableNames, $workflowNames, $isLang);
            if (!is_null($item)) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * @param array<string> $tableNames
     * @param array<string> $workflowNames
     * @return array<string, mixed>|null
     */
    protected static function exportOne(Notify $notify, array $tableNames, array $workflowNames, bool $isLang): ?array
    {
        $trigger = strval($notify->notify_trigger);
        if ($trigger === NotifyTrigger::WORKFLOW) {
            $workflow = Workflow::where('id', $notify->target_id)->first();
            if (!$workflow || !in_array($workflow->workflow_view_name, $workflowNames, true)) {
                return null;
            }
            $targetName = $workflow->workflow_view_name;
        } elseif (in_array($trigger, NotifyTrigger::CUSTOM_TABLES())) {
            $table = CustomTable::getEloquent($notify->target_id);
            if (!$table || (count($tableNames) > 0 && !in_array($table->table_name, $tableNames, true))) {
                return null;
            }
            $targetName = $table->table_name;
        } else {
            return null;
        }

        // the stored settings: the accessor adds notify_target_date, made of ids
        $triggerSettings = $notify->getRawOriginal('trigger_settings');
        $triggerSettings = is_string($triggerSettings) ? (json_decode($triggerSettings, true) ?? []) : (array) $triggerSettings;
        if (!empty($triggerSettings['view_pivot_column_id']) || !empty($triggerSettings['view_pivot_table_id'])) {
            return null;
        }

        if ($isLang) {
            $lang = ['suuid' => $notify->suuid, 'notify_view_name' => $notify->notify_view_name];
            if (isset($triggerSettings['notify_button_name'])) {
                $lang['trigger_settings'] = ['notify_button_name' => $triggerSettings['notify_button_name']];
            }
            return $lang;
        }

        if (isset($triggerSettings['notify_target_column'])) {
            $column = CustomColumn::getEloquent($triggerSettings['notify_target_column']);
            if (!$column) {
                return null;
            }
            $triggerSettings['notify_target_column_name'] = $column->column_name;
            $triggerSettings['notify_target_table_name'] = $column->custom_table_cache->table_name;
        }
        unset($triggerSettings['notify_target_column'], $triggerSettings['notify_target_table_id'], $triggerSettings['notify_target_date']);

        $actions = [];
        foreach ((array) ($notify->action_settings ?? []) as $setting) {
            $setting = (array) $setting;
            $targets = [];
            foreach (stringToArray(array_get($setting, 'notify_action_target')) as $target) {
                $target = strval($target);
                if (in_array($target, [NotifyActionTarget::FIXED_USER, NotifyActionTarget::FIXED_ORGANIZATION], true)
                    || strpos($target, 'view_pivot_column_id') !== false) {
                    continue;
                }
                if (in_array($target, NotifyActionTarget::arrays(), true)) {
                    $targets[] = $target;
                    continue;
                }
                $column = CustomColumn::getEloquent($target);
                if ($column) {
                    $targets[] = static::COLUMN_PREFIX . $column->custom_table_cache->table_name . '.' . $column->column_name;
                }
            }
            $setting['notify_action_target'] = $targets;
            $actions[] = array_intersect_key($setting, array_flip(static::ACTION_KEYS));
        }

        $item = [
            'suuid' => $notify->suuid,
            'notify_name' => $notify->notify_name,
            'notify_view_name' => $notify->notify_view_name,
            'active_flg' => boolval($notify->active_flg ?? true) ? '1' : '0',
            'notify_trigger' => $trigger,
            'target_name' => $targetName,
            'trigger_settings' => $triggerSettings,
            'action_settings' => $actions,
        ];
        if (!empty($notify->custom_view_id) && ($view = CustomView::where('id', $notify->custom_view_id)->first())) {
            $item['custom_view_suuid'] = $view->suuid;
        }
        if (!empty($notify->mail_template_id)) {
            $mail = getModelName(SystemTableName::MAIL_TEMPLATE)::find($notify->mail_template_id);
            if ($mail) {
                $item['mail_key_name'] = $mail->getValue('mail_key_name');
            }
        }
        return $item;
    }

    /**
     * Make or update (by suuid) the notifies of a template. A notify whose
     * table, workflow or date column is not on this site is skipped; a
     * column it sends to that is not there is left out.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function import(array $items, bool $isUpdate = false): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (static::importOne($item, $isUpdate)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $item
     */
    protected static function importOne(array $item, bool $isUpdate): bool
    {
        $trigger = strval(array_get($item, 'notify_trigger'));
        $targetName = strval(array_get($item, 'target_name'));
        if ($trigger === NotifyTrigger::WORKFLOW) {
            $workflow = Workflow::where('workflow_view_name', $targetName)->first();
            if (!$workflow) {
                return false;
            }
            $targetId = $workflow->id;
        } elseif (in_array($trigger, NotifyTrigger::CUSTOM_TABLES())) {
            $table = CustomTable::getEloquent($targetName);
            if (!$table) {
                return false;
            }
            $targetId = $table->id;
        } else {
            return false;
        }

        $triggerSettings = (array) array_get($item, 'trigger_settings', []);
        $columnName = array_get($triggerSettings, 'notify_target_column_name');
        if (!is_nullorempty($columnName)) {
            $column = CustomColumn::getEloquent($columnName, array_get($triggerSettings, 'notify_target_table_name'));
            if (!$column) {
                return false;
            }
            $triggerSettings['notify_target_column'] = $column->id;
            $triggerSettings['notify_target_table_id'] = $column->custom_table_id;
        }
        unset($triggerSettings['notify_target_column_name'], $triggerSettings['notify_target_table_name']);

        $actions = [];
        foreach ((array) array_get($item, 'action_settings', []) as $setting) {
            $setting = array_intersect_key((array) $setting, array_flip(static::ACTION_KEYS));
            $targets = [];
            foreach ((array) array_get($setting, 'notify_action_target', []) as $target) {
                $target = strval($target);
                if (strpos($target, static::COLUMN_PREFIX) === 0) {
                    [$tableName, $name] = array_pad(explode('.', substr($target, strlen(static::COLUMN_PREFIX)), 2), 2, null);
                    $column = CustomColumn::getEloquent($name, $tableName);
                    if ($column) {
                        $targets[] = strval($column->id);
                    }
                    continue;
                }
                if (in_array($target, NotifyActionTarget::arrays(), true)) {
                    $targets[] = $target;
                }
            }
            $setting['notify_action_target'] = $targets;
            $actions[] = $setting;
        }

        $suuid = array_get($item, 'suuid');
        $notify = $suuid ? Notify::where('suuid', $suuid)->first() : null;
        if ($notify && !$isUpdate) {
            return false;
        }
        $notify = $notify ?? new Notify();
        if ($suuid) {
            $notify->suuid = $suuid;
        }
        $notify->notify_name = array_get($item, 'notify_name');
        $notify->notify_view_name = array_get($item, 'notify_view_name');
        $notify->active_flg = boolval(array_get($item, 'active_flg', '1'));
        $notify->notify_trigger = $trigger;
        $notify->target_id = $targetId;
        $notify->trigger_settings = $triggerSettings;
        $notify->action_settings = $actions;

        $viewSuuid = array_get($item, 'custom_view_suuid');
        $notify->custom_view_id = $viewSuuid ? optional(CustomView::where('suuid', $viewSuuid)->first())->id : null;

        $mailKey = array_get($item, 'mail_key_name');
        $notify->mail_template_id = $mailKey
            ? optional(getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', $mailKey)->first())->id
            : null;

        $notify->save();
        return true;
    }
}
