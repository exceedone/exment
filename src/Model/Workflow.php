<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Enums\NotifyTrigger;

/**
 * @phpstan-consistent-constructor
 * @property mixed $workflow_type
 * @property mixed $workflow_tables
 * @property mixed $workflow_statuses
 * @property mixed $workflow_view_name
 * @property mixed $workflow_actions
 * @property mixed $start_status_name
 * @property mixed $setting_completed_flg
 * @property mixed $notifies
 * @property mixed $options
 * @property mixed $created_user_id
 * @property mixed $updated_user_id
 * @property mixed $created_at
 * @property mixed $updated_at
 */
class Workflow extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonOptionTrait;
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\TemplateTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $appends = ['workflow_edit_flg'];
    protected $casts = ['options' => 'json'];


    // @phpstan-ignore-next-line
    public static $templateItems = [
        // workflow_edit_flg is an appended accessor, not a stored column. Without
        // excepting it the importer writes it into the options json as null.
        'excepts' => ['id', 'workflow_edit_flg'],
        // Workflow names are not unique, so keying the import by name merged two
        // workflows sharing one. importReplaceJson() falls back to the name for
        // a template written before the suuid was exported.
        'uniqueKeys' => ['suuid'],
        'langs' => [
            'keys' => ['workflow_view_name'],
            'values' => ['workflow_view_name'],
        ],
        'enums' => [
            'workflow_type' => WorkflowType::class,
        ],
        'children' => [
            'workflow_tables' => WorkflowTable::class,
            'workflow_statuses' => WorkflowStatus::class,
            'workflow_actions' => WorkflowAction::class,
        ],
    ];


    // @phpstan-ignore-next-line
    public function workflow_tables()
    {
        return $this->hasMany(WorkflowTable::class, 'workflow_id');
    }

    /**
     * get workflow statuses
     */

    // @phpstan-ignore-next-line
    public function workflow_statuses()
    {
        return $this->hasMany(WorkflowStatus::class, 'workflow_id');
    }

    /**
     * get workflow actions
     */

    // @phpstan-ignore-next-line
    public function workflow_actions()
    {
        return $this->hasMany(WorkflowAction::class, 'workflow_id');
    }


    // @phpstan-ignore-next-line
    public function notifies()
    {
        return $this->hasMany(Notify::class, 'target_id')
            ->where('notify_trigger', NotifyTrigger::WORKFLOW)
            ->where('active_flg', 1);
    }

    protected static function boot()
    {
        parent::boot();

        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
        });
    }

    /**
     * Delete children items
     */

    // @phpstan-ignore-next-line
    public function deletingChildren()
    {
        $keys = ['workflow_statuses', 'workflow_tables', 'notifies'];
        $this->load($keys);
        foreach ($keys as $key) {
            foreach ($this->{$key} as $item) {
                if (!method_exists($item, 'deletingChildren')) {
                    continue;
                }

                // @phpstan-ignore-next-line
                $item->deletingChildren();
            }

            $this->{$key}()->delete();
        }

        foreach ($this->workflow_actions()->withTrashed()->get() as $item) {
            $item->deletingChildren();
        }

        $this->workflow_actions()->forceDelete();
    }


    // @phpstan-ignore-next-line
    public function getWorkflowEditFlgAttribute()
    {
        return $this->getOption('workflow_edit_flg');
    }

    // @phpstan-ignore-next-line
    public function setWorkflowEditFlgAttribute($workflow_edit_flg)
    {
        $this->setOption('workflow_edit_flg', $workflow_edit_flg);
        return $this;
    }


    /**
     * get workflow statuses using cache
     */

    // @phpstan-ignore-next-line
    public function getWorkflowStatusesCacheAttribute()
    {
        return $this->hasManyCache(WorkflowStatus::class, 'workflow_id');
    }

    /**
     * get workflow actions
     */

    // @phpstan-ignore-next-line
    public function getWorkflowActionsCacheAttribute()
    {
        return $this->hasManyCache(WorkflowAction::class, 'workflow_id');
    }

    /**
     * get eloquent using Cache.
     * now only support only id.
     */

    // @phpstan-ignore-next-line
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentCache($id, $withs);
    }

    /**
     * Get status string
     *
     * @return string
     */
    public function getStatusesString()
    {
        return $this->getStatusOptions()->implode(exmtrans('common.separate_word'));
    }

    /**
     * Get status options. contains start and end.
     *
     * @return \Illuminate\Support\Collection|null
     */

    // @phpstan-ignore-next-line
    public function getStatusOptions($onlyStart = false)
    {
        $statuses = collect();
        if (!$onlyStart) {
            $statuses = $this->workflow_statuses_cache->pluck('status_name', 'id');
        }

        $statuses->prepend($this->start_status_name, Define::WORKFLOW_START_KEYNAME);

        return $statuses;
    }

    /**
     * Get action options.
     *
     * @return \Illuminate\Support\Collection
     */

    // @phpstan-ignore-next-line
    public function getActionOptions()
    {
        $actions = $this->workflow_actions_cache->pluck('action_name', 'id');

        return $actions;
    }

    /**
     * Get workflow filtering active using custom table
     *
     * @param CustomTable $custom_table
     * @return Workflow|null
     */
    public static function getWorkflowByTable($custom_table)
    {
        // if not has workflow, return false
        $hasWorkflow = System::cache(Define::SYSTEM_KEY_SESSION_HAS_WORLFLOW, function () {
            return WorkflowTable::count() > 0;
        });
        if (!$hasWorkflow) {
            return null;
        }

        $custom_table = CustomTable::getEloquent($custom_table);
        if (!$custom_table) {
            return null;
        }

        $today = \Carbon\Carbon::today();

        $workflowTable = WorkflowTable::allRecordsCache(function ($record) use ($custom_table, $today) {
            if ($custom_table->id != $record->custom_table_id) {
                return false;
            }

            if (!boolval($record->active_flg)) {
                return false;
            }

            if (isset($record->active_start_date) && $today->lt(new \Carbon\Carbon($record->active_start_date))) {
                return false;
            }

            if (isset($record->active_end_date) && $today->gt(new \Carbon\Carbon($record->active_end_date))) {
                return false;
            }

            $workflow = Workflow::getEloquent($record->workflow_id);
            if (!$workflow || !boolval($workflow->setting_completed_flg)) {
                return false;
            }

            return true;
        }, false)->first();

        if (!isset($workflowTable)) {
            return null;
        }

        return Workflow::getEloquent($workflowTable->workflow_id);
    }

    /**
     * Get custom table. Only workflow type is table
     * If workflow is common, return null
     *
     * @return CustomTable|null
     */
    public function getDesignatedTable()
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_WORKFLOW_DESIGNATED_TABLE, $this->id);
        return System::requestSession($key, function () {
            if ($this->workflow_type == WorkflowType::COMMON) {
                return null;
            }

            $workflowTables = $this->workflow_tables;
            if (is_nullorempty($workflowTables)) {
                return null;
            }

            return $workflowTables->first()->custom_table;
        });
    }

    /**
     * Check can change activate this wokflow
     *
     * @return boolean
     */
    public function canActivate()
    {
        if (boolval($this->setting_completed_flg)) {
            return false;
        }

        // check statuses
        if (count($this->workflow_statuses) == 0) {
            return false;
        }

        // check actions
        if (count($this->workflow_actions) == 0) {
            return false;
        }

        // check action use status is exists
        $status_list = $this->workflow_statuses->pluck('id');
        $status_list->push(Define::WORKFLOW_START_KEYNAME);

        foreach ($this->workflow_actions as $workflow_action) {
            if (!$status_list->contains($workflow_action->status_from)) {
                return false;
            }
            foreach ($workflow_action->workflow_condition_headers as $workflow_condition_header) {
                if (!$status_list->contains($workflow_condition_header->status_to)) {
                    return false;
                }
            }
        }

        return true;
    }


    // @phpstan-ignore-next-line
    public static function hasSettingCompleted()
    {
        return static::allRecords(function ($workflow) {
            return boolval($workflow->setting_completed_flg);
        })->count() > 0;
    }

    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function getDisabledDeleteAttribute()
    {
        return boolval($this->setting_completed_flg);
    }

    /**
     * Target Custom Table
     *
     * @return boolean|null
     */
    public function getTargetTableAttribute()
    {
        if ($this->workflow_type == WorkflowType::TABLE) {
            $workflow_tables = $this->workflow_tables;
            if (!is_nullorempty($workflow_tables)) {
                return $workflow_tables->first()->custom_table;
            }
        }
        return null;
    }

    /**
     * append workflow status
     *
     * @return Workflow
     */
    public function appendStartStatus(): Workflow
    {
        $this->workflow_statuses->prepend(WorkflowStatus::getWorkflowStartStatus($this));

        return $this;
    }

    /**
     * Template import.
     *
     * - A template written before workflows carried their suuid still matches
     *   an existing workflow by name, as it always did.
     * - When the template cannot be reproduced exactly (see
     *   templateImportProblem), every table assignment is brought in inactive,
     *   so the workflow does not route records until an administrator has
     *   checked it and activated it through the beginning settings screen.
     * - A template written before the layout was re-keyed by status name (see
     *   exportTemplateDesignerLayout) still keys the positions by the source
     *   system's status ids. Those ids mean nothing here and may even match
     *   unrelated statuses, so only the start node position is kept; the
     *   designer lays out the rest itself.
     *
     * @param array<mixed> $json
     * @param array<string, mixed> $options
     * @return void
     */
    public static function importReplaceJson(&$json, $options = [])
    {
        if (is_nullorempty(array_get($json, 'suuid'))) {
            $json['suuid'] = static::where('workflow_view_name', array_get($json, 'workflow_view_name'))->value('suuid');
        }

        $problem = static::templateImportProblem($json);
        if (isset($problem)) {
            \Log::warning('Exment template import: workflow "' . strval(array_get($json, 'workflow_view_name')) . '" imported inactive, ' . $problem . '.');
            foreach (array_keys((array)array_get($json, 'workflow_tables', [])) as $index) {
                $json['workflow_tables'][$index]['active_flg'] = 0;
            }
        }

        $layout = static::decodeDesignerLayout(array_get($json, 'options.designer_layout'));
        if (is_null($layout) || array_key_exists('pos_by_status_name', $layout)) {
            return;
        }

        $start = static::designerPoint(array_get((array)array_get($layout, 'pos', []), Define::WORKFLOW_START_KEYNAME));
        $layout['pos'] = (object)(isset($start) ? [Define::WORKFLOW_START_KEYNAME => $start] : []);
        array_set($json, 'options.designer_layout', json_encode($layout, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Why the template cannot be reproduced exactly, or null when it can.
     *
     * - Statuses, actions and branches are found again by name on import, so
     *   two statuses with one name, two actions with one name leaving the same
     *   status, or two branches of one action leading to the same status are
     *   merged into one record.
     * - A branch condition whose column is missing here is dropped (see
     *   Condition::importReplaceJson). An AND branch left without conditions
     *   matches every record, which can skip an approval step.
     *
     * @param array<mixed> $json
     * @return string|null
     */
    protected static function templateImportProblem(array $json): ?string
    {
        $statusNames = collect((array)array_get($json, 'workflow_statuses', []))->pluck('status_name')->map(fn ($name) => strval($name));
        if ($statusNames->count() !== $statusNames->unique()->count()) {
            return 'two statuses share a name';
        }

        $actionKeys = [];
        foreach ((array)array_get($json, 'workflow_actions', []) as $action) {
            $from = array_get($action, 'status_from_name', array_get($action, 'status_from'));
            $actionKeys[] = json_encode([strval($from), strval(array_get($action, 'action_name'))]);

            $headers = collect((array)array_get($action, 'workflow_condition_headers', []));
            $statusTo = $headers->pluck('status_to')->map(fn ($to) => strval($to));
            if ($statusTo->count() !== $statusTo->unique()->count()) {
                return 'two branches of one action lead to the same status';
            }

            foreach ($headers as $header) {
                foreach ((array)array_get($header, 'workflow_conditions', []) as $condition) {
                    if (Condition::isDroppedOnTemplateImport((array)$condition)) {
                        return 'a branch condition refers to a column that does not exist on this system';
                    }
                }
            }
        }
        if (count($actionKeys) !== count(array_unique($actionKeys))) {
            return 'two actions with one name leave the same status';
        }

        return null;
    }

    /**
     * Template export: options.designer_layout keys node positions by
     * workflow_status id, which is assigned per install. Re-key the positions
     * by status_name (the unique key the statuses are imported with) so the
     * layout drawn in the designer survives the import.
     *
     * @param array<string, mixed> $item result of getTemplateExportItems()
     * @return array<string, mixed>
     */
    public function exportTemplateDesignerLayout(array $item): array
    {
        $layout = static::decodeDesignerLayout(array_get($item, 'options.designer_layout'));
        if (is_null($layout)) {
            return $item;
        }

        $names = $this->workflow_statuses->pluck('status_name', 'id')->all();
        $pos = [];
        $byName = [];
        foreach ((array)array_get($layout, 'pos', []) as $key => $value) {
            $point = static::designerPoint($value);
            if (is_null($point)) {
                continue;
            }
            $key = strval($key);
            if ($key === Define::WORKFLOW_START_KEYNAME) {
                $pos[$key] = $point;
            } elseif (array_key_exists($key, $names)) {
                $byName[strval($names[$key])] = $point;
            }
        }

        $layout['pos'] = (object)$pos;
        $layout['pos_by_status_name'] = (object)$byName;
        array_set($item, 'options.designer_layout', json_encode($layout, JSON_UNESCAPED_UNICODE));

        return $item;
    }

    /**
     * Reverse of exportTemplateDesignerLayout(). Call it after the statuses
     * have been imported, so their new ids exist.
     *
     * @return void
     */
    public function importTemplateDesignerLayout(): void
    {
        $layout = static::decodeDesignerLayout($this->getOption('designer_layout'));
        if (is_null($layout) || !array_key_exists('pos_by_status_name', $layout)) {
            return;
        }

        $ids = WorkflowStatus::where('workflow_id', $this->id)->pluck('id', 'status_name')->all();
        $pos = [];
        $start = static::designerPoint(array_get((array)array_get($layout, 'pos', []), Define::WORKFLOW_START_KEYNAME));
        if (isset($start)) {
            $pos[Define::WORKFLOW_START_KEYNAME] = $start;
        }
        foreach ((array)$layout['pos_by_status_name'] as $name => $value) {
            $point = static::designerPoint($value);
            $name = strval($name);
            if (isset($point) && array_key_exists($name, $ids)) {
                $pos[strval($ids[$name])] = $point;
            }
        }

        unset($layout['pos_by_status_name']);
        $layout['pos'] = (object)$pos;
        $this->setOption('designer_layout', json_encode($layout, JSON_UNESCAPED_UNICODE));
        $this->save();
    }

    /**
     * @param mixed $raw stored designer_layout (json string or array)
     * @return array<mixed>|null
     */
    protected static function decodeDesignerLayout($raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) ? $raw : null;
    }

    /**
     * @param mixed $value
     * @return array{x: int|float, y: int|float}|null
     */
    protected static function designerPoint($value): ?array
    {
        $x = array_get((array)$value, 'x');
        $y = array_get((array)$value, 'y');
        if (!is_numeric($x) || !is_numeric($y)) {
            return null;
        }
        return ['x' => $x + 0, 'y' => $y + 0];
    }
}
