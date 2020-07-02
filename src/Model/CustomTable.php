<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\FormActionType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Revisionable\Revision;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Exceedone\Exment\Services\FormHelper;
use Exceedone\Exment\Validator\EmptyRule;
use Exceedone\Exment\Validator\CustomValueRule;
use Exceedone\Exment\ColumnItems\WorkflowItem;
use Encore\Admin\Facades\Admin;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Input;

/**
 * Custom Table Class
 */
class CustomTable extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\AutoSUuidTrait;
    use Traits\TemplateTrait;
    use Traits\ColumnOptionQueryTrait;

    protected $casts = ['options' => 'json'];
    protected $guarded = ['id', 'suuid', 'system_flg'];

    public static $templateItems = [
        'excepts' => ['suuid'],
        'uniqueKeys' => ['table_name'],
        'langs' => [
            'keys' => ['table_name'],
            'values' => ['table_view_name', 'description'],
        ],
        'children' =>[
            'custom_columns' => CustomColumn::class,
            'custom_column_multisettings' => CustomColumnMulti::class,
        ],
        'ignoreImportChildren' => ['custom_columns', 'custom_column_multisettings'],
    ];

    /**
     * Getted custom columns. if call attributes "custom_columns_cache", already called, return this value.
     */
    protected $cached_custom_columns = [];

    public function custom_columns()
    {
        return $this->hasMany(CustomColumn::class, 'custom_table_id');
    }

    public function custom_views()
    {
        return $this->hasMany(CustomView::class, 'custom_table_id')
            ->orderBy('view_type')
            ->orderBy('id');
    }
 
    public function custom_forms()
    {
        return $this->hasMany(CustomForm::class, 'custom_table_id');
    }
 
    public function custom_operations()
    {
        return $this->hasMany(CustomOperation::class, 'custom_table_id');
    }

    public function custom_relations()
    {
        return $this->hasMany(CustomRelation::class, 'parent_custom_table_id');
    }
    
    public function child_custom_relations()
    {
        return $this->hasMany(CustomRelation::class, 'child_custom_table_id');
    }
    
    public function from_custom_copies()
    {
        return $this->hasMany(CustomCopy::class, 'from_custom_table_id');
    }
    
    public function notifies()
    {
        return $this->hasMany(Notify::class, 'custom_table_id');
    }
    
    public function custom_form_block_target_tables()
    {
        return $this->hasMany(CustomFormBlock::class, 'form_block_target_table_id');
    }

    public function custom_column_multisettings()
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id');
    }

    public function custom_form_priorities()
    {
        return $this->hasManyThrough(CustomFormPriority::class, CustomForm::class, 'custom_table_id', 'custom_form_id');
    }

    public function workflow_tables()
    {
        return $this->hasMany(WorkflowTable::class, 'custom_table_id');
    }

    public function multi_uniques()
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id')
            ->where('multisetting_type', MultisettingType::MULTI_UNIQUES);
    }

    public function table_labels()
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id')
            ->where('multisetting_type', MultisettingType::TABLE_LABELS);
    }

    public function compare_columns()
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id')
            ->where('multisetting_type', MultisettingType::COMPARE_COLUMNS);
    }

    public function share_settings()
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id')
            ->where('multisetting_type', MultisettingType::SHARE_SETTINGS);
    }

    /**
     * Whether this model disables delete
     *
     * @return boolean if true, cannot delete.
     */
    public function getDisabledDeleteAttribute()
    {
        if (boolval($this->system_flg)) {
            return true;
        }
        return !empty(self::validateDestroy($this->id));
    }

    /**
     * check if target id table can be deleted
     * @param int|string $id
     * @return [boolean, string] status, error message.
     */
    public static function validateDestroy($id)
    {
        // check select_table
        $child_count = CustomRelation::where('parent_custom_table_id', $id)
            ->count();

        if ($child_count > 0) {
            return [
                'status'  => false,
                'message' => exmtrans('custom_value.help.relation_error'),
            ];
        }
        // check select_table
        $column_count = CustomColumn::whereIn('options->select_target_table', [strval($id), intval($id)])
            ->where('custom_table_id', '<>', $id)
            ->count();

        if ($column_count > 0) {
            return [
                'status'  => false,
                'message' => exmtrans('custom_value.help.reference_error'),
            ];
        }
    }

    /**
     * get Custom columns using cache
     */
    public function getCustomColumnsCacheAttribute()
    {
        if (!empty($this->cached_custom_columns)) {
            return $this->cached_custom_columns;
        }

        $this->cached_custom_columns = $this->hasManyCache(CustomColumn::class, 'custom_table_id');
        return $this->cached_custom_columns;
    }

    /**
     * Get Columns where select_target_table's id is this table.
     *
     * @return void
     */
    public function getSelectedItems()
    {
        return CustomColumn::where('options->select_target_table', $this->id)
            ->get();
    }

    public function scopeSearchEnabled($query)
    {
        return $query->whereIn('options->search_enabled', [1, "1", true]);
    }

    public function getSelectTables()
    {
        $list = $this->custom_columns_cache->mapWithKeys(function ($item) {
            $key = $item->getIndexColumnName();
            $val = array_get($item->options, 'select_target_table');
            return [$key => (is_numeric($val)? intval($val): null)];
        });
        $list = $list->filter()->toArray();
        return $list;
    }

    /**
     * Get priority form using condition
     *
     * @param int|string $id
     * @return CustomForm $custom_form
     */
    public function getPriorityForm($id = null)
    {
        $custom_value = $this->getValueModel($id);

        if (isset($custom_value)) {
            $custom_form_priorities = $this->custom_form_priorities->sortBy('order');
            foreach ($custom_form_priorities as $custom_form_priority) {
                if ($custom_form_priority->isMatchCondition($custom_value)) {
                    return $custom_form_priority->custom_form;
                }
            }
        }
        return CustomForm::getDefault($this);
    }

    /**
     * Get custom columns, filtering "references" "users" "organizations", in this table.
     * Value is custom column.
     * Filter is select_target_table
     *
     * @param int|string|CustomTable|null $select_target_table if filter select target table, set value.
     * @return Collection
     */
    public function getSelectTableColumns($select_target_table = null)
    {
        return $this->custom_columns_cache->filter(function ($item) use ($select_target_table) {
            if (!ColumnType::isSelectTable($item->column_type)) {
                return false;
            }

            if (is_null($select_target_table)) {
                return true;
            }

            $select_target_table = CustomTable::getEloquent($select_target_table);
            if (!isset($select_target_table)) {
                return false;
            }

            return isset($item->select_target_table) && $select_target_table->id == $item->select_target_table->id;
        });
    }

    /**
     * Get Label Columns.
     *
     * @return Collection|string
     */
    public function getLabelColumns()
    {
        $key = 'custom_table_use_label_flg_' . $this->table_name;
        return System::requestSession($key, function () {
            $table_label_format = $this->getOption('table_label_format');
            if (boolval(config('exment.expart_mode', false)) && isset($table_label_format)) {
                return $table_label_format;
            }
            return $this->table_labels;
        });
    }

    /**
     * Get key-value items.
     * Key is column index name.
     * Value is select_target_table's table id.
     *
     * @return array
     */
    public function getSelectedTables()
    {
        return CustomColumn::where('options->select_target_table', $this->id)
            ->get()
            ->mapWithKeys(function ($item) {
                $key = $item->getIndexColumnName();
                return [$key => $item->custom_table_id];
            })->filter()->toArray();
    }

    /**
     * Get key-value items.
     * Key is column index name.
     * Value is custom column.
     *
     * @return Collection
     */
    public function getSelectedTableColumns()
    {
        return CustomColumn::where('options->select_target_table', $this->id)
            ->get()
            ->mapWithKeys(function ($item) {
                $key = $item->getIndexColumnName();
                return [$key => $item];
            })->filter();
    }

    /**
     * get Select table's relation columns.
     * If there are two or more select_tables in the same table and they are in a parent-child relationship, parent-child relationship information is acquired.
     *
     * @return array contains parent_column, child_column, searchType
     */
    public function getSelectTableLinkages($checkPermission = true)
    {
        return Linkage::getSelectTableLinkages($this, $checkPermission);
    }



    public function getMultipleUniques($custom_column = null)
    {
        return CustomColumnMulti::allRecords(function ($val) use ($custom_column) {
            if (array_get($val, 'custom_table_id') != $this->id) {
                return false;
            }

            if (!isset($custom_column)) {
                return true;
            }

            if ($val->multisetting_type != MultisettingType::MULTI_UNIQUES) {
                return false;
            }

            $targetid = CustomColumn::getEloquent($custom_column, $this)->id;
            foreach ([1,2,3] as $key) {
                if ($val->{'unique' . $key} == $targetid) {
                    return true;
                }
            }
            return false;
        }, false);
    }

    public function getCompareColumns()
    {
        return CustomColumnMulti::allRecords(function ($val) {
            if (array_get($val, 'custom_table_id') != $this->id) {
                return false;
            }

            if ($val->multisetting_type != MultisettingType::COMPARE_COLUMNS) {
                return false;
            }

            return true;
        }, false);
    }

    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }
    public function clearOption()
    {
        return $this->clearJson('options');
    }
    
    
    /**
     * Delete children items
     */
    public function deletingChildren()
    {
        foreach ($this->custom_columns as $item) {
            $item->deletingChildren();
        }
        foreach ($this->custom_forms as $item) {
            $item->deletingChildren();
        }
        foreach ($this->custom_views as $item) {
            $item->deletingChildren();
        }
        foreach ($this->from_custom_copies as $item) {
            $item->deletingChildren();
        }
        foreach ($this->custom_form_block_target_tables as $item) {
            $item->deletingChildren();
        }

        foreach (WorkflowValue::where('morph_type', $this->table_name)->get() as $item) {
            $item->deletingChildren();
            $item->delete();
        }
    }

    protected static function boot()
    {
        parent::boot();
        
        // add default order
        static::addGlobalScope(new OrderScope('order'));

        static::saving(function ($model) {
            $model->prepareJson('options');
        });

        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
            
            $model->workflow_tables()->delete();
            $model->custom_form_block_target_tables()->delete();
            $model->child_custom_relations()->delete();
            $model->custom_views()->delete();
            $model->custom_forms()->delete();
            $model->custom_columns()->delete();
            $model->custom_relations()->delete();

            // delete items
            Notify::where('custom_table_id', $model->id)->delete();
            Menu::where('menu_type', MenuType::TABLE)->where('menu_target', $model->id)->delete();
            Revision::where('revisionable_type', $model->table_name)->delete();
            
            // delete custom values table
            $model->dropTable();
        });
    }

    /**
     * validation custom_value using each column setting.
     * *If use this function, Please check customMessages.
     *
     * @param array $value input value
     * @param ?CustomValue $custom_value matched custom_value
     * @return mixed
     */
    public function validateValue($value, $custom_value = null, array $options = [])
    {
        extract(
            array_merge([
                'systemColumn' => false,  // whether checking system column
                'column_name_prefix' => null,  // appending error key's prefix, and value prefix
                'appendKeyName' => true, // whether appending key name if eror
                'checkCustomValueExists' => true, // whether checking require custom column
                'asApi' => false, // calling as api
                'appendErrorAllColumn' => false, // if error, append error message for all column
                'validateLock' => true, // whether validate update lock
            ], $options)
        );

        // get rules for validation
        $rules = $this->getValidateRules($value, $custom_value, $options);

        // get custom attributes
        $customAttributes = $this->getValidateCustomAttributes($systemColumn, $column_name_prefix, $appendKeyName);

        // execute validation
        $validator = \Validator::make(array_dot_reverse($value), $rules, [], $customAttributes);

        $errors = $this->validatorMultiUniques($value, $custom_value, $options);
        
        $errors = array_merge(
            $this->validatorCompareColumns($value, $custom_value, $options),
            $errors
        );
        
        $errors = array_merge(
            $this->validatorPlugin($value, $custom_value),
            $errors
        );

        if ($validateLock) {
            $errors = array_merge(
                $this->validatorLock($value, $custom_value, $asApi),
                $errors
            );
        }

        if (count($errors) > 0) {
            $validator->setCustomMessages($errors);
        }

        return $validator;
    }

    /**
     * get validation custom attribute
     */
    public function getValidateCustomAttributes($systemColumn = false, $column_name_prefix = null, $appendKeyName = true)
    {
        $customAttributes = [];

        foreach ($this->custom_columns_cache as $custom_column) {
            $customAttributes[$column_name_prefix . $custom_column->column_name] = "{$custom_column->column_view_name}" . ($appendKeyName ? "({$custom_column->column_name})" : "");

            if ($systemColumn) {
                $rules = [
                    'id',
                    'parent_id',
                    'parent_type',
                    'suuid',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ];
                    
                foreach ($rules as $key => $rule) {
                    $customAttributes[$key] = exmtrans("common.$key") . ($appendKeyName ? "($key)" : "");
                }
            }
        }
        
        return $customAttributes;
    }
    
    /**
     * get validation rules
     */
    public function getValidateRules($value, $custom_value = null, array $options = [])
    {
        extract(
            array_merge([
                'systemColumn' => false,  // whether checking system column
                'column_name_prefix' => null,  // appending error key's prefix, and value prefix
                'appendKeyName' => true, // whether appending key name if eror
                'checkCustomValueExists' => true, // whether checking require custom column
            ], $options)
        );

        // get fields for validation
        $rules = [];
        $fields = [];

        // get custom attributes
        $customAttributes = $this->getValidateCustomAttributes($systemColumn, $column_name_prefix, $appendKeyName);

        foreach ($this->custom_columns_cache as $custom_column) {
            $fields[] = FormHelper::getFormField($this, $custom_column, $custom_value, null, $column_name_prefix, true, true);

            // if not contains $value[$custom_column->column_name], set as null.
            // if not set, we cannot validate null check because $field->getValidator returns false.
            if (is_null($custom_value) && !array_has($value, $column_name_prefix.$custom_column->column_name)) {
                array_set($value, $column_name_prefix.$custom_column->column_name, null);
            }
        }

        // create parent type validation array
        if ($systemColumn) {
            $custom_relation_parent = CustomRelation::getRelationByChild($this, RelationType::ONE_TO_MANY);
            $custom_table_parent = ($custom_relation_parent ? $custom_relation_parent->parent_custom_table : null);
            
            if (!isset($custom_table_parent)) {
                $parent_id_rules = [new EmptyRule];
            } elseif (!$checkCustomValueExists) {
                $parent_id_rules = ['nullable', 'numeric'];
            } else {
                $parent_id_rules = ['nullable', 'numeric', new CustomValueRule($custom_table_parent)];
            }
            $parent_type_rules = isset($custom_table_parent) ? ['nullable', "in:". $custom_table_parent->table_name] : [new EmptyRule];

            // create common validate rules.
            $rules = array_merge([
                'id' => ['nullable', 'numeric'],
                'parent_id' => $parent_id_rules,
                'parent_type' => $parent_type_rules,
                'suuid' => ['nullable', 'regex:/^[a-z0-9]{20}$/'],
                'created_at' => ['nullable', 'date'],
                'updated_at' => ['nullable', 'date'],
                'deleted_at' => ['nullable', 'date'],
            ], $rules);
        }

        // foreach for field validation rules
        foreach ($fields as $field) {
            // get field validator
            $field_validator = $field->getValidator($value);
            if (!$field_validator) {
                continue;
            }
            // get field rules
            $field_rules = $field_validator->getRules();

            // merge rules
            $rules = array_merge($field_rules, $rules);
        }

        return $rules;
    }
    
    public function validatorMultiUniques($input, $custom_value = null, array $options = [])
    {
        extract(
            array_merge([
                'asApi' => false, // calling as api
                'appendErrorAllColumn' => false, // if error, append error message for all column
            ], $options)
        );

        $errors = [];

        // getting custom_table's custom_column_multi_uniques
        $multi_uniques = $this->getMultipleUniques();

        if (!isset($multi_uniques) || count($multi_uniques) == 0) {
            return $errors;
        }

        foreach ($multi_uniques as $multi_unique) {
            $query = $this->getValueModel()->query();
            $column_keys = [];
            foreach ([1,2,3] as $key) {
                if (is_null($column_id = $multi_unique->{'unique' . $key})) {
                    continue;
                }

                $column = CustomColumn::getEloquent($column_id);
                // get value
                $value = array_get($input, 'value.' . $column->column_name);
                if (is_array($value)) {
                    $value = json_encode(array_filter($value));
                }

                $query->where($column->getQueryKey(), $value);

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

            if (isset($custom_value)) {
                $query->where('id', '<>', $custom_value->id);
            }

            if ($query->count() > 0) {
                $errorTexts = collect($column_keys)->map(function ($column_key) {
                    return $column_key->column_view_name;
                });
                $errorText = implode(exmtrans('common.separate_word'), $errorTexts->toArray());
                
                // append error message
                foreach ($column_keys as $column_key) {
                    $errors["value.{$column_key->column_name}"] = [exmtrans('custom_value.help.multiple_uniques', $errorText)];
                    if (!$appendErrorAllColumn) {
                        break;
                    }
                }
            }
        }
        return $errors;
    }
    
    /**
     * Validation comparing 2 columns
     */
    public function validatorCompareColumns($input, $custom_value = null, array $options = [])
    {
        extract(
            array_merge([
                'asApi' => false, // calling as api
            ], $options)
        );

        $errors = [];

        // getting custom_table's custom_column_multi_uniques
        $compare_columns = $this->getCompareColumns();

        if (!isset($compare_columns) || count($compare_columns) == 0) {
            return $errors;
        }

        foreach ($compare_columns as $compare_column) {
            // get two values
            $compareResult = $compare_column->compareValue($input, $custom_value);
            if ($compareResult === true) {
                continue;
            }

            $errors["value.{$compare_column->compare_column1->column_name}"][] = $compareResult;
        }
        return $errors;
    }

    public function validatorLock($input, $custom_value = null, bool $asApi = false)
    {
        if (!array_key_value_exists('updated_at', $input)) {
            return [];
        }

        if (is_nullorempty($custom_value)) {
            return [];
        }

        $errors = [];

        // re-get updated_at value
        $updated_at = $this->getValueModel()->query()->select(['updated_at'])->find($custom_value->id)->updated_at ?? null;

        if (!isset($updated_at)) {
            return [];
        }

        if (\Carbon\Carbon::parse($input['updated_at']) != $updated_at) {
            $errors["updated_at"] = [$asApi ? exmtrans('custom_value.help.lock_error_api') : exmtrans('custom_value.help.lock_error')];

            if (!$asApi) {
                admin_warning(exmtrans('error.header'), exmtrans('custom_value.help.lock_error'));
            }
        }

        return $errors;
    }

    /**
     * validator using plugin
     */
    public function validatorPlugin($input, $custom_value = null)
    {
        return Plugin::pluginValidator($this, [
            'custom_table' => $this,
            'custom_value' => $custom_value,
            'input_value' => array_get($input, 'value'),
        ]);
    }


    /**
     * Set default value from custom column info
     *
     * @param array $value input value
     * @return array Value after assigning default value
     */
    public function setDefaultValue($value)
    {
        // get fields for validation
        $fields = [];
        foreach ($this->custom_columns_cache as $custom_column) {
            // get default value
            $default = $custom_column->getOption('default');

            // if not key in value, set default value
            if (!array_has($value, $custom_column->column_name) && isset($default)) {
                $value[$custom_column->column_name] = $default;
            }
        }

        return $value;
    }

    /**
     * Convert base64 encode file
     *
     * @param array $value input value
     * @return array Value after converting base64 encode file, and files value
     */
    public function convertFileData($value)
    {
        // get file columns
        $file_columns = $this->custom_columns_cache->filter(function ($column) {
            return ColumnType::isAttachment($column->column_type);
        });
        
        $files = [];

        foreach ($file_columns as $file_column) {
            // if not key in value, set default value
            if (!array_has($value, $file_column->column_name)) {
                continue;
            }
            $file_value = $value[$file_column->column_name];
            if (!array_has($file_value, 'name') && !array_has($file_value, 'base64')) {
                continue;
            }

            $file_name = $file_value['name'];
            $file_data = $file_value['base64'];
            $file_data = base64_decode($file_data);

            // convert file name for validation
            $value[$file_column->column_name] = null;

            // append file data
            $files[$file_column->column_name] = [
                'name' => $file_name,
                'data' => $file_data,
                'custom_column' => $file_column,
            ];
        }

        return [$value, $files];
    }

    /**
     * get CustomTable by url
     */
    public static function findByEndpoint($endpoint = null, $withs = [])
    {
        // get table info
        if (!isset($endpoint)) {
            // for command execute
            if (is_null(app('request')->route())) {
                return null;
            }

            $tableKey = app('request')->route()->parameter('tableKey');
            if (!isset($tableKey)) {
                return null;
            }
        } else {
            $tableKey = explode('/', $endpoint)[0];
            $tableKey = explode('?', $tableKey)[0];
        }

        $custom_table = static::getEloquent($tableKey);
        if (!isset($custom_table)) {
            return null;
        }

        return $custom_table;
    }

    /**
     * get filter and sort order from request.
     * @param bool $addFilter append filter url
     * @param array|null $options Options to execute this function
     */
    public function getGridUrl($addFilter = false, $options = [])
    {
        $path = 'data/' . $this->table_name;

        if ($addFilter) {
            $view = array_get($options, 'view');

            if (is_null($view)) {
                $custom_view = CustomView::getDefault($this);
                $view = $custom_view->suuid;
            }

            // get page settings
            $settings = \Exment::user()->getSettingValue($path)?? '[]';
            $settings = json_decode($settings, true);

            // get view settings
            $parameters = [];
            if (isset($view) && array_key_exists($view, $settings)) {
                $parameters = array_get($settings, $view);
            }

            // merge old and current settings
            $parameters = array_merge($options, $parameters);
        }

        if (isset($parameters) && count($parameters) > 0) {
            return admin_url($path).'?'.http_build_query($parameters);
        } else {
            return admin_url($path);
        }
    }

    /**
     * Save database information about filter and sort order to user setting database.
     *
     * @param string $path.
     * @return void
     */
    public function saveGridParameter($path)
    {
        $custom_view = CustomView::getDefault($this);

        if (is_null($custom_view)) {
            return;
        }

        $path = admin_exclusion_path($path);

        $view = $custom_view->suuid;

        $inputs = Arr::except(Input::all(), ['view', '_pjax', '_token', '_method', '_previous_', '_export_', 'format', 'group_key']);

        $parameters = \Exment::user()->getSettingValue($path)?? '[]';
        $parameters = json_decode($parameters, true);

        $parameters[$view] = $inputs;

        Admin::user()->setSettingValue($path, json_encode($parameters));
    }

    /**
     * Get custom table eloquent. key is id, table_name, etc.
     * Since the results are kept in memory, access to the database is minimized.
     *
     * @param mixed $obj id table_name CustomTable_object CustomValue_object.
     * @return null|CustomTable matched custom_table.
     */
    public static function getEloquent($obj, $withs = [])
    {
        if ($obj instanceof CustomTable) {
            return static::withLoad($obj, $withs);
        } elseif ($obj instanceof CustomColumn) {
            return static::withLoad(static::getEloquent($obj->custom_table_id), $withs);
        } elseif ($obj instanceof CustomValue) {
            return static::withLoad($obj->custom_table, $withs);
        }

        if ($obj instanceof \stdClass) {
            $obj = (array)$obj;
        }
        // get id or array value
        if (is_array($obj)) {
            // get id or table_name
            if (array_key_value_exists('id', $obj)) {
                $obj = array_get($obj, 'id');
            } elseif (array_key_value_exists('table_name', $obj)) {
                $obj = array_get($obj, 'table_name');
            } else {
                return null;
            }
        }

        // get eloquent model
        if (is_numeric($obj)) {
            $query_key = 'id';
        } elseif (is_string($obj)) {
            $query_key = 'table_name';
        }
        if (isset($query_key)) {
            // get table
            $obj = static::allRecordsCache(function ($table) use ($query_key, $obj) {
                return array_get($table, $query_key) == $obj;
            })->first();
            if (!isset($obj)) {
                return null;
            }
        }

        return static::withLoad($obj, $withs);
    }

    /**
     * get table list.
     * But filter these:
     *     Get only has role
     *     showlist_flg is true
     */
    public static function filterList($model = null, $options = [])
    {
        $options = array_merge(
            [
                'getModel' => true,
                'permissions' => Permission::CUSTOM_TABLE,
                'with' => null,
                'filter' => null,
                'checkPermission' => true,
            ],
            $options
        );
        if (!isset($model)) {
            $model = new self;
        }
        $model = $model->where('showlist_flg', true);

        // if not exists, filter model using permission
        if ($options['checkPermission'] && !\Exment::user()->hasPermission(Permission::CUSTOM_TABLE)) {
            // get tables has custom_table permission.
            $permission_tables = \Exment::user()->allHasPermissionTables($options['permissions']);
            $permission_table_ids = $permission_tables->map(function ($permission_table) {
                return array_get($permission_table, 'id');
            });
            // filter id;
            $model = $model->whereIn('id', $permission_table_ids);
        }

        if (isset($options['with'])) {
            $with = is_array($options['with']) ? $options['with'] : [$options['with']];
            $model->with($with);
        }

        if (isset($options['filter'])) {
            $model = $options['filter']($model);
        }

        if ($options['getModel']) {
            return $model->get();
        }
        return $model;
    }

    /**
     * get 'with' array for get eloquent
     */
    protected static function getWiths($withs)
    {
        if (is_array($withs)) {
            return $withs;
        }
        if ($withs === true) {
            return ['custom_columns'];
        }
        return [];
    }
    
    /**
     * set lazy load and return
     */
    protected static function withLoad($obj, $withs = [])
    {
        $withs = static::getWiths($withs);
        if (count($withs) > 0) {
            $obj->load($withs);
        }
        return $obj;
    }

    protected function importSetValue(&$json, $options = [])
    {
        $system_flg = array_get($options, 'system_flg', false);
        $table_system_flg = array_get($json, 'system_flg');
        $this->system_flg = ($system_flg && (is_null($table_system_flg) || $table_system_flg != 0));

        // set showlist_flg
        if (!array_has($json, 'showlist_flg')) {
            $this->showlist_flg = true;
        } elseif (boolval(array_get($json, 'showlist_flg'))) {
            $this->showlist_flg = true;
        } else {
            $this->showlist_flg = false;
        }

        // return expects array
        return ['system_flg', 'showlist_flg'];
    }

    public function importSaved($json, $options = [])
    {
        $this->createTable();

        return $this;
    }
    
    /**
     * search value
     */
    public function searchValue($q, $options = [])
    {
        $options = array_merge(
            [
                'isLike' => true, // search as "like"
                'maxCount' => 5, // result max count
                'paginate' => false, // if return as paginate, set true.
                'makeHidden' => false,
                'searchColumns' => null, // if select search columns, set them. If null search for index_enabled columns.
                'relation' => false, // if relation search, set true
                'target_view' => null, // filtering view if select
                'getLabel' => false,
                'executeSearch' => true, // if true, search $q . If false,  not filter.
                'relationColumn' => null, // Linkage object. if has, filtering value.
            ],
            $options
        );
        extract($options);

        $data = [];

        $mainQuery = $this->getValueModel()->getSearchQuery($q, $options);

        if (is_nullorempty($mainQuery)) {
            return null;
        }

        // return as paginate
        if ($paginate) {
            // set custom view, sort
            if (isset($target_view)) {
                $target_view->setValueSort($mainQuery);
            }

            // get data(only id)
            $paginates = $mainQuery->select('id')->paginate($maxCount);

            // set eloquent data using ids
            $ids = collect($paginates->items())->map(function ($item) {
                return $item->id;
            });

            // set pager items
            $query = getModelName($this)::whereIn('id', $ids->toArray());

            // set custom view, sort again.
            if (isset($target_view)) {
                $target_view->setValueSort($query);
            }

            // set with
            $this->setQueryWith($query, $target_view);
                
            $paginates->setCollection($query->get());
            
            if (boolval($makeHidden)) {
                $data = $paginates->makeHidden($this->getMakeHiddenArray());
                $paginates->data = $data;
            }

            // append label
            if (boolval($getLabel)) {
                $paginates->map(function ($model) {
                    $model->append('label');
                });
            }

            return $paginates;
        }

        // set custom view, sort.
        if (isset($target_view)) {
            $target_view->setValueSort($mainQuery);
        }

        // return default
        $ids = $mainQuery->select('id')->take($maxCount)->get()->pluck('id');
        
        $query = getModelName($this)::whereIn('id', $ids);
    
        // set custom view, sort again
        if (isset($target_view)) {
            $target_view->setValueSort($query);
        }
        
        // set with
        $this->setQueryWith($query, $target_view);

        return $query->take($maxCount)->get();
    }

    /**
     * search relation value
     */
    public function searchRelationValue($search_type, $parent_value_id, $child_table, &$options = [])
    {
        $options = array_merge(
            [
                'paginate' => false,
                'maxCount' => 5,
                'searchColumns' => null, // if search_type is SELECT_TABLE, and selecting target, set columns collection
                'target_view' => null, // filtering view if select
            ],
            $options
        );
        extract($options);
        
        $child_table = static::getEloquent($child_table);

        switch ($search_type) {
            // self table
            case SearchType::SELF:
                // set query info
                $options['listQuery'] = [
                    'id' => $parent_value_id,
                ];
                
                return [$this->getValueModel($parent_value_id)];
            // select_table(select box)
            case SearchType::SELECT_TABLE:
                // get columns for relation child to parent
                if (!isset($searchColumns)) {
                    $searchColumns = $child_table->getSelectTableColumns($this->id);
                }

                // set query info
                if ($searchColumns->count() > 0) {
                    $options['listQuery'] = [
                        $searchColumns->first()->getIndexColumnName() => $parent_value_id,
                    ];
                }

                return $child_table->searchValue($parent_value_id, [
                    'isLike' => false,
                    'paginate' => $paginate,
                    'relation' => true,
                    'searchColumns' => $searchColumns,
                    'maxCount' => $maxCount,
                    'target_view' => $target_view,
                ]);
            
            // one_to_many
            case SearchType::ONE_TO_MANY:
                $query = $child_table->getValueModel()->query();
                RelationTable::setQueryOneMany($query, $this, $parent_value_id);

                // set query info
                $options['listQuery'] = [
                    'parent_id' => $parent_value_id,
                ];

                ///// if has display table, filter display table and $child_table
                if (isset($display_table)) {
                    $child_table->filterDisplayTable($query, $display_table, $options);
                }

                // target view
                if (isset($target_view)) {
                    $target_view->filterModel($query);
                }

                return $paginate ? $query->paginate($maxCount) : $query->get();
            // many_to_many
            case SearchType::MANY_TO_MANY:
                $query = $child_table->getValueModel()->query();
                RelationTable::setQueryManyMany($query, $this, $child_table, $parent_value_id);

                ///// if has display table, filter display table
                if (isset($display_table)) {
                    $child_table->filterDisplayTable($query, $display_table, $options);
                }

                // target view
                if (isset($target_view)) {
                    $target_view->filterModel($query);
                }

                return $paginate ? $query->paginate($maxCount) : $query->get();
        }

        return null;
    }

    /**
     * Set with query
     *
     * @return void
     */
    public function setQueryWith($query, $custom_view = null)
    {
        if (!method_exists($query, 'with')) {
            return;
        }

        // set query workflow
        if (!is_null(Workflow::getWorkflowByTable($this))) {
            //WorkflowItem::getStatusSubquery($query, $this);
            $query->with(['workflow_value', 'workflow_value.workflow_status']);
        }

        if (
            System::requestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_STATUS_CHECK) === true ||
            (isset($custom_view) &&
            $custom_view->custom_view_filters->contains(function ($custom_view_filter) {
                return $custom_view_filter->view_column_target_id == SystemColumn::WORKFLOW_STATUS()->option()['id'];
            }))) {
            // add query
            WorkflowItem::getStatusSubquery($query, $this);
        }
        // if contains custom_view_filters workflow query
        if (
            System::requestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_FILTER_CHECK) === true ||
            ($custom_view &&
            $custom_view->custom_view_filters->contains(function ($custom_view_filter) {
                return $custom_view_filter->view_column_target_id == SystemColumn::WORKFLOW_WORK_USERS()->option()['id'];
            }))) {
            // add query
            WorkflowItem::getWorkUsersSubQuery($query, $this);
        }
    }


    /**
     * Set selectTable value's. for after calling from select_table object
     */
    public function setSelectTableValues(?Collection $customValueCollection)
    {
        if (empty($customValueCollection)) {
            return;
        }

        $this->getSelectTableColumns()->each(function ($column) use ($customValueCollection) {
            $target_table = $column->select_target_table;

            // get searching value
            $values = $customValueCollection->map(function ($custom_value) use ($column) {
                return array_get($custom_value, "value.{$column->column_name}");
            })->filter()->toArray();
            if (empty($values)) {
                return;
            }

            // value sometimes array, so flatten value. maybe has best way..
            $target_table->setCustomValueModels($values);
        });
    }

    public function setCustomValueModels($ids)
    {
        // value sometimes array, so flatten value. maybe has best way..
        $finds = [];
        foreach (collect($ids)->filter() as $id) {
            foreach (toArray($id) as $v) {
                if (System::hasRequestSession(sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $this->table_name, $v))) {
                    continue;
                }
                $finds[] = $v;
            }
        }

        if (empty($finds)) {
            return;
        }

        $this->getValueModel()->findMany(array_unique($finds))->each(function ($target_value) {
            // set request settion
            $target_value->setValueModel();
        });
    }


    /**
     * Get CustomValues using key. for performance
     *
     * @param array $values
     * @param string $keyName database key name
     * @return array key-value's. "key" is value, "value" matched custom_value.
     */
    public function getMatchedCustomValues($values, $keyName = 'id', $withTrashed = false)
    {
        $result = [];

        $values = array_filter($values);

        foreach (collect($values)->chunk(100) as $chunk) {
            $query = $this->getValueModel()->query();

            if (preg_match("/value\.([a-zA-Z0-9_-]+)/i", $keyName, $matches)) {
                // get custom_column
                $custom_column = CustomColumn::getEloquent($matches[1], $this);
                if ($custom_column->index_enabled) {
                    $databaseKeyName = $this->getIndexColumnName($matches[1]);
                } else {
                    $databaseKeyName = "value->{$matches[1]}";
                }
            } else {
                $databaseKeyName = $keyName;
            }
            $query->whereIn($databaseKeyName, $chunk);

            if ($withTrashed) {
                $query->withTrashed();
            }

            $records = $query->get();

            $records->each(function ($record) use ($keyName, &$result) {
                $matchedKey = array_get($record, $keyName);
                $result[$matchedKey] = $record;
            });
        }

        return $result;
    }

    /**
     * Get search-enabled columns.
     */
    public function getSearchEnabledColumns()
    {
        return CustomColumn::allRecords(function ($custom_column) {
            if ($custom_column->custom_table_id != $this->id) {
                return false;
            }

            if (!$custom_column->index_enabled) {
                return false;
            }

            return true;
        });
    }

    /**
     * Get freeword-search columns.
     */
    public function getFreewordSearchColumns()
    {
        return CustomColumn::allRecords(function ($custom_column) {
            if ($custom_column->custom_table_id != $this->id) {
                return false;
            }

            if (!boolval($custom_column->index_enabled) || !boolval($custom_column->getOption('freeword_search'))) {
                return false;
            }

            return true;
        });
    }

    /**
     * Create Table on Database.
     *
     * @return void
     */
    public function createTable()
    {
        $table_name = getDBTableName($this);
        // if not null
        if (!isset($table_name)) {
            throw new Exception('table name is not found. please tell system administrator.');
        }

        // CREATE TABLE from custom value table.
        if (hasTable($table_name)) {
            return;
        }

        \Schema::createValueTable($table_name);

        System::clearCache();
    }

    public function dropTable()
    {
        $table_name = getDBTableName($this);
        if (!\Schema::hasTable($table_name)) {
            return;
        }
        \Schema::dropIfExists($table_name);

        System::clearCache();
    }
    
    /**
     * Get index column name
     * @param string|CustomTable|array $obj
     * @return string
     */
    public function getIndexColumnName($column_name)
    {
        // get column eloquent
        $column = CustomColumn::getEloquent($column_name, $this);
        // return column name
        return $column->getIndexColumnName();
    }
    
    /**
     * get options for select, multipleselect.
     * But if options count > 100, use ajax, so only one record.
     *
     * @param $custom_view
     */
    public function isGetOptions($options = [])
    {
        extract(array_merge(
            [
                'target_view' => null,
                'custom_column' => null,
                'notAjax' => false,
                'callQuery' => true,
            ],
            $options
        ));

        // if not ajax, return true
        if (boolval($notAjax)) {
            return true;
        }
        // if custom table option's select_load_ajax is true, return false (as ajax).
        elseif (isset($custom_column) && boolval(array_get($custom_column, 'options.select_load_ajax'))) {
            return false;
        }

        // get count table. get Database value directly
        if (boolval($callQuery)) {
            $key = sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_COUNT, $this->id);
            $count = System::requestSession($key, function () {
                return $this->getValueModel()->withoutGlobalScopes([CustomValueModelScope::class])->count();
            });
            // when count > 0, create option only value.
            return $count <= config('exment.select_table_limit_count', 100);
        }

        return true;
    }

    /**
     * Get all accessible users on this table. (only get id, consider performance)
     * *Not check "loginuser"'s permission.
     */
    public function getAccessibleUserIds()
    {
        return $this->getAccessibleUserOrganizationIds(SystemTableName::USER);
    }

    /**
     * Get all accessible organizations on this table. (only get id, consider performance)
     * *Not check "loginuser"'s permission.
     */
    public function getAccessibleOrganizationIds()
    {
        return $this->getAccessibleUserOrganizationIds(SystemTableName::ORGANIZATION);
    }

    /**
     * Get all accessible organizations. (only get id, consider performance)
     */
    protected function getAccessibleUserOrganizationIds($target_table)
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_ACCESSIBLE_TABLE, $target_table, $this->table_name);
        return System::requestSession($key, function () use ($target_table) {
            // $target_table : user or org
            $table = CustomTable::getEloquent($target_table);
            $query = $table->getValueModel()->query();
            $table->filterDisplayTable($query, $this);

            return $query->select(['id'])->pluck('id');
        });
    }

    /**
     * get options for select, multipleselect.
     * But if options count > 100, use ajax, so only one record.
     *
     * *"$this" is the table targeted on options.
     * *"$display_table" is the table user shows on display.
     *
     * @param $selected_value the value that already selected.
     * @param CustomTable $display_table Information on the table displayed on the screen
     * @param boolean $all is show all data. for system role, it's true.
     */
    public function getSelectOptions($options = [])
    {
        extract(array_merge(
            [
                'selected_value' => null,
                'display_table' => null,
                'all' => false,
                'showMessage_ifDeny' => null,
                'filterCallback' => null,
                'target_id' => null,
                'target_view' => null,
                'permission' => null,
                'notAjax' => false,
                'custom_column' => null,
            ],
            $options
        ));

        // if ajax, return []. (set callQuery is false)
        if (!$this->isGetOptions(array_merge(['callQuery' => false], $options))) {
            return $this->getSelectedOptionDefault($selected_value);
        }

        // get query
        $query = $this->getOptionsQuery($options);

        // when count > 100, create option only value.
        if (!$this->isGetOptions($options)) {
            return $this->getSelectedOptionDefault($selected_value);
        }
        
        $items = $query->get()->pluck("label", "id");

        return $this->putSelectedValue($items, $selected_value, $options);
    }

    /**
     * get ajax url for options for select, multipleselect.
     *
     * @param array|CustomTable $table
     * @param $value
     */
    public function getOptionAjaxUrl($options = [])
    {
        // if use options, return null
        if ($this->isGetOptions($options)) {
            return null;
        }

        $display_table = array_get($options, 'display_table');
        return admin_urls_query("webapi", 'data', array_get($this, 'table_name'), "select", ['display_table_id' => $display_table ? $display_table->id : null]);
    }

    /**
     * get options for select and ajax url
     *
     * @param array $options
     * @return array offset 0 is select options, 1 is ajax url
     */
    public function getSelectOptionsAndAjaxUrl($options = [])
    {
        return [
            $this->getSelectOptions($options),
            $this->getOptionAjaxUrl($options)
        ];
    }

    /**
     * put selected value
     */
    protected function putSelectedValue($items, $selected_value, $options = [])
    {
        // if display_table and $this is same, and contains target_id, remove selects
        if (!is_null(array_get($options, 'display_table')) && $this->id == array_get($options, 'display_table.id')
            && !is_null(array_get($options, 'target_id'))) {
            array_forget($items, $options['target_id']);
        }

        if (is_nullorempty($selected_value)) {
            return $items;
        }

        ///// if not contains $selected_value, add
        if ($items->contains(function ($value, $key) use ($selected_value) {
            return $key == $selected_value;
        })) {
            return $items;
        }

        $selected_custom_values = $this->getValueModel()->find((array)$selected_value);
        if (is_nullorempty($selected_custom_values)) {
            return $items;
        }

        $selected_custom_values->each(function ($selected_custom_value) use (&$items) {
            $items->put($selected_custom_value->id, $selected_custom_value->label);
        });

        return $items->unique();
    }

    /**
     * getOptionsQuery. this function uses for count, get, ...
     */
    protected function getOptionsQuery($options = [])
    {
        extract(array_merge(
            [
                'selected_value' => null,
                'display_table' => null,
                'all' => false,
                'showMessage_ifDeny' => null,
                'filterCallback' => null,
                'target_view' => null,
                'permission' => null,
                'notAjax' => false,
                'custom_column' => null,
            ],
            $options
        ));

        if (is_null($display_table)) {
            $display_table = $this;
        } else {
            $display_table = self::getEloquent($display_table);
        }

        // get query.
        $query = $this->getValueModel()->query();
        
        ///// filter display table
        $this->filterDisplayTable($query, $display_table, $options);

        // filter model using view
        if (isset($target_view)) {
            $target_view->filterModel($query);
        }

        if (isset($filterCallback)) {
            $filterCallback($query);
        }

        return $query;
    }

    /**
     * Filtering display table. if $this table is user or org, filtering.
     */
    public function filterDisplayTable($query, $display_table, $options = [])
    {
        extract(array_merge(
            [
                'all' => false,
                'permission' => null,
            ],
            $options
        ));
        $display_table = CustomTable::getEloquent($display_table);

        $table_name = $this->table_name;
        if (isset($display_table) && in_array($table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION]) && in_array($display_table->table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION])) {
            return $query;
        }
        // if $table_name is user or organization, get from getRoleUserOrOrg
        if ($table_name == SystemTableName::USER && !$all) {
            return AuthUserOrgHelper::getRoleUserQueryTable($display_table, $permission, $query);
        }
        if ($table_name == SystemTableName::ORGANIZATION && !$all) {
            return AuthUserOrgHelper::getRoleOrganizationQuery($display_table, $permission, $query);
        }

        return $query;
    }

    /**
     * Get selected option default.
     * If ajax etc, and not set default list, call this function.
     */
    protected function getSelectedOptionDefault($selected_value)
    {
        if (!isset($selected_value)) {
            return [];
        }
        $item = getModelName($this)::find($selected_value);

        if ($item) {
            // check whether $item is multiple value.
            if ($item instanceof Collection) {
                $ret = [];
                foreach ($item as $i) {
                    $ret[$i->id] = $i->label;
                }
                return $ret;
            }
            return [$item->id => $item->label];
        } else {
            return [];
        }
    }

    /**
     * get columns select options.
     * 'append_table': whether appending custom table id in options value
     * 'index_enabled_only': only getting index column
     * 'include_parent': whether getting parent table's column and select table's column
     * 'include_child': whether getting child table's column
     * 'include_system': whether getting system column
     * * 'include_system': whether getting workflow column
     * @param array $selectOptions
     * @param option items
     */
    //public function getColumnsSelectOptions($append_table = false, $index_enabled_only = false, $include_parent = false, $include_child = false, $include_system = true)
    public function getColumnsSelectOptions($selectOptions = [])
    {
        $selectOptions = array_merge(
            [
                'append_table' => false,
                'index_enabled_only' => false,
                'include_parent' => false,
                'include_child' => false,
                'include_column' => true,
                'include_system' => true,
                'include_workflow' => false,
                'include_workflow_work_users' => false,
                'include_condition' => false,
                'ignore_attachment' => false,
            ],
            $selectOptions
        );
        extract($selectOptions);

        $options = [];
        
        if ($include_condition) {
            $this->setColumnOptions(
                $options,
                [],
                null,
                [
                    'include_system' => false,
                    'include_condition' => true,
                ]
            );
        }


        // getting this table's column options
        if ($include_column) {
            $this->setColumnOptions(
                $options,
                $this->custom_columns_cache,
                $this->id,
                [
                    'append_table' => $append_table,
                    'index_enabled_only' => $index_enabled_only,
                    'include_parent' => $include_parent,
                    'include_system' => $include_system,
                    'include_workflow' => $include_workflow,
                    'include_workflow_work_users' => $include_workflow_work_users,
                    'ignore_attachment' => $ignore_attachment,
                ]
            );
        }

        if ($include_parent) {
            ///// get child table columns
            $relations = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->id)->get();
            foreach ($relations as $rel) {
                $parent = array_get($rel, 'parent_custom_table');
                $parent_id = array_get($rel, 'parent_custom_table_id');
                $tablename = array_get($parent, 'table_view_name');
                $this->setColumnOptions(
                    $options,
                    $parent->custom_columns_cache,
                    $parent_id,
                    [
                        'append_table' => $append_table,
                        'index_enabled_only' => $index_enabled_only,
                        'include_parent' => false,
                        'include_system' => $include_system,
                        'table_view_name' => $tablename,
                        'view_pivot_column' => SystemColumn::PARENT_ID,
                        'view_pivot_table' => $this,
                        'ignore_attachment' => $ignore_attachment,
                    ]
                );
            }
            ///// get select table columns
            $select_table_columns = $this->getSelectTableColumns();
            foreach ($select_table_columns as $select_table_column) {
                if ($index_enabled_only && !$select_table_column->index_enabled) {
                    continue;
                }
                $select_table = $select_table_column->column_item->getSelectTable();
                $tablename = array_get($select_table, 'table_view_name');
                $this->setColumnOptions(
                    $options,
                    $select_table->custom_columns_cache,
                    $select_table->id,
                    [
                        'append_table' => $append_table,
                        'index_enabled_only' => $index_enabled_only,
                        'include_parent' => false,
                        'include_system' => $include_system,
                        'table_view_name' => $tablename,
                        'view_pivot_column' => $select_table_column,
                        'view_pivot_table' => $this,
                        'ignore_attachment' => $ignore_attachment,
                    ]
                );
            }
        }

        if ($include_child) {
            ///// get child table columns
            $relations = CustomRelation::with('child_custom_table')->where('parent_custom_table_id', $this->id)->get();
            foreach ($relations as $rel) {
                $child = array_get($rel, 'child_custom_table');
                $child_id = array_get($rel, 'child_custom_table_id');
                $tablename = array_get($child, 'table_view_name');
                $this->setColumnOptions(
                    $options,
                    $child->custom_columns_cache,
                    $child_id,
                    [
                        'append_table' => $append_table,
                        'index_enabled_only' => $index_enabled_only,
                        'include_parent' => false,
                        'include_system' => true,
                        'table_view_name' => $tablename,
                        'ignore_attachment' => $ignore_attachment,
                    ]
                );
            }
            ///// get selected table columns
            $selected_table_columns = $this->getSelectedTableColumns();
            foreach ($selected_table_columns as $selected_table_column) {
                $custom_table = $selected_table_column->custom_table;
                $tablename = array_get($custom_table, 'table_view_name');
                $this->setColumnOptions(
                    $options,
                    $custom_table->custom_columns_cache,
                    $custom_table->id,
                    [
                        'append_table' => $append_table,
                        'index_enabled_only' => $index_enabled_only,
                        'include_parent' => true,
                        'include_system' => true,
                        'table_view_name' => $tablename,
                        'ignore_attachment' => $ignore_attachment,
                    ]
                );
            }
        }
    
        return $options;
    }

    protected function setColumnOptions(&$options, $custom_columns, $table_id, $selectOptions = [])
    {
        $selectOptions = array_merge(
            [
                'append_table' => false,
                'index_enabled_only' => false,
                'include_parent' => false,
                'include_column' => true,
                'include_system' => true,
                'include_workflow' => false,
                'include_workflow_work_users' => false,
                'include_condition' => false,
                'table_view_name' => null,
                'view_pivot_column' => null,
                'view_pivot_table' => null,
                'ignore_attachment' => false,
            ],
            $selectOptions
        );
        extract($selectOptions);

        // get option key
        $optionKeyParams = [
            'view_pivot_column' => $view_pivot_column,
            'view_pivot_table' => $view_pivot_table,
        ];

        /// get system columns
        $setSystemColumn = function ($filter) use (&$options, $table_view_name, $append_table, $table_id) {
            foreach (SystemColumn::getOptions($filter) as $option) {
                $key = static::getOptionKey(array_get($option, 'name'), $append_table, $table_id);
                $value = exmtrans('common.'.array_get($option, 'name'));
                static::setKeyValueOption($options, $key, $value, $table_view_name);
            }
        };

        if ($include_system) {
            $setSystemColumn(['header' => true]);
        }

        if ($include_parent) {
            $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $table_id)->first();
            ///// if this table is child relation(1:n), add parent table
            if (isset($relation)) {
                $key = static::getOptionKey('parent_id', $append_table, $table_id);
                $value = array_get($relation, 'parent_custom_table.table_view_name');
                static::setKeyValueOption($options, $key, $value, $table_view_name);
            }
        }

        if ($include_condition) {
            foreach (ConditionTypeDetail::toArray() as $key => $value) {
                if (in_array($value, [ConditionTypeDetail::COLUMN, ConditionTypeDetail::SYSTEM])) {
                    continue;
                }
                $array[$key] = strtolower($key);
            }
            $options = getTransArrayValue($array, 'condition.condition_type_options');
        }

        if ($include_column) {
            foreach ($custom_columns as $custom_column) {
                // if $index_enabled_only = true and options.index_enabled_only is false, continue
                if ($index_enabled_only && !$custom_column->index_enabled) {
                    continue;
                }
                if ($ignore_attachment && ColumnType::isAttachment($custom_column->column_type)) {
                    continue;
                }
                $key = static::getOptionKey(array_get($custom_column, 'id'), $append_table, $table_id, $optionKeyParams);
                $value = array_get($custom_column, 'column_view_name');
                static::setKeyValueOption($options, $key, $value, $table_view_name);
            }
        }

        if ($include_system) {
            $setSystemColumn(['footer' => true]);

            if ($include_workflow && !is_null(Workflow::getWorkflowByTable($this))) {
                // check contains workflow in table
                $setSystemColumn(['name' => 'workflow_status']);
            }
            if ($include_workflow_work_users && !is_null(Workflow::getWorkflowByTable($this))) {
                // check contains workflow in table
                $setSystemColumn(['name' => 'workflow_work_users']);
            }
        }
    }

    /**
     * get number columns select options. It contains integer, decimal, currency columns.
     * @param array|CustomTable $table
     * @param $selected_value
     */
    public function getSummaryColumnsSelectOptions()
    {
        $options = [];
        
        /// get system columns for summary
        foreach (SystemColumn::getOptions(['summary' => true]) as $option) {
            $key = static::getOptionKey(array_get($option, 'name'), true, $this->id);
            $options[$key] = exmtrans('common.'.array_get($option, 'name'));
        }

        ///// get table columns
        $custom_columns = $this->custom_columns_cache;
        foreach ($custom_columns as $option) {
            $column_type = array_get($option, 'column_type');
            if (ColumnType::isCalc($column_type) || ColumnType::isDateTime($column_type)) {
                $key = static::getOptionKey(array_get($option, 'id'), true, $this->id);
                $options[$key] = array_get($option, 'column_view_name');
            }
        }
        ///// get child table columns for summary
        $relations = CustomRelation::with('child_custom_table')->where('parent_custom_table_id', $this->id)->get();
        foreach ($relations as $rel) {
            $child = array_get($rel, 'child_custom_table');
            $tableid = array_get($child, 'id');
            $tablename = array_get($child, 'table_view_name');
            /// get system columns for summary
            foreach (SystemColumn::getOptions(['summary' => true]) as $option) {
                $key = static::getOptionKey(array_get($option, 'name'), true, $tableid);
                $options[$key] = $tablename . ' : ' . exmtrans('common.'.array_get($option, 'name'));
            }
            $child_columns = $child->custom_columns_cache;
            foreach ($child_columns as $option) {
                $column_type = array_get($option, 'column_type');
                if (ColumnType::isCalc($column_type) || ColumnType::isDateTime($column_type)) {
                    $key = static::getOptionKey(array_get($option, 'id'), true, $tableid);
                    $options[$key] = $tablename . ' : ' . array_get($option, 'column_view_name');
                }
            }
        }
        ///// get selected table columns
        $selected_table_columns = $this->getSelectedTableColumns();
        foreach ($selected_table_columns as $selected_table_column) {
            $custom_table = $selected_table_column->custom_table;
            $tablename = array_get($custom_table, 'table_view_name');
            /// get system columns for summary
            foreach (SystemColumn::getOptions(['summary' => true]) as $option) {
                $key = static::getOptionKey(array_get($option, 'name'), true, $custom_table->id);
                $options[$key] = $tablename . ' : ' . exmtrans('common.'.array_get($option, 'name'));
            }
            foreach ($custom_table->custom_columns_cache as $option) {
                $column_type = array_get($option, 'column_type');
                if (ColumnType::isCalc($column_type) || ColumnType::isDateTime($column_type)) {
                    $key = static::getOptionKey(array_get($option, 'id'), true, $custom_table->id);
                    $options[$key] = $tablename . ' : ' . array_get($option, 'column_view_name');
                }
            }
        }
    
        return $options;
    }

    /**
     * get date columns select options. It contains date, datetime.
     *
     */
    public function getDateColumnsSelectOptions()
    {
        $options = [];

        ///// get table columns
        $custom_columns = $this->custom_columns_cache;
        foreach ($custom_columns as $option) {
            if (!$option->index_enabled) {
                continue;
            }
            $column_type = array_get($option, 'column_type');
            if (ColumnType::isDate($column_type)) {
                $options[static::getOptionKey(array_get($option, 'id'), true, $this->id)] = array_get($option, 'column_view_name');
            }
        }
        
        /// get system date columns
        foreach (SystemColumn::getOptions(['type' => 'datetime']) as $option) {
            $options[static::getOptionKey(array_get($option, 'name'), true, $this->id)] = exmtrans('common.'.array_get($option, 'name'));
        }

        return $options;
    }

    /**
     * get user and organization columns select options.
     *
     */
    public function getUserOrgColumnsSelectOptions($options = [])
    {
        $options = array_merge(
            [
                'append_table' => false,
                'index_enabled_only' => true,
            ],
            $options
        );

        $results = [];

        ///// get table columns
        $custom_columns = $this->custom_columns_cache;
        foreach ($custom_columns as $option) {
            if ($options['index_enabled_only'] && !$option->index_enabled) {
                continue;
            }
            $column_type = array_get($option, 'column_type');
            if (ColumnType::isUserOrganization($column_type)) {
                $results[static::getOptionKey(array_get($option, 'id'), $options['append_table'], $this->id)] = array_get($option, 'column_view_name');
            }
        }

        return $results;
    }

    /**
     * Get relation tables list.
     * It contains search_type(select_table, one_to_many, many_to_many)
     */
    public function getRelationTables($checkPermission = true, $options = [])
    {
        return RelationTable::getRelationTables($this, $checkPermission, $options);
    }

    /**
     * Get CustomValue's model.
     *
     * @param null|int|string $id CustomValue's id
     * @param bool $withTrashed if true, get already trashed value.
     * @return ?CustomValue CustomValue's model.
     */
    public function getValueModel($id = null, $withTrashed = false)
    {
        if ($id instanceof CustomValue) {
            return $id;
        }

        $modelname = getModelName($this);
        if (isset($id)) {
            $key = sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $this->table_name, $id);
            $model = System::requestSession($key, function () use ($id, $withTrashed) {
                return getModelName($this->table_name)::find($id);
            });

            if (!isset($model) && $withTrashed) {
                $model = getModelName($this->table_name)::withTrashed()->find($id);
            }
        } else {
            $model = new $modelname;
        }
        
        return $model;
    }

    /**
     * get array for "makeHidden" function
     */
    public function getMakeHiddenArray()
    {
        return $this->getSearchEnabledColumns()->map(function ($columns) {
            return $columns->getIndexColumnName();
        })->toArray();
    }

    // --------------------------------------------------
    // Permission
    // --------------------------------------------------
    /**
     * whether login user has permission. target is table
     */
    public function hasPermission($role_key = Permission::AVAILABLE_VIEW_CUSTOM_VALUE)
    {
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        $table_name = $this->table_name;
        $role_key = (array)$role_key;

        $user = \Exment::user();
        if (!isset($user)) {
            return false;
        }
        
        $permissions = $user->allPermissions();

        foreach ($permissions as $permission) {
            $role_type = $permission->getRoleType();
            $permission_details = $permission->getPermissionDetails();

            // check system permission
            if (RoleType::SYSTEM == $role_type
                && array_key_exists('system', $permission_details)) {
                return true;
            }

            // if role type is system, and has key
            elseif (RoleType::SYSTEM == $role_type
                && array_keys_exists($role_key, $permission_details)) {
                return true;
            }

            // if role type is table, and match table name
            elseif (RoleType::TABLE == $role_type && $permission->getTableName() == $table_name) {
                // if user has role
                if (array_keys_exists($role_key, $permission_details)) {
                    return true;
                }
            }
        }

        return false;
    }
    
    /**
     * Whether login user has permission about view.
     */
    public function hasViewPermission()
    {
        return !boolval(config('exment.userview_disabled', false)) || $this->hasSystemViewPermission();
    }
    
    /**
     * Whether login user has system permission about view.
     */
    public function hasSystemViewPermission()
    {
        return $this->hasPermission([Permission::CUSTOM_TABLE, Permission::CUSTOM_VIEW]);
    }
    
    /**
     * Whether login user has permission about target id data.
     */
    public function hasPermissionData($id)
    {
        return $this->_hasPermissionData($id, Permission::AVAILABLE_ACCESS_CUSTOM_VALUE, Permission::AVAILABLE_ALL_CUSTOM_VALUE, Permission::AVAILABLE_ACCESS_CUSTOM_VALUE);
    }

    /**
     * Whether login user has edit permission about target id data.
     */
    public function hasPermissionEditData($id)
    {
        return $this->_hasPermissionData($id, Permission::AVAILABLE_ACCESS_CUSTOM_VALUE, Permission::AVAILABLE_ALL_EDIT_CUSTOM_VALUE, Permission::AVAILABLE_EDIT_CUSTOM_VALUE);
    }
    
    /**
     * Whether login user has permission about target id data. (protected function)
     *
     * @$tableRole if user doesn't have these permission, return false
     * @$tableRoleTrue if user has these permission, return true
     */
    protected function _hasPermissionData($id, $tableRole, $tableRoleTrue, $dataRole)
    {
        // if system doesn't use role, return true
        if (!System::permission_available()) {
            return true;
        }

        // if user doesn't have all permissons about target table, return false.
        if (!$this->hasPermission($tableRole)) {
            return false;
        }

        // if user has all edit table, return true.
        if ($this->hasPermission($tableRoleTrue)) {
            return true;
        }

        // if id is null(for create), return true
        if (!isset($id)) {
            return true;
        }

        if (is_numeric($id)) {
            $model = $this->getValueModel($id);
        } else {
            $model = $id;
        }

        if (!isset($model)) {
            return false;
        }

        // else, get model using value_authoritable.
        // if count > 0, return true.
        $rows = $model->getAuthoritable(SystemTableName::USER);
        if ($this->checkPermissionWithPivot($rows, $dataRole)) {
            return true;
        }

        // else, get model using value_authoritable. (only that system uses organization.)
        // if count > 0, return true.
        if (System::organization_available()) {
            $rows = $model->getAuthoritable(SystemTableName::ORGANIZATION);
            if ($this->checkPermissionWithPivot($rows, $dataRole)) {
                return true;
            }
        }

        // else, return false.
        return false;
    }

    /**
     * This custom value in DB.
     * *Remove permission global scope. Only check has or not
     *
     * @return bool if true, has in database.
     */
    public function hasCustomValueInDB($custom_value_id)
    {
        return $this->getValueModel()->withoutGlobalScopes([CustomValueModelScope::class])->where('id', $custom_value_id)->count() > 0;
    }

    /**
     * Get not data error code.
     * If not database, return NO_DATA. If has database, return PERMISSION_DENY
       *
     * @return ErrorCode
     */
    public function getNoDataErrorCode($custom_value_id)
    {
        if ($this->hasCustomValueInDB($custom_value_id)) {
            return ErrorCode::PERMISSION_DENY();
        } else {
            return ErrorCode::DATA_NOT_FOUND();
        }
    }

    /**
     * check permission with pivot
     */
    protected function checkPermissionWithPivot($rows, $role_key)
    {
        if (!isset($rows) || count($rows) == 0) {
            return false;
        }

        if (is_string($role_key)) {
            $role_key = [$role_key];
        }

        foreach ($rows as $row) {
            // check role permissions
            $r = toArray($row);
            $authoritable_type = array_get($r, 'pivot.authoritable_type') ?? array_get($r, 'authoritable_type');
            if (in_array($authoritable_type, $role_key)) {
                return true;
            }
        }

        return false;
    }

    /**
     *
     */
    public function allUserAccessable()
    {
        return !System::permission_available()
            || boolval($this->getOption('all_user_editable_flg'))
            || boolval($this->getOption('all_user_viewable_flg'))
            || boolval($this->getOption('all_user_accessable_flg'));
    }

    /**
     * Set Authoritable for grid. (For performance)
     *
     * @return void
     */
    public function setGridAuthoritable(Collection $custom_values)
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_GRID_AUTHORITABLE, $this->id);

        System::requestSession($key, function () use ($custom_values) {
            // get custom_values_authoritable
            $values = \DB::table('custom_value_authoritables')
                ->where('parent_type', $this->table_name)
                ->whereIn('parent_id', $custom_values->pluck('id')->toArray())
                ->get(['authoritable_user_org_type', 'authoritable_target_id', 'authoritable_type', 'parent_id']);
                    
            return $values;
        });
    }

    /**
     *
     */
    public function formActionDisable($action_type)
    {
        $disable_actions = $this->getOption('form_action_disable_flg', []);
        return in_array($action_type, $disable_actions);
    }

    /**
     *
     */
    public function gridFilterDisable($action_type)
    {
        $grid_filter_disable_flg = System::grid_filter_disable_flg() ?? [];
        return in_array($action_type, $grid_filter_disable_flg);
    }

    /**
     * User can access this custom value
     *
     * @return void
     */
    public function enableAccess()
    {
        if (!$this->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return ErrorCode::PERMISSION_DENY();
        }
        
        return true;
    }

    /**
     * User can view this custom value
     *
     * @return void
     */
    public function enableView()
    {
        if (!$this->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return ErrorCode::PERMISSION_DENY();
        }
        
        return true;
    }

    /**
     * User can create value custom value
     *
     * @return void
     */
    public function enableCreate($checkFormAction = false)
    {
        if (!$this->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return ErrorCode::PERMISSION_DENY();
        }

        if ($checkFormAction && $this->formActionDisable(FormActionType::CREATE)) {
            return ErrorCode::FORM_ACTION_DISABLED();
        }

        return true;
    }

    /**
     * User can edit value custom value
     * *This function checks as table. If have to check as data, please call $custom_value->enableEdit().
     *
     * @return void
     */
    public function enableEdit($checkFormAction = false)
    {
        if (!$this->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return ErrorCode::PERMISSION_DENY();
        }

        if ($checkFormAction && $this->formActionDisable(FormActionType::EDIT)) {
            return ErrorCode::FORM_ACTION_DISABLED();
        }
        
        return true;
    }
    
    /**
     * User can export this custom value
     *
     * @return void
     */
    public function enableExport()
    {
        if (!$this->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return ErrorCode::PERMISSION_DENY();
        }

        if ($this->formActionDisable(FormActionType::EXPORT)) {
            return ErrorCode::FORM_ACTION_DISABLED();
        }
        
        return true;
    }

    /**
     * User can import this custom value
     *
     * @return void
     */
    public function enableImport()
    {
        if (!$this->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return ErrorCode::PERMISSION_DENY();
        }

        if ($this->formActionDisable(FormActionType::IMPORT)) {
            return ErrorCode::FORM_ACTION_DISABLED();
        }

        return true;
    }

    /**
     * User can show trashed value
     *
     * @return void
     */
    public function enableShowTrashed()
    {
        if (!$this->hasPermission([Permission::CUSTOM_TABLE, Permission::CUSTOM_VALUE_VIEW_TRASHED])) {
            return ErrorCode::PERMISSION_DENY();
        }

        return true;
    }

    /**
     * User can view customtable menu button
     *
     * @return void
     */
    public function enableTableMenuButton()
    {
        if (boolval(config('exment.datalist_table_button_disabled', false))) {
            return false;
        }

        if (boolval(config('exment.datalist_table_button_disabled_user', false))) {
            return $this->hasPermission([Permission::CUSTOM_TABLE]);
        }
        
        return true;
    }

    /**
     * User can view customview menu button
     *
     * @return void
     */
    public function enableViewMenuButton()
    {
        if (boolval(config('exment.datalist_view_button_disabled', false))) {
            return false;
        }

        if (boolval(config('exment.datalist_view_button_disabled_user', false))) {
            return $this->hasPermission([Permission::CUSTOM_TABLE]);
        }
        
        return true;
    }

    /**
     *
     */
    public function isOneRecord()
    {
        return $this->getOption('one_record_flg', false);
    }
}
