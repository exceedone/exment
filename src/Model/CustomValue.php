<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewColumnFilterOption;
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Enums\ValueType;

abstract class CustomValue extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use \Exceedone\Exment\Revisionable\RevisionableTrait;

    protected $casts = ['value' => 'json'];
    protected $appends = ['label'];
    protected $hidden = ['laravel_admin_escape'];
    protected $keepRevisionOf = ['value'];
    
    /**
     * remove_file_columns.
     * default flow, if file column is empty, set original value.
     */
    protected $remove_file_columns = [];

    /**
     * saved notify.
     * if false, don't notify
     */
    protected $saved_notify = true;
    
    /**
     * already_updated.
     * if true, not call saved event again.
     */
    protected $already_updated = false;
    
    
    /**
     * Create a new Eloquent model instance.
     *
     * @param  array  $attributes
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        // set parent_id for org
        if ($this->custom_table->table_name == SystemTableName::ORGANIZATION) {
            // treeview
            $this->titleColumn = 'label';
            $this->orderColumn = 'id';
            $this->parentColumn = CustomColumn::getEloquent('parent_organization', $this->custom_table)->getIndexColumnName();
        }

        parent::__construct($attributes);
    }

    public function getLabelAttribute()
    {
        return $this->getLabel();
    }

    public function getCustomTableAttribute()
    {
        // return resuly using cache
        return System::requestSession('custom_table_' . $this->custom_table_name, function () {
            return CustomTable::getEloquent($this->custom_table_name);
        });
    }

    public function getWorkflowValueAttribute()
    {
        return WorkflowValue::where('morph_id', $this->id)
            ->where('morph_type', $this->custom_table->table_name)
            ->where('workflow_id', $this->custom_table->workflow_id)
            //->where('datalock_flg', false)
            ->orderBy('updated_at', 'desc')
            ->first();
    }

    public function getWorkflowStatusIdAttribute()
    {
        return array_get($this->workflow_value, 'workflow_status_id')?? 0;
    }

    public function getWorkflowStatusAttribute()
    {
        $workflow_value = $this->workflow_value;
        if (isset($workflow_value)) {
            return array_get($workflow_value->workflow_status, 'status_name');
        }
        return null;
    }

    // user value_authoritable. it's all role data. only filter morph_type
    public function value_authoritable_users()
    {
        return $this->morphToMany(getModelName(SystemTableName::USER), 'parent', 'custom_value_authoritables', 'parent_id', 'authoritable_target_id')
            ->withPivot('authoritable_target_id', 'authoritable_user_org_type', 'authoritable_type')
            ->wherePivot('authoritable_user_org_type', SystemTableName::USER)
            ;
    }

    // user value_authoritable. it's all role data. only filter morph_type
    public function value_authoritable_organizations()
    {
        return $this->morphToMany(getModelName(SystemTableName::ORGANIZATION), 'parent', 'custom_value_authoritables', 'parent_id', 'authoritable_target_id')
            ->withPivot('authoritable_target_id', 'authoritable_user_org_type', 'authoritable_type')
            ->wherePivot('authoritable_user_org_type', SystemTableName::ORGANIZATION)
            ;
    }

    // get workflow actions which has authority
    public function workflow_actions()
    {
        $workflow_id = array_get($this->custom_table, 'workflow_id');
        $status_id = $this->workflow_status_id;
        if ($workflow_id) {
            $user = \Exment::user()->base_user_id;
            $organization = \Exment::user()->getOrganizationIds();
            $actions = WorkflowAction::select()
                ->leftJoin('workflow_authorities', 'workflow_authorities.workflow_action_id', '=', 'workflow_actions.id')
                ->where('workflow_actions.workflow_id', $workflow_id)
                ->where('workflow_actions.status_from', $status_id)
                ->where(function ($query) use($user, $organization){
                    $query
                        ->whereNull('workflow_authorities.related_id')
                        ->orWhere(function ($query1) use($user) {
                            $query1
                                ->where('workflow_authorities.related_id', '=', $user)
                                ->where('workflow_authorities.related_type', '=', SystemTableName::USER);
                        });
                    if (count($organization) > 0) {
                        $query->orWhere(function ($query2) use($organization) {
                            $query2
                                ->where('workflow_authorities.related_id', '=', $organization)
                                ->where('workflow_authorities.related_type', '=', SystemTableName::ORGANIZATION);
                        });
                    }
                })->get();
            return $actions;
        }
        return [];
    }
 
    public function parent_custom_value()
    {
        return $this->morphTo();
    }
    /**
     * get or set remove_file_columns
     */
    public function remove_file_columns($key = null)
    {
        // get
        if (!isset($key)) {
            return $this->remove_file_columns;
        }

        // set
        $this->remove_file_columns[] = $key;
        return $this;
    }
    
    public function saved_notify($disable_saved_notify)
    {
        $this->saved_notify = $disable_saved_notify;
        return $this;
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            // re-get field data --------------------------------------------------
            $model->prepareValue();

            // call plugins
            Plugin::pluginPreparing(Plugin::getPluginsByTable($model), 'saving', [
                'custom_table' => $model->custom_table,
                'custom_value' => $model,
            ]);

            // prepare revision
            $model->preSave();
        });
        static::saved(function ($model) {
            $model->setFileValue();
            
            // call plugins
            Plugin::pluginPreparing(Plugin::getPluginsByTable($model), 'saved', [
                'custom_table' => $model->custom_table,
                'custom_value' => $model,
            ]);

            $model->savedValue();
            CustomValueAuthoritable::setValueAuthoritable($model);
        });
        static::created(function ($model) {
            // send notify
            $model->notify(NotifySavedType::CREATE);

            $model->postCreate();
        });
        static::updated(function ($model) {
            // send notify
            $model->notify(NotifySavedType::UPDATE);

            // set revision
            $model->postSave();
        });
        
        static::deleting(function ($model) {
            static::setUser($model, ['deleted_user_id']);

            // saved_notify(as update) disable
            $saved_notify = $model->saved_notify;
            $model->saved_notify = false;
            $model->save();
            $model->saved_notify = $saved_notify;
            
            $model->deleteRelationValues();
        });

        static::deleted(function ($model) {
            $model->preSave();
            $model->postDelete();

            $model->notify(NotifySavedType::DELETE);
        });

        static::addGlobalScope(new CustomValueModelScope);
    }

    /**
     * Validator before saving.
     * Almost multiple columns validation
     *
     * @param array $input laravel-admin input
     * @return mixed
     */
    public function validatorSaving($input)
    {
        $errors = [];
        // getting custom_table's custom_column_multi_uniques
        $multi_uniques = $this->custom_table->getMultipleUniques();
        if (!isset($multi_uniques) || count($multi_uniques) == 0) {
            return true;
        }
        foreach ($multi_uniques as $multi_unique) {
            $query = static::query();
            $column_keys = [];
            foreach ([1,2,3] as $key) {
                if (is_null($column_id = $multi_unique->{'unique' . $key})) {
                    continue;
                }

                $column = CustomColumn::getEloquent($column_id);
                $column_name = $column->column_name;

                // get query key
                if ($column->index_enabled) {
                    $query_key = $column->getIndexColumnName();
                } else {
                    $query_key = 'value->' . $column_name;
                }

                // get value
                $value = array_get($input, 'value.' . $column_name);
                if (is_array($value)) {
                    $value = json_encode(array_filter($value));
                }

                $query->where($query_key, $value);

                $column_keys[] = $column;
            }

            if (empty($column_keys)) {
                continue;
            }
            
            // if all column's value is empty, continue.
            if (collect($column_keys)->filter(function ($column) use ($input) {
                return !is_nullorempty(array_get($input, 'value.' . $column->column_name));
            })->count() == 0) {
                continue;
            }

            if (isset($this->id)) {
                $query->where('id', '<>', $this->id);
            }

            if ($query->count() > 0) {
                $errorTexts = collect($column_keys)->map(function ($column_key) {
                    return $column_key->column_view_name;
                });
                $errorText = implode(exmtrans('common.separate_word'), $errorTexts->toArray());
                foreach ($column_keys as $column_key) {
                    $errors["value.{$column_key->column_name}"] = [exmtrans('custom_value.help.multiple_uniques', $errorText)];
                }
            }
        }

        return count($errors) > 0 ? $errors : true;
    }

    // re-set field data --------------------------------------------------
    // if user update form and save, but other field remove if not conatins form field, so re-set field before update
    protected function prepareValue()
    {
        ///// saving event for image, file event
        // https://github.com/z-song/laravel-admin/issues/1024
        // because on value edit display, if before upload file and not upload again, don't post value.
        $value = $this->value;
        $original = json_decode($this->getOriginal('value'), true);
        // get  columns
        $custom_columns = $this->custom_table->custom_columns;

        // loop columns
        $update_flg = false;
        foreach ($custom_columns as $custom_column) {
            $column_name = $custom_column->column_name;
            // get saving value
            $v = $custom_column->column_item->setCustomValue($this)->saving();
            // if has value, update
            if (isset($v)) {
                array_set($value, $column_name, $v);
                $update_flg = true;
            }

            if ($this->setAgainOriginalValue($value, $original, $custom_column)) {
                $update_flg = true;
            }
        }

        // array_forget if $v is null
        // if not execute this, mysql column "virtual" returns string "null".
        foreach ($value as $k => $v) {
            if (is_null($v)) {
                $update_flg = true;
                array_forget($value, $k);
            }
        }

        // if update
        if ($update_flg) {
            $this->setAttribute('value', $value);
        }
    }

    /**
     * set original data.
     */
    protected function setAgainOriginalValue(&$value, $original, $custom_column)
    {
        if (is_null($value)) {
            $value = [];
        }
        $column_name = $custom_column->column_name;
        // if not key, set from original
        if (array_key_exists($column_name, $value)) {
            return false;
        }
        // if column has $remove_file_columns, continue.
        // property "$remove_file_columns" uses user wants to delete file
        if (in_array($column_name, $this->remove_file_columns())) {
            return false;
        }

        if (!array_key_value_exists($column_name, $original)) {
            return false;
        }

        $value[$column_name] = array_get($original, $column_name);
        return true;
    }

    /**
     * saved file id.
     */
    protected function setFileValue()
    {
        // if requestsession "file upload uuid"(for set data this value's id and type into files)
        $uuids = System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID);
        if (isset($uuids)) {
            foreach ($uuids as $uuid) {
                // get id matching path
                $file = File::getData(array_get($uuid, 'uuid'));
                $value = $file->getCustomValueFromForm($this, $uuid);
                if (is_null($value)) {
                    continue;
                }

                File::getData(array_get($uuid, 'uuid'))->saveCustomValue(array_get($value, 'id'), array_get($uuid, 'column_name'), array_get($uuid, 'custom_table'));
            }
        }
    }

    /**
     * saved value event.
     */
    protected function savedValue()
    {
        $this->syncOriginal();

        // if already updated, not save again
        if ($this->already_updated) {
            return;
        }

        $columns = $this->custom_table
            ->custom_columns
            ->all();

        $update_flg = false;
        // loop columns
        foreach ($columns as $custom_column) {
            $column_name = array_get($custom_column, 'column_name');
            // get saved value
            $v = $custom_column->column_item->setCustomValue($this)->saved();

            // if has value, update
            if (isset($v)) {
                $this->setValue($column_name, $v);
                $update_flg = true;
            }
        }
        // if update
        if ($update_flg) {
            $this->already_updated = true;
            $this->save();
        }
    }

    
    // notify user --------------------------------------------------
    public function notify($notifySavedType)
    {
        // if $saved_notify is false, return
        if ($this->saved_notify === false) {
            return;
        }

        $notifies = $this->custom_table->notifies;

        // loop for $notifies
        foreach ($notifies as $notify) {
            $notify->notifyCreateUpdateUser($this, $notifySavedType);
        }
    }

    /**
     * delete relation if record delete
     */
    protected function deleteRelationValues()
    {
        $custom_table = $this->custom_table;
        // delete custom relation is 1:n value
        $relations = CustomRelation::getRelationsByParent($custom_table, RelationType::ONE_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            $child_table = $relation->child_custom_table;
            // find keys
            getModelName($child_table)
                ::where('parent_id', $this->id)
                ->where('parent_type', $custom_table->table_name)
                ->delete();
        }
        
        // delete custom relation is n:n value
        $relations = CustomRelation::getRelationsByParent($custom_table, RelationType::MANY_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            // ge pivot table
            $pivot_name = $relation->getRelationName();
            
            // find keys and delete
            \DB::table($pivot_name)
                ->where('parent_id', $this->id)
                ->delete();
        }
        
        // delete custom relation is n:n value (for children)
        $relations = CustomRelation::getRelationsByChild($custom_table, RelationType::MANY_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            // ge pivot table
            $pivot_name = $relation->getRelationName();
            
            // find keys and delete
            \DB::table($pivot_name)
                ->where('child_id', $this->id)
                ->delete();
        }

        // delete value_authoritables
        CustomValueAuthoritable::deleteValueAuthoritable($this);
        // delete role group
        RoleGroupUserOrganization::deleteRoleGroupUserOrganization($this);
    }
    
    /**
     * get Authoritable values.
     * this function selects value_authoritable, and get all values.
     */
    public function getAuthoritable($related_type)
    {
        if ($related_type == SystemTableName::USER) {
            $query = $this
            ->value_authoritable_users()
            ->where('authoritable_target_id', \Exment::user()->base_user_id);
        } elseif ($related_type == SystemTableName::ORGANIZATION) {
            $query = $this
            ->value_authoritable_organizations()
            ->whereIn('authoritable_target_id', \Exment::user()->getOrganizationIds());
        }

        return $query->get();
    }

    public function setValue($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('value', $key, $val, $forgetIfNull);
    }
    
    public function getValue($column, $label = false, $options = [])
    {
        if (is_null($column)) {
            return null;
        }

        $options = array_merge(
            [
                'format' => null,
                'disable_currency_symbol' => false,
            ],
            $options
        );
        $custom_table = $this->custom_table;

        // if $column is string and  and contains comma
        if (is_string($column) && str_contains($column, ',')) {
            ///// getting value Recursively
            // split comma
            $columns = explode(",", $column);
            // if $columns count >= 2, loop columns
            if (count($columns) >= 2) {
                $loop_value = $this;
                foreach ($columns as $k => $c) {
                    $lastIndex = ($k == count($columns) - 1);
                    // if $k is not last index, $loop_label is false(because using CustomValue Object)
                    if (!$lastIndex) {
                        $loop_label = false;
                    }
                    // if last index, $loop_label is called $label
                    else {
                        $loop_label = $label;
                    }
                    // get value using $c
                    $loop_value = $loop_value->getValue($c, $loop_label);
                    // if null, return
                    if (is_null($loop_value)) {
                        return null;
                    }
                    
                    // if last index, return value
                    if ($lastIndex) {
                        return $loop_value;
                    }
                    
                    // get custom table. if CustomValue
                    if (!($loop_value instanceof CustomValue)) {
                        return null;
                    }
                }
                return $loop_value;
            } else {
                $column = $columns[0];
            }
        }

        ///// get custom column
        // if string
        $column = CustomColumn::getEloquent($column, $custom_table);
        if (is_null($column)) {
            return null;
        }

        $item = CustomItem::getItem($column, $this);
        if (!isset($item)) {
            return null;
        }

        $item->options($options);

        // get value
        // using ValueType
        $valueType = ValueType::getEnum($label);
        if(isset($valueType)){
            return $valueType->getCustomValue($item, $this);
        }

        if ($label === true) {
            return $item->text();
        }
        return $item->value();
    }

    /**
     * Get vustom_value's label
     * @param CustomValue $custom_value
     * @return string
     */
    public function getLabel()
    {
        $custom_table = $this->custom_table;

        $key = 'custom_table_use_label_flg_' . $this->custom_table_name;
        $label_columns = System::requestSession($key, function () use ($custom_table) {
            $table_label_format = $custom_table->getOption('table_label_format');
            if (boolval(config('exment.expart_mode', false)) && isset($table_label_format)) {
                return $table_label_format;
            }
            return $custom_table->table_labels;
        });

        if (isset($label_columns) && is_string($label_columns)) {
            return $this->getExpansionLabel($label_columns);
        } else {
            return $this->getBasicLabel($label_columns);
        }
    }

    /**
     * get label string (general setting case)
     */
    protected function getBasicLabel($label_columns)
    {
        $custom_table = $this->custom_table;

        if (!isset($label_columns) || count($label_columns) == 0) {
            $columns = [$custom_table->custom_columns->first()];
        } else {
            $columns = $label_columns->map(function ($label_column) {
                return CustomColumn::getEloquent($label_column->table_label_id);
            });
        }

        // loop for columns and get value
        $labels = [];

        // if table's use_label_id_flg is true, add id
        if (boolval($custom_table->getOption('use_label_id_flg', false))) {
            $labels[] = '#'.strval($this->id);
        }

        foreach ($columns as $column) {
            if (!isset($column)) {
                continue;
            }
            $label = $this->getValue($column, true);
            if (empty($label)) {
                continue;
            }
            $labels[] = $label;
        }
        if (count($labels) == 0) {
            return strval($this->id);
        }

        return implode(' ', $labels);
    }

    /**
     * get custom format label
     */
    protected function getExpansionLabel($label_format)
    {
        $options['afterCallback'] = function ($text, $custom_value, $options) {
            return $this->replaceText($text, $options);
        };
        return replaceTextFromFormat($label_format, $this, $options);
    }

    /**
     * replace text. ex.comma, &yen, etc...
     */
    protected function replaceText($text, $documentItem = [])
    {
        // add comma if number_format
        if (array_key_exists('number_format', $documentItem) && !str_contains($text, ',') && is_numeric($text)) {
            $text = number_format($text);
        }

        // replace <br/> or \r\n, \n, \r to new line
        $text = preg_replace("/\\\\r\\\\n|\\\\r|\\\\n/", "\n", $text);
        // &yen; to
        $text = str_replace("&yen;", "¥", $text);

        return $text;
    }

    /**
     * get target custom_value's self link url
     */
    public function getUrl($options = [])
    {
        // options is boolean, tag is true
        if (is_bool($options)) {
            $options = ['tag' => true];
        }
        $options = array_merge(
            [
                'tag' => false,
                'uri' => null,
                'list' => false,
                'icon' => null,
                'modal' => true,
                'add_id' => false,
            ],
            $options
        );
        $tag = boolval($options['tag']);

        // if this table is document, create target blank link
        if ($this->custom_table->table_name == SystemTableName::DOCUMENT) {
            $url = admin_urls('files', $this->getValue('file_uuid', true));
            if (!$tag) {
                return $url;
            }
            $label = esc_html($this->getValue('document_name'));
            return "<a href='$url' target='_blank'>$label</a>";
        }
        $url = admin_urls('data', $this->custom_table->table_name);
        if (!boolval($options['list'])) {
            $url = url_join($url, $this->id);
        }
        
        if (isset($options['uri'])) {
            $url = url_join($url, $options['uri']);
        }
        if (!$tag) {
            return $url;
        }
        if (isset($options['icon'])) {
            $label = '<i class="fa ' . $options['icon'] . '" aria-hidden="true"></i>';
        } else {
            $label = esc_html($this->getLabel());
        }

        if (boolval($options['modal'])) {
            $url .= '?modal=1';
            $href = 'javascript:void(0);';
            $widgetmodal_url = sprintf(" data-widgetmodal_url='$url' data-toggle='tooltip' title='%s'", exmtrans('custom_value.data_detail'));
        } else {
            $href = $url;
            $widgetmodal_url = null;
        }

        if (boolval($options['add_id'])) {
            $widgetmodal_url .= " data-id='{$this->id}'";
        }

        return "<a href='$href'$widgetmodal_url>$label</a>";
    }
    
    /**
     * get target custom_value's relation search url
     */
    public function getRelationSearchUrl($options = [])
    {
        if (is_bool($options)) {
            $options = ['force' => true];
        }
        $options = array_merge(
            [
                'force' => false
            ],
            $options
        );
        return admin_url("search?table_name={$this->custom_table->table_name}&value_id={$this->id}" . ($options['force'] ? '&relation=1' : ''));
    }

    /**
     * merge value from custom_value
     */
    public function mergeValue($value)
    {
        foreach ($this->custom_table->custom_columns as $custom_column) {
            $column_name = $custom_column->column_name;
            // if not key in value, set default value
            if (!array_has($value, $column_name)) {
                $value[$column_name] = $this->getValue($column_name);
            }
        }

        return $value;
    }
    
    /**
     * get parent value
     */
    public function getParentValue($isonly_label = false)
    {
        $model = getModelName($this->parent_type)::find($this->parent_id);
        if (!$isonly_label) {
            return $model ?? null;
        }
        return $model->label ?? null;
    }
    
    /**
     * Get Custom children value summary
     */
    public function getSum($custom_column)
    {
        $name = $custom_column->index_enabled ? $custom_column->getIndexColumnName() : 'value->'.array_get($custom_column, 'column_name');

        if (!isset($name)) {
            return 0;
        }
        return $this->getChildrenValues($custom_column, true)
            ->sum($name);
    }

    /**
     * Get Custom children Value.
     * v1.1.0 changes ... get children values using relation or select_table
     */
    public function getChildrenValues($relation, $returnBuilder = false)
    {
        // first, get children values as relation
        if ($relation instanceof CustomColumn) {
            // get custom column as array
            // target column is select table and has index, get index name
            if (ColumnType::isSelectTable($relation->column_type) && $relation->indexEnabled()) {
                $index_name = $relation->getIndexColumnName();
                // get children values where this id
                $query = getModelName(CustomTable::getEloquent($relation))
                    ::where($index_name, $this->id);
                return $returnBuilder ? $query : $query->get();
            }
        }
    
        // get custom column as array
        $child_table = CustomTable::getEloquent($relation);
        $pivot_table_name = CustomRelation::getRelationNameByTables($this->custom_table, $child_table);

        if (isset($pivot_table_name)) {
            return $returnBuilder ? $this->{$pivot_table_name}() : $this->{$pivot_table_name};
        }
        
        return null;
    }

    /**
     * set revision data
     */
    public function setRevision($revision_suuid)
    {
        $revision_value = $this->revisionHistory()->where('suuid', $revision_suuid)->first()->new_value;
        if (is_json($revision_value)) {
            $revision_value = \json_decode($revision_value, true);
        }
        $this->value = $revision_value;
        return $this;
    }
    
    /**
     * set workflow status condition
     */
    public function scopeWorkflowStatus($query, $condition, $status) {
        switch ($condition) {
            case ViewColumnFilterOption::EQ:
                return $query->whereExists(function ($subqry) use ($status) {
                    $subqry->select(\DB::raw(1))
                        ->from('workflow_values')
                        ->whereRaw($this->getTable().'.id = workflow_values.morph_id')
                        ->where('workflow_values.morph_type', $this->custom_table_name)
                        //->where('workflow_values.datalock_flg', false)
                        ->where('workflow_values.workflow_status_id', $status);
                });
            case ViewColumnFilterOption::NE:
                return $query->whereNotExists(function ($subqry) use ($status) {
                    $subqry->select(\DB::raw(1))
                        ->from('workflow_values')
                        ->whereRaw($this->getTable().'.id = workflow_values.morph_id')
                        ->where('workflow_values.morph_type', $this->custom_table_name)
                        //->where('workflow_values.datalock_flg', false)
                        ->where('workflow_values.workflow_status_id', $status);
                });
            case ViewColumnFilterOption::NULL:
                return $query->whereNotExists(function ($subqry) use ($status) {
                    $subqry->select(\DB::raw(1))
                        ->from('workflow_values')
                        ->whereRaw($this->getTable().'.id = workflow_values.morph_id')
                        ->where('workflow_values.morph_type', $this->custom_table_name)
                        //->where('workflow_values.datalock_flg', false)
                        ->where(\DB::raw('ifnull(workflow_values.workflow_status_id, 0)'), '<>', 0);
                });
            case ViewColumnFilterOption::NOT_NULL:
                return $query->whereExists(function ($subqry) use ($status) {
                    $subqry->select(\DB::raw(1))
                        ->from('workflow_values')
                        ->whereRaw($this->getTable().'.id = workflow_values.morph_id')
                        ->where('workflow_values.morph_type', $this->custom_table_name)
                        //->where('workflow_values.datalock_flg', false)
                        ->where(\DB::raw('ifnull(workflow_values.workflow_status_id, 0)'), '<>', 0);
                });
        }
    }
        
    /**
     * Get Query for text search
     *
     * @return void
     */
    public function getSearchQuery($q, $options = [])
    {
        $options = $this->getQueryOptions($q, $options);
        extract($options);

        if (empty($searchColumns)) {
            // return null if searchColumns is not has
            return null;
        }

        // crate union query
        $queries = [];
        for ($i = 0; $i < count($searchColumns) - 1; $i++) {
            $searchColumn = $searchColumns[$i];
            $query = static::query();
            $query->where($searchColumn, $mark, $value)->select('id');
            $query->take($takeCount);

            $queries[] = $query;
        }

        $searchColumn = $searchColumns->last();
        $subquery = static::query();
        $subquery->where($searchColumn, $mark, $value)->select('id');
        $subquery->take($takeCount);

        foreach ($queries as $inq) {
            $subquery->union($inq);
        }

        // create main query
        $mainQuery = \DB::query()->fromSub($subquery, 'sub');

        return $mainQuery;
    }

    /**
     * Set Query for text search. use orwhere
     *
     * @return void
     */
    public function setSearchQueryOrWhere(&$query, $q, $options = [])
    {
        $options = $this->getQueryOptions($q, $options);

        $query->where(function ($query) use ($options) {
            extract($options);

            for ($i = 0; $i < count($searchColumns); $i++) {
                $searchColumn = $searchColumns[$i];
                $query->orWhere($searchColumn, $mark, $value);
            }
        });
    }

    /**
     * Get Query Options for search
     *
     * @param string $q search text
     * @param array $options
     * @return void
     */
    protected function getQueryOptions($q, $options = [])
    {
        $options = array_merge(
            [
                'isLike' => true,
                'maxCount' => 5,
                'paginate' => false,
                'makeHidden' => false,
                'searchColumns' => null,
                'relation' => false,
                
            ],
            $options
        );
        extract($options);

        // if selected target column,
        if (is_null($searchColumns)) {
            $searchColumns = $this->custom_table->getSearchEnabledColumns()->map(function ($c) {
                return $c->getIndexColumnName();
            });
        }

        if (!isset($searchColumns) || count($searchColumns) == 0) {
            return $options;
        }
        
        if (System::filter_search_type() == FilterSearchType::ALL) {
            $value = ($isLike ? '%' : '') . $q . ($isLike ? '%' : '');
        } else {
            $value = $q . ($isLike ? '%' : '');
        }
        $mark = ($isLike ? 'LIKE' : '=');

        if ($relation) {
            $takeCount = intval(config('exment.keyword_search_relation_count', 5000));
        } else {
            $takeCount = intval(config('exment.keyword_search_count', 1000));
        }

        // if not paginate, only take maxCount
        if (!$paginate) {
            $takeCount = is_null($maxCount) ? $takeCount : min($takeCount, $maxCount);
        }

        $options['searchColumns'] = $searchColumns;
        $options['takeCount'] = $takeCount;
        $options['mark'] = $mark;
        $options['value'] = $value;

        return $options;
    }
}
