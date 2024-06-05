<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
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
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\ShowPositionType;
use Exceedone\Exment\Revisionable\Revision;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Exceedone\Exment\Services\FormHelper;
use Exceedone\Exment\Validator\EmptyRule;
use Exceedone\Exment\Validator\CustomValueRule;
use Exceedone\Exment\ColumnItems\WorkflowItem;
use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Validator\ExmentCustomValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Request;

/**
 * Custom Table Class
 *
 * @phpstan-consistent-constructor
 * @property mixed $suuid
 * @property mixed $table_name
 * @property mixed $system_flg
 * @property mixed $showlist_flg
 * @property mixed $table_view_name
 * @property mixed $options
 * @method static int count($columns = '*')
 * @method static ExtendedBuilder orderBy($column, $direction = 'asc')
 * @method static ExtendedBuilder whereNotIn($column, $values, $boolean = 'and')
 * @method static ExtendedBuilder join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false)
 * @method static ExtendedBuilder create(array $attributes = [])
 */
class CustomTable extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\DatabaseJsonOptionTrait;
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

    public function custom_columns(): HasMany
    {
        return $this->hasMany(CustomColumn::class, 'custom_table_id');
    }

    public function custom_views(): HasMany
    {
        return $this->hasMany(CustomView::class, 'custom_table_id')
            ->orderBy('view_type')
            ->orderBy('id');
    }

    public function custom_forms(): HasMany
    {
        return $this->hasMany(CustomForm::class, 'custom_table_id');
    }

    public function custom_operations(): HasMany
    {
        return $this->hasMany(CustomOperation::class, 'custom_table_id');
    }

    public function custom_relations(): HasMany
    {
        return $this->hasMany(CustomRelation::class, 'parent_custom_table_id');
    }

    public function child_custom_relations(): HasMany
    {
        return $this->hasMany(CustomRelation::class, 'child_custom_table_id');
    }

    public function from_custom_copies(): HasMany
    {
        return $this->hasMany(CustomCopy::class, 'from_custom_table_id');
    }

    public function to_custom_copies(): HasMany
    {
        return $this->hasMany(CustomCopy::class, 'to_custom_table_id');
    }

    public function notifies(): HasMany
    {
        return $this->hasMany(Notify::class, 'target_id')
            ->whereIn('notify_trigger', NotifyTrigger::CUSTOM_TABLES())
            ->where('active_flg', 1);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(CustomOperation::class, 'custom_table_id');
    }

    public function custom_form_block_target_tables(): HasMany
    {
        return $this->hasMany(CustomFormBlock::class, 'form_block_target_table_id');
    }

    public function custom_column_multisettings(): HasMany
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id');
    }

    public function custom_form_priorities(): HasManyThrough
    {
        return $this->hasManyThrough(CustomFormPriority::class, CustomForm::class, 'custom_table_id', 'custom_form_id');
    }

    public function workflow_tables(): HasMany
    {
        return $this->hasMany(WorkflowTable::class, 'custom_table_id');
    }

    public function multi_uniques(): HasMany
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id')
            ->where('multisetting_type', MultisettingType::MULTI_UNIQUES);
    }

    public function table_labels(): HasMany
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id')
            ->where('multisetting_type', MultisettingType::TABLE_LABELS);
    }

    public function compare_columns(): HasMany
    {
        return $this->hasMany(CustomColumnMulti::class, 'custom_table_id')
            ->where('multisetting_type', MultisettingType::COMPARE_COLUMNS);
    }

    public function share_settings(): HasMany
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
     * @return array [boolean, string] status, error message.
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
        return [];
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
     * Get Filterd type columns.
     *
     * @param string|array|Collection $column_types
     * @return Collection
     */
    public function getFilteredTypeColumns($column_types)
    {
        return $this->custom_columns_cache->filter(function (CustomColumn $custom_column) use ($column_types) {
            if (is_string($column_types)) {
                $column_types = [$column_types];
            }
            foreach ($column_types as $column_type) {
                if (isMatchString($column_type, $custom_column->column_type)) {
                    return true;
                }
            }

            return false;
        });
    }


    /**
     * Get Columns where select_target_table's id is this table.
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
            $select_target_table = $item->select_target_table;
            return [$key => (!is_null($select_target_table) ? $select_target_table->id : null)];
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
        // get request
        $request = request();
        if (!is_null($request->input('formid'))) {
            $suuid = $request->input('formid');
            // if query has form id, set form.
            return CustomForm::findBySuuid($suuid);
        }

        $custom_value = $this->getValueModel($id);

        if (isset($custom_value)) {
            $custom_form_priorities = $this->custom_form_priorities->sortBy('order');
            foreach ($custom_form_priorities as $custom_form_priority) {
                /** @var CustomFormPriority $custom_form_priority */
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
     * @param bool $skipSelf if true, skip column for relation target is self.
     * @return Collection
     */
    public function getSelectTableColumns($select_target_table = null, bool $skipSelf = false)
    {
        return $this->custom_columns_cache->filter(function ($custom_column) use ($skipSelf, $select_target_table) {
            if (!ColumnType::isSelectTable($custom_column->column_type)) {
                return false;
            }

            $custom_column_target_table = $custom_column->select_target_table;
            if (!isset($custom_column_target_table)) {
                return false;
            }
            // skip if $this->custom_table_id and $this->id (Self relation), return false.
            if ($skipSelf && isMatchString($custom_column_target_table->id, $this->id)) {
                return false;
            }

            // if not filter, return true.
            if (is_null($select_target_table)) {
                return true;
            }
            // filtering select_target_table if set
            $select_target_table = CustomTable::getEloquent($select_target_table);
            if (!isset($select_target_table)) {
                return false;
            }

            return $select_target_table->id == $custom_column_target_table->id;
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
        return $this->getSelectedTableColumns()->mapWithKeys(function ($custom_column, $key) {
            return [$key => $custom_column->custom_table_id];
        })->toArray();
    }

    /**
     * Get key-value items.
     * Key is column index name.
     * Value is custom column.
     * *Ignore self selection*
     *
     * @param bool $skipSelf if true, skip column for relation target is self.
     * @return Collection
     */
    public function getSelectedTableColumns(bool $skipSelf = true)
    {
        return CustomColumn::allRecords(function ($custom_column) use ($skipSelf) {
            // skip if $this->custom_table_id and $this->id (Self relation), return false.
            if ($skipSelf && isMatchString($custom_column->custom_table_id, $this->id)) {
                return false;
            }

            $select_target_table = $custom_column->select_target_table;
            return !empty($select_target_table) && isMatchString($select_target_table->id, $this->id);
        })->mapWithKeys(function ($custom_column) {
            $key = $custom_column->getIndexColumnName();
            return [$key => $custom_column];
        })->filter();
    }


    /**
     * Get unique keys. Contains simple and multiple column settings
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getUniqueColumns()
    {
        $results = collect();
        // First, single column setting ----------------------------------------------------
        $this->custom_columns_cache->filter(function ($custom_column) {
            return boolval(array_get($custom_column->options, 'unique')) && !boolval(array_get($custom_column->options, 'multiple_enabled'));
        })->each(function ($custom_column) use ($results) {
            $results->push([
                // set as "unique1" array
                'unique1' => $custom_column,
            ]);
        });

        // second, multi column setting ----------------------------------------------------
        CustomColumnMulti::allRecords(function ($val) {
            if (array_get($val, 'custom_table_id') != $this->id) {
                return false;
            }

            if ($val->multisetting_type != MultisettingType::MULTI_UNIQUES) {
                return false;
            }

            return true;
        }, false)->each(function ($custom_column_multi) use ($results) {
            $i = [];
            foreach ([1,2,3] as $key) {
                $value = $custom_column_multi->{"unique{$key}"};
                if (is_nullorempty($value)) {
                    continue;
                }
                $custom_column = CustomColumn::getEloquent($value);
                if (boolval(array_get($custom_column->options, 'multiple_enabled'))) {
                    return;
                }
                $i["unique{$key}"] = $custom_column;
            }

            if (is_nullorempty($i)) {
                return;
            }

            $results->push($i);
        });

        return $results;
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
        foreach ($this->custom_views()->withoutGlobalScope('showableViews')->get() as $item) {
            $item->deletingChildren();
        }
        foreach ($this->from_custom_copies as $item) {
            $item->deletingChildren();
        }
        foreach ($this->to_custom_copies as $item) {
            $item->deletingChildren();
        }
        foreach ($this->custom_form_block_target_tables as $item) {
            $item->deletingChildren();
        }
        foreach ($this->operations as $item) {
            $item->deletingChildren();
        }

        /** @var WorkflowValue $item */
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
            $model->custom_views()->withoutGlobalScope('showableViews')->delete();
            $model->custom_forms()->delete();
            $model->custom_columns()->delete();
            $model->custom_relations()->delete();
            $model->from_custom_copies()->delete();
            $model->to_custom_copies()->delete();
            $model->operations()->delete();
            $model->notifies()->delete();

            // delete items
            Menu::where('menu_type', MenuType::TABLE)->where('menu_target', $model->id)->delete();
            Revision::where('revisionable_type', $model->table_name)->delete();

            // delete custom values table
            $model->dropTable();
        });
    }

    /**
     * validation custom_value using each column setting.
     * *If use this function, Please check appendMessages.
     *
     * @param array $value input value
     * @param ?CustomValue $custom_value matched custom_value
     * @return mixed
     */
    public function validateValue($value, $custom_value = null, array $options = [])
    {
        $options = array_merge([
            'systemColumn' => false,  // whether checking system column
            'column_name_prefix' => null,  // appending error key's prefix, and value prefix
            'appendKeyName' => true, // whether appending key name if eror
            'checkCustomValueExists' => true, // whether checking require custom column
            'checkUnnecessaryColumn' => false, // check unnecessaly column contains
            'asApi' => false, // calling as api
            'appendErrorAllColumn' => false, // if error, append error message for all column
            'validateLock' => true, // whether validate update lock
            'calledType' => null, // Whether this validation is called.
        ], $options);
        $systemColumn = $options['systemColumn'];
        $column_name_prefix = $options['column_name_prefix'];
        $appendKeyName = $options['appendKeyName'];
        $checkCustomValueExists = $options['checkCustomValueExists'];
        $checkUnnecessaryColumn = $options['checkUnnecessaryColumn'];
        $asApi = $options['asApi'];
        $appendErrorAllColumn = $options['appendErrorAllColumn'];
        $validateLock = $options['validateLock'];


        // set required column's name for validation.
        $this->setColumnsName($value, $custom_value, $options);

        // get rules for validation
        $rules = $this->getValidateRules($value, $custom_value, $options);

        // get custom attributes
        $customAttributes = $this->getValidateCustomAttributes($systemColumn, $column_name_prefix, $appendKeyName);

        // execute validation
        /** @var ExmentCustomValidator $validator */
        $validator = \Validator::make(array_dot_reverse($value), $rules, [], $customAttributes);

        $errors = $this->validatorUniques($value, $custom_value, $options);

        $errors = array_merge(
            $this->validatorUnnecessaryColumn($value, $options),
            $errors
        );

        $errors = array_merge(
            $this->validatorCompareColumns($value, $custom_value, $options),
            $errors
        );

        $errors = array_merge(
            $this->validatorPlugin($value, $custom_value, ['called_type' => $options['calledType']]),
            $errors
        );

        if ($validateLock) {
            $errors = array_merge(
                $this->validatorLock($value, $custom_value, $asApi),
                $errors
            );
        }

        if (count($errors) > 0) {
            $validator->appendMessages($errors);
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

                foreach ($rules as $rule) {
                    $customAttributes[$rule] = exmtrans("common.$rule") . ($appendKeyName ? "($rule)" : "");
                }
            }
        }

        return $customAttributes;
    }

    /**
     * Set required column
     *
     * @param array $value
     * @param CustomValue|null $custom_value
     * @param array $options
     * @return void
     */
    protected function setColumnsName(&$value, ?CustomValue $custom_value = null, array $options = [])
    {
        $options = array_merge([
            'column_name_prefix' => null,  // appending error key's prefix, and value prefix
            'checkCustomValueExists' => true, // whether checking require custom column
        ], $options);

        if (!boolval($options['checkCustomValueExists'])) {
            return;
        }
        $column_name_prefix = $options['column_name_prefix'];

        foreach ($this->custom_columns_cache as $custom_column) {
            if (!$custom_column->required) {
                continue;
            }

            // if not contains $value[$custom_column->column_name], set as null.
            // if not set, we cannot validate null check because $field->getValidator returns false.
            $isNew = (is_null($custom_value) || !$custom_value->exists);
            if ($isNew && !array_has($value, $column_name_prefix.$custom_column->column_name)) {
                array_set($value, $column_name_prefix.$custom_column->column_name, null);
            }
        }
    }

    /**
     * get validation rules
     */
    public function getValidateRules($value, $custom_value = null, array $options = [])
    {
        $options = array_merge([
            'systemColumn' => false,  // whether checking system column
            'column_name_prefix' => null,  // appending error key's prefix, and value prefix
            'appendKeyName' => true, // whether appending key name if eror
            'checkCustomValueExists' => true, // whether checking require custom column
        ], $options);
        $systemColumn = $options['systemColumn'];
        $column_name_prefix = $options['column_name_prefix'];
        $appendKeyName = $options['appendKeyName'];
        $checkCustomValueExists = $options['checkCustomValueExists'];

        // get fields for validation
        $rules = [];
        $fields = [];

        // get custom attributes
        $customAttributes = $this->getValidateCustomAttributes($systemColumn, $column_name_prefix, $appendKeyName);

        // set required column's name for validation.
        $this->setColumnsName($value, $custom_value, $options);

        foreach ($this->custom_columns_cache as $custom_column) {
            $fields[] = FormHelper::getFormField($this, $custom_column, $custom_value, null, $column_name_prefix, true);
        }

        // create parent type validation array
        if ($systemColumn) {
            $custom_relation_parent = CustomRelation::getRelationByChild($this, RelationType::ONE_TO_MANY);
            $custom_table_parent = ($custom_relation_parent ? $custom_relation_parent->parent_custom_table : null);

            if (!isset($custom_table_parent)) {
                $parent_id_rules = [new EmptyRule()];
            } elseif (!$checkCustomValueExists) {
                $parent_id_rules = ['nullable', 'numeric'];
            } else {
                $parent_id_rules = ['nullable', 'numeric', new CustomValueRule($custom_table_parent)];
            }
            $parent_type_rules = isset($custom_table_parent) ? ['nullable', "in:". $custom_table_parent->table_name] : [new EmptyRule()];

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
            $field_rules = $field_validator->getRules($value);

            // merge rules
            $rules = array_merge($field_rules, $rules);
        }

        return $rules;
    }

    /**
     * Validation whether input contains unnecessary column
     *
     * @param array $input
     * @return array error messages
     */
    public function validatorUnnecessaryColumn($input, array $options = [])
    {
        $options = array_merge([
            'column_name_prefix' => null,  // appending error key's prefix, and value prefix
            'checkUnnecessaryColumn' => false, // whether checking unnecessary custom column
        ], $options);

        if (!$options['checkUnnecessaryColumn']) {
            return [];
        }

        $errors = [];

        $custom_column_names = $this->custom_columns_cache->map(function ($custom_column) {
            return $custom_column->column_name;
        });

        foreach ($input as $key => $value) {
            if (!$custom_column_names->contains($key)) {
                $errors[$options['column_name_prefix'] . $key][] = exmtrans('error.not_contains_column');
            }
        }

        return $errors;
    }


    /**
     * Validate unique single and multiple.
     *
     * @param array $input
     * @param CustomValue|null $custom_value
     * @param array $options
     * @return array
     */
    public function validatorUniques($input, ?CustomValue $custom_value = null, array $options = [])
    {
        $options = array_merge([
            'column_name_prefix' => null,  // appending error key's prefix, and value prefix
            'asApi' => false, // calling as api
            'addValue' => true, // add value. to column name
            'appendErrorAllColumn' => false, // if error, append error message for all column
            'uniqueCheckSiblings' => [], // unique validation Siblings. Please mremove myself values.
            'uniqueCheckIgnoreIds' => [], // ignore ids.
        ], $options);

        $errors = [];
        $prefix = $options['addValue'] ? 'value.' : '';

        // getting custom_table's unique columns(contains simgle, multiple)
        $unique_columns = $this->getUniqueColumns();

        if (is_nullorempty($unique_columns) || count($unique_columns) == 0) {
            return $errors;
        }

        foreach ($unique_columns as $unique_column) {
            $column_keys = [];

            // check input data
            $is_duplicate = collect($options['uniqueCheckSiblings'])
                ->contains(function ($row) use ($input, $unique_column, $prefix, &$column_keys) {
                    foreach ([1,2,3] as $key) {
                        if (is_null($column_id = array_get($unique_column, "unique{$key}"))) {
                            continue;
                        }
                        $column = CustomColumn::getEloquent($column_id);
                        if (is_null($column)) {
                            continue;
                        }
                        // get input value
                        $value = array_get($input, $prefix . $column->column_name);
                        $other = array_get($row, $prefix . $column->column_name);
                        if (is_null($value) && is_null($other)) {
                            continue;
                        }
                        if ($value != $other) {
                            return false;
                        }
                        $column_keys[] = $column;
                    }
                    return !empty($column_keys);
                });

            if (!$is_duplicate) {
                $column_keys = [];
                $query = \DB::table(getDBTableName($this->table_name));
                foreach ([1,2,3] as $key) {
                    if (is_null($column_id = array_get($unique_column, "unique{$key}"))) {
                        continue;
                    }

                    $column = CustomColumn::getEloquent($column_id);
                    if (is_null($column)) {
                        continue;
                    }

                    // get value
                    $value = null;
                    if (array_has($input, $prefix . $column->column_name)) {
                        $value = array_get($input, $prefix . $column->column_name);
                    } elseif (isset($custom_value)) {
                        $value = $custom_value->getValue($column->column_name, ValueType::PURE_VALUE);
                    }
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
                if (collect($column_keys)->filter(function ($column) use ($input, $prefix) {
                    return !is_nullorempty(array_get($input, $prefix . $column->column_name));
                })->count() == 0) {
                    continue;
                }

                if (isset($custom_value) && isset($custom_value->id)) {
                    $query->where('id', '<>', $custom_value->id);
                }

                if (!empty($options['uniqueCheckIgnoreIds'])) {
                    $query->whereNotIn('id', $options['uniqueCheckIgnoreIds']);
                }

                $query->whereNull('deleted_at');

                $is_duplicate = $query->count() > 0;
            }

            if ($is_duplicate) {
                $errorTexts = collect($column_keys)->map(function ($column_key) {
                    return $column_key->column_view_name;
                });
                $errorText = implode(exmtrans('common.separate_word'), $errorTexts->toArray());

                // append error message
                foreach ($column_keys as $column_key) {
                    $errors[$options['column_name_prefix'] . $column_key->column_name] = [exmtrans('custom_value.help.multiple_uniques', $errorText)];
                    if (!$options['appendErrorAllColumn']) {
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
        $options = array_merge([
            'asApi' => false, // calling as api
        ], $options);

        $errors = [];

        // getting custom_table's custom_column_multi_uniques
        $compare_columns = $this->getCompareColumns();

        if (!isset($compare_columns) || count($compare_columns) == 0) {
            return $errors;
        }

        foreach ($compare_columns as $compare_column) {
            // get two values
            $compareResult = $compare_column->compareValue($input, $custom_value, $options);
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
        $display_updated_at = \Carbon\Carbon::parse($input['updated_at']);

        if (is_nullorempty($custom_value)) {
            return [];
        }
        // re-get updated_at value
        $data_updated_at = $this->getValueQuery()->select(['updated_at'])->find($custom_value->id)->updated_at ?? null;
        if (!isset($data_updated_at)) {
            return [];
        }

        $errors = [];
        if (!isMatchString($display_updated_at->format('Y-m-d H:i:s'), $data_updated_at->format('Y-m-d H:i:s'))) {
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
    public function validatorPlugin($input, $custom_value = null, array $options = [])
    {
        return Plugin::pluginValidator($this, [
            'custom_table' => $this,
            'custom_value' => $custom_value,
            'input_value' => array_get($input, 'value'),
            'called_type' => array_get($options, 'called_type'),
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
            $settings = json_decode_ex($settings, true);

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

        $inputs = Arr::except(Request::all(), ['view', '_pjax', '_token', '_method', '_previous_', '_export_', 'format', 'group_key', 'group_view']);

        $parameters = \Exment::user()->getSettingValue($path)?? '[]';
        $parameters = json_decode_ex($parameters, true);

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
            $obj = static::firstRecordCache(function ($table) use ($query_key, $obj) {
                return array_get($table, $query_key) == $obj;
            });
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
            $model = new self();
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
                'searchDocument' => false, // is search document.
                'isApi' => false, // called from API
                'withChildren' => null, // If has value, set "with" to query.
            ],
            $options
        );
        $target_view = $options['target_view'];
        $maxCount = $options['maxCount'];

        $data = [];

        $subQuery = $this->getValueModel()->getSearchQuery($q, $options);
        if (is_nullorempty($subQuery)) {
            return null;
        }
        $dbTableName = getDBTableName($this);

        $mainQuery = $this->getValueQuery()
            ->joinSub($subQuery, 'sub', function ($join) use ($dbTableName) {
                $join->on('sub.id', '=', $dbTableName . '.id');
            });

        // return as paginate
        if ($options['paginate']) {
            // set custom view, sort
            if (isset($target_view)) {
                $target_view->setValueSort($subQuery);
            }

            // get data(only id)
            $paginates = $mainQuery->select("$dbTableName.id")->paginate($maxCount);

            // set eloquent data using ids
            $ids = collect($paginates->items())->map(function ($item) {
                /** @var mixed $item */
                return $item->id;
            });

            // set pager items
            $query = getModelName($this)::whereIn("$dbTableName.id", $ids->toArray());

            // set custom view, sort again.
            if (isset($target_view)) {
                $target_view->resetSearchService();
                $target_view->setValueSort($query);
            }

            // set with
            $this->setQueryWith($query, $target_view);

            // set with relation
            if (isset($options['withChildren'])) {
                $this->setQueryWithRelation($query, $options['withChildren']);
            }

            $paginates->setCollection($query->get());

            if (boolval($options['makeHidden'])) {
                /** @phpstan-ignore-next-line  */
                $data = $paginates->makeHidden($this->getMakeHiddenArray());
                /** @phpstan-ignore-next-line  */
                $paginates->data = $data;
            }

            // append label
            if (boolval($options['getLabel'])) {
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
        $ids = $mainQuery->select("$dbTableName.id")->take($maxCount)->get()->pluck('id');

        $query = getModelName($this)::whereIn("$dbTableName.id", $ids);

        // set custom view, sort again
        if (isset($target_view)) {
            $target_view->resetSearchService();
            $target_view->setValueSort($query);
        }

        // set with
        $this->setQueryWith($query, $target_view);

        // set with relation
        if (isset($options['withChildren'])) {
            $this->setQueryWithRelation($query, $options['withChildren']);
        }

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
                'display_table' => null,
            ],
            $options
        );
        $paginate = $options['paginate'];
        $maxCount = $options['maxCount'];
        $searchColumns = $options['searchColumns'];
        $target_view = $options['target_view'];
        $display_table = $options['display_table'];


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
                $query = $child_table->getValueQuery();
                RelationTable::setQueryOneMany($query, $this, $child_table, $parent_value_id);

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
                    $target_view->filterSortModel($query);
                }

                return $paginate ? $query->paginate($maxCount) : $query->get();
                // many_to_many
            case SearchType::MANY_TO_MANY:
                $query = $child_table->getValueQuery();
                RelationTable::setQueryManyMany($query, $this, $child_table, $parent_value_id);

                ///// if has display table, filter display table
                if (isset($display_table)) {
                    $child_table->filterDisplayTable($query, $display_table, $options);
                }

                // target view
                if (isset($target_view)) {
                    $target_view->filterSortModel($query);
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
        $this->appendSubQuery($query, $custom_view);
    }

    /**
     * Set with query using relation
     *
     * @return void
     */
    protected function setQueryWithRelation($query, $relations)
    {
        if (!method_exists($query, 'with') || !$relations) {
            return;
        }

        if (!is_list($relations)) {
            $relations = [$relations];
        }

        foreach ($relations as $relation) {
            $query->with($relation->getRelationName());
        }
    }

    /**
     * Append to query for filtering workflow
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomView|null $custom_view
     * @return void
     */
    public function appendSubQuery($query, ?CustomView $custom_view)
    {
        $this->appendWorkflowSubQuery($query, $custom_view);

        // if has relations, set with
        if (!is_nullorempty($custom_view)) {
            $relations = $custom_view->custom_view_columns_cache->map(function ($custom_view_column) {
                $column_item = $custom_view_column->column_item;
                if (empty($column_item)) {
                    return null;
                }

                return $column_item->options([
                    'view_pivot_column' => $custom_view_column->view_pivot_column_id ?? null,
                    'view_pivot_table' => $custom_view_column->view_pivot_table_id ?? null,
                ])->getRelation();
            })->filter()->unique();

            if ($relations->count() > 0) {
                $relations->each(function ($r) use ($query) {
                    $query->with($r->getRelationName());
                });
            }
        }
    }


    /**
     * Append to query for filtering workflow
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param CustomView|null $custom_view
     * @return void
     */
    public function appendWorkflowSubQuery($query, ?CustomView $custom_view)
    {
        if ($custom_view && System::requestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_STATUS_CHECK) === true) {
            // add query
            $custom_view->getSearchService()->setRelationJoinWorkflow(SystemColumn::WORKFLOW_STATUS);
        }
        // if contains custom_view_filters workflow query
        if ($custom_view && System::requestSession(Define::SYSTEM_KEY_SESSION_WORLFLOW_FILTER_CHECK) === true) {
            // add query
            $custom_view->getSearchService()->setRelationJoinWorkflow(SystemColumn::WORKFLOW_WORK_USERS);
        }
    }

    /**
     * Set selectTable value's and relations. for after calling from select_table object
     */
    public function setSelectRelationValues(?\Illuminate\Database\Eloquent\Collection $customValueCollection)
    {
        $this->setSelectTableValues($customValueCollection);
        $this->setRelationValues($customValueCollection);
    }


    /**
     * Set selectTable value's. for after calling from select_table object
     *
     * @param \Illuminate\Support\Collection|null $customValueCollection
     * @return void
     */
    public function setSelectTableValues(?\Illuminate\Support\Collection $customValueCollection)
    {
        if (empty($customValueCollection)) {
            return;
        }

        //// for select table
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


    /**
     * Set relation value's. for after calling from select_table object
     */
    public function setRelationValues(?\Illuminate\Database\Eloquent\Collection $customValueCollection)
    {
        if (empty($customValueCollection)) {
            return;
        }

        //// for parent relation
        $relation = CustomRelation::getRelationByChild($this, RelationType::ONE_TO_MANY);
        if (!empty($relation)) {
            // get searching value
            $parent_custom_table = $relation->parent_custom_table;
            $relationName = $relation->getRelationName();
            $customValueCollection->load($relationName);

            $customValueCollection->map(function ($custom_value) use ($relationName) {
                return $custom_value->{$relationName};
            })->filter()->each(function ($custom_value) {
                $custom_value->setValueModel();
            });
        }
    }


    /**
     * query and set custom value's model
     *
     * @param array|Collection $ids
     * @return void
     */
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

        $finds = collect($finds)->filter(function ($find) {
            return is_numeric($find);
        })->toArray();

        if (empty($finds)) {
            return;
        }

        $this->getValueQuery()->whereIn('id', array_unique($finds))->chunk(1000, function ($target_values) {
            $target_values->each(function ($target_value) {
                // set request settion
                $target_value->setValueModel();
            });
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
            $query = $this->getValueQuery();

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
                /** @phpstan-ignore-next-line  */
                $query->withTrashed();
            }

            $records = $query->get();

            $records->each(function ($record) use ($keyName, &$result) {
                $matchedKey = array_get($record, $keyName);
                $result[$matchedKey] = $record;

                if ($record instanceof CustomValue) {
                    $record->setValueModel();
                }
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
        if (is_nullorempty($table_name)) {
            throw new \Exception('table name is not found. please tell system administrator.');
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
     * @param string|CustomColumn $column_name
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
     * @param array $options
     */
    public function isGetOptions($options = [])
    {
        $options = array_merge(
            [
                'target_view' => null,
                'custom_column' => null,
                'notAjax' => false,
                'callQuery' => true,
            ],
            $options
        );

        // if not ajax, return true
        if (boolval($options['notAjax'])) {
            return true;
        }
        // if custom table option's select_load_ajax is true, return false (as ajax).
        elseif (isset($options['custom_column']) && boolval(array_get($options['custom_column'], 'options.select_load_ajax'))) {
            return false;
        }

        // get count table. get Database value directly
        if (boolval($options['callQuery'])) {
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
     * Get all accessible users on this table. (get model)
     * *Not check "loginuser"'s permission.
     */
    public function getAccessibleUsers()
    {
        $target_ids = $this->getAccessibleUserOrganizationIds(SystemTableName::USER);
        return CustomTable::getEloquent(SystemTableName::USER)->getValueModel()->find($target_ids);
    }

    /**
     * Filter all accessible users on this table.
     */
    public function filterAccessibleUsers($userIds): \Illuminate\Support\Collection
    {
        if (is_nullorempty($userIds)) {
            return collect();
        }

        $accessibleUsers = $this->getAccessibleUserIds();

        $result = collect();
        foreach ($userIds as $user) {
            if ($accessibleUsers->contains(function ($accessibleUser) use ($user) {
                return $accessibleUser == $user;
            })) {
                $result->push($user);
            }
        }

        return $result;
    }

    /**
     * Filter all accessible orgs on this table.
     */
    public function filterAccessibleOrganizations($organizationIds): \Illuminate\Support\Collection
    {
        if (is_nullorempty($organizationIds)) {
            return collect();
        }

        $accessibleOrganizations = $this->getAccessibleOrganizationIds();

        $result = collect();
        foreach ($organizationIds as $org) {
            if ($accessibleOrganizations->contains(function ($accessibleOrganization) use ($org) {
                return $accessibleOrganization == $org;
            })) {
                $result->push($org);
            }
        }

        return $result;
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
            $query = $table->getValueQuery();
            $table->filterDisplayTable($query, $this);

            return $query->select(['id'])->pluck('id');
        });
    }


    /**
     * Set select table's field info.
     *
     * @param \Encore\Admin\Form\Field $field
     * @param array $options
     * @return \Encore\Admin\Form\Field
     */
    public function setSelectTableField(\Encore\Admin\Form\Field $field, array $options = []): \Encore\Admin\Form\Field
    {
        $options = array_merge([
            'custom_value' => null, // select custom value, if called custom value's select table
            'custom_column' => null, // target custom column
            'buttons' => [], // append buttons for select field searching etc.
            'label' => null, // almost use 'data-add-select2'.
            'linkage' => null, // linkage \Closure|null info
            'target_view' => null, // target view for filter
            'select_option' => [], // select option's option
            'as_modal' => false, // If true, this select is as modal.
        ], $options);
        $selectOption = $options['select_option'];
        $thisObj = $this;

        // add table info
        $field->attribute(['data-target_table_name' => array_get($this, 'table_name')]);
        /** @phpstan-ignore-next-line */
        $field->buttons($options['buttons']);

        $field->options(function ($value, $field) use ($thisObj, $selectOption) {
            $selectOption['selected_value'] = (!empty($field) ? $field->getOld() : null) ?? $value;
            return $thisObj->getSelectOptions($selectOption);
        });

        $ajax = $this->getOptionAjaxPath($selectOption);
        if (isset($ajax)) {
            // set select2_expand data
            $select2_expand = [];
            if (isset($options['target_view'])) {
                $select2_expand['target_view_id'] = array_get($options['target_view'], 'id');
            }
            if (isset($options['linkage'])) {
                $select2_expand['linkage_column_id'] = $options['linkage']->parent_column->id;
                $select2_expand['column_id'] = $options['linkage']->child_column->id;
                $select2_expand['linkage_value_id'] = $options['linkage']->getParentValueId($options['custom_value']);
            }

            $field->attribute([
                'data-add-select2' => $options['label'],
                'data-add-select2-as-modal' => boolval($options['as_modal']),
                'data-add-select2-ajax' => $ajax,
                'data-add-select2-ajax-webapi' => url_join('data', $thisObj->table_name), // called by changedata
                'data-add-select2-expand' => json_encode($select2_expand),
            ]);
        }

        return $field;
    }


    /**
     * get options for select, multipleselect.
     * But if options count > 100, use ajax, so only one record.
     *
     * *"$this" is the table targeted on options.
     * *"$display_table" is the table user shows on display.
     *
     * @param array $options
     * @return Collection
     */
    public function getSelectOptions($options = []): Collection
    {
        $options = array_merge(
            [
                'selected_value' => null, // the value that already selected.
                'display_table' => null, // Information on the table displayed on the screen
                'all' => false, // is show all data. for system role, it's true
                'showMessage_ifDeny' => null,
                'filterCallback' => null,
                'target_id' => null,
                'target_view' => null,
                'permission' => null,
                'notAjax' => false, // If not ajax(For getting all value), set true.
                'custom_column' => null,
            ],
            $options
        );
        $selected_value = $options['selected_value'];

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
     * get ajax uri for options for select, multipleselect.
     *
     * @param array $options
     * @return string|null url
     */
    public function getOptionAjaxPath($options = [])
    {
        // if use options, return null
        if ($this->isGetOptions($options)) {
            return null;
        }

        $display_table = array_get($options, 'display_table');
        $custom_column = array_get($options, 'custom_column');
        return url_join('data', array_get($this, 'table_name'), "select") . '?' . http_build_query(['column_id' => $custom_column ? $custom_column->id : null, 'display_table_id' => $display_table ? $display_table->id : null]);
    }

    /**
     * get ajax url for options for select, multipleselect.
     *
     * @param array $options
     * @return string|null url
     */
    public function getOptionAjaxUrl($options = [])
    {
        $path = $this->getOptionAjaxPath($options);
        if (!$path) {
            return null;
        }
        return admin_urls('webapi', $path);
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
    protected function putSelectedValue(Collection $items, $selected_value, $options = []): Collection
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

        // checking id
        $isId = !collect(toArray($selected_value))->filter()->contains(function ($s) {
            return !is_numeric($s);
        });
        if (!$isId) {
            return $items;
        }

        $selected_custom_values = $this->getValueModel()->find((array)$selected_value);
        if (is_nullorempty($selected_custom_values)) {
            return $items;
        }

        $selected_custom_values->each(function ($selected_custom_value) use (&$items) {
            /** @var mixed $selected_custom_value */
            $items->put($selected_custom_value->id, $selected_custom_value->label);
        });

        return $items->unique(function ($item, $key) {
            return $key;
        });
    }

    /**
     * getOptionsQuery. this function uses for count, get, ...
     */
    protected function getOptionsQuery($options = [])
    {
        $options = array_merge(
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
        );
        $display_table = $options['display_table'];
        $target_view = $options['target_view'];
        $filterCallback = $options['filterCallback'];


        if (is_null($display_table)) {
            $display_table = $this;
        } else {
            $display_table = self::getEloquent($display_table);
        }

        // get query.
        $query = $this->getValueQuery();

        ///// filter display table
        $this->filterDisplayTable($query, $display_table, $options);

        // filter model using view
        if (isset($target_view)) {
            $target_view->filterSortModel($query);
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
        $options = array_merge([
            'all' => false,
            'permission' => null,
        ], $options);
        $all = $options['all'];
        $permission = $options['permission'];

        $display_table = CustomTable::getEloquent($display_table);

        $table_name = $this->table_name;
        if (isset($display_table) && in_array($table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION]) && in_array($display_table->table_name, [SystemTableName::USER, SystemTableName::ORGANIZATION])) {
            return $query;
        }
        // if $table_name is user or organization, get from getRoleUserOrOrg
        if ($table_name == SystemTableName::USER && !$all) {
            return AuthUserOrgHelper::getRoleUserAndOrgBelongsUserQueryTable($display_table, $permission, $query);
        }
        if ($table_name == SystemTableName::ORGANIZATION && !$all) {
            return AuthUserOrgHelper::getRoleOrganizationQueryTable($display_table, $permission, $query);
        }

        return $query;
    }

    /**
     * Get selected option default.
     * If ajax etc, and not set default list, call this function.
     *
     * @param int|string|null $selected_value
     * @return \Illuminate\Support\Collection
     */
    protected function getSelectedOptionDefault($selected_value): Collection
    {
        if (!isset($selected_value)) {
            return collect([]);
        }
        $item = getModelName($this)::find($selected_value);

        if ($item) {
            // check whether $item is multiple value.
            if ($item instanceof Collection) {
                $ret = [];
                foreach ($item as $i) {
                    $ret[$i->id] = $i->label;
                }
                /** @var Collection $collection */
                $collection =  collect($ret);
                return $collection;
            }
            /** @var Collection $collection */
            $collection = collect([$item->id => $item->label]);
            return $collection;
        } else {
            return collect([]);
        }
    }

    /**
     * get columns select options.
     * 'append_table': whether appending custom table id in options value
     * 'index_enabled_only': only getting index column
     * 'include_parent': whether getting parent table's column and select table's column
     * 'include_child': whether getting child table's column
     * 'include_system': whether getting system column
     * 'include_workflow': whether getting workflow column
     * 'include_form_type': whether getting form type(show, create, edit)
     * @param array $selectOptions
     * @return array|null option items
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
                'include_form_type' => false,
                'ignore_attachment' => false,
                'ignore_autonumber' => false,
                'ignore_multiple' => false,
                'ignore_multiple_refer' => false,
                'ignore_many_to_many' => false,
                'only_system_grid_filter' => false,
                'column_type_filter' => null,
            ],
            $selectOptions
        );
        $append_table = $selectOptions['append_table'];
        $index_enabled_only = $selectOptions['index_enabled_only'];
        $include_parent = $selectOptions['include_parent'];
        $include_child = $selectOptions['include_child'];
        $include_column = $selectOptions['include_column'];
        $include_system = $selectOptions['include_system'];
        $include_workflow = $selectOptions['include_workflow'];
        $include_workflow_work_users = $selectOptions['include_workflow_work_users'];
        $include_condition = $selectOptions['include_condition'];
        $include_form_type = $selectOptions['include_form_type'];
        $ignore_attachment = $selectOptions['ignore_attachment'];
        $ignore_autonumber = $selectOptions['ignore_autonumber'];
        $ignore_multiple = $selectOptions['ignore_multiple'];
        $ignore_multiple_refer = $ignore_multiple || $selectOptions['ignore_multiple_refer'];
        $ignore_many_to_many = $selectOptions['ignore_many_to_many'];
        $only_system_grid_filter = $selectOptions['only_system_grid_filter'];
        $column_type_filter = $selectOptions['column_type_filter'];

        $options = [];

        if ($include_form_type) {
            $this->setColumnOptions(
                $options,
                [],
                null,
                [
                    'include_system' => false,
                    'include_form_type' => true,
                ]
            );
        }
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
                    'ignore_autonumber' => $ignore_autonumber,
                    'ignore_multiple' => $ignore_multiple,
                    'ignore_many_to_many' => $ignore_many_to_many,
                    'only_system_grid_filter' => $only_system_grid_filter,
                    'column_type_filter' => $column_type_filter,
                ]
            );
        }

        if ($include_parent) {
            ///// get child table columns
            $relations = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->id)->get();
            foreach ($relations as $rel) {
                if ($ignore_many_to_many && $rel->relation_type == RelationType::MANY_TO_MANY) {
                    continue;
                }
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
                        'ignore_autonumber' => $ignore_autonumber,
                        'ignore_multiple' => $ignore_multiple,
                        'ignore_many_to_many' => $ignore_many_to_many,
                        'only_system_grid_filter' => $only_system_grid_filter,
                        'column_type_filter' => $column_type_filter,
                    ]
                );
            }
            ///// get select table columns
            $select_table_columns = $this->getSelectTableColumns(null, true);
            foreach ($select_table_columns as $select_table_column) {
                if ($index_enabled_only && !$select_table_column->index_enabled) {
                    continue;
                }
                if ($ignore_multiple_refer && $select_table_column->isMultipleEnabled()) {
                    continue;
                }
                $select_table = $select_table_column->column_item->getSelectTable();
                $column_name = array_get($select_table_column, 'column_view_name');
                $this->setColumnOptions(
                    $options,
                    $select_table->custom_columns_cache,
                    $select_table->id,
                    [
                        'append_table' => $append_table,
                        'index_enabled_only' => $index_enabled_only,
                        'include_parent' => false,
                        'include_system' => $include_system,
                        'table_view_name' => $column_name,
                        'view_pivot_column' => $select_table_column,
                        'view_pivot_table' => $this,
                        'ignore_attachment' => $ignore_attachment,
                        'ignore_autonumber' => $ignore_autonumber,
                        'ignore_multiple' => $ignore_multiple,
                        'ignore_many_to_many' => $ignore_many_to_many,
                        'only_system_grid_filter' => $only_system_grid_filter,
                        'column_type_filter' => $column_type_filter,
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
                        'ignore_autonumber' => $ignore_autonumber,
                        'ignore_multiple' => $ignore_multiple,
                        'ignore_many_to_many' => $ignore_many_to_many,
                        'only_system_grid_filter' => $only_system_grid_filter,
                        'column_type_filter' => $column_type_filter,
                    ]
                );
            }
            ///// get selected table columns
            $selected_table_columns = $this->getSelectedTableColumns();
            foreach ($selected_table_columns as $selected_table_column) {
                $custom_table = $selected_table_column->custom_table;
                $tablename = array_get($selected_table_column, 'column_view_name');
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
                        'ignore_autonumber' => $ignore_autonumber,
                        'ignore_multiple' => $ignore_multiple,
                        'ignore_many_to_many' => $ignore_many_to_many,
                        'view_pivot_column' => $selected_table_column,
                        'view_pivot_table' => $this,
                        'only_system_grid_filter' => $only_system_grid_filter,
                        'column_type_filter' => $column_type_filter,
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
                'include_form_type' => false,
                'table_view_name' => null,
                'view_pivot_column' => null,
                'view_pivot_table' => null,
                'ignore_attachment' => false,
                'ignore_autonumber' => false,
                'ignore_multiple' => false,
                'ignore_many_to_many' => false,
                'only_system_grid_filter' => false,
                'column_type_filter' => null,
            ],
            $selectOptions
        );
        $append_table = $selectOptions['append_table'];
        $index_enabled_only = $selectOptions['index_enabled_only'];
        $include_parent = $selectOptions['include_parent'];
        $include_column = $selectOptions['include_column'];
        $include_system = $selectOptions['include_system'];
        $include_workflow = $selectOptions['include_workflow'];
        $include_workflow_work_users = $selectOptions['include_workflow_work_users'];
        $include_condition = $selectOptions['include_condition'];
        $include_form_type = $selectOptions['include_form_type'];
        $table_view_name = $selectOptions['table_view_name'];
        $view_pivot_column = $selectOptions['view_pivot_column'];
        $view_pivot_table = $selectOptions['view_pivot_table'];
        $ignore_attachment = $selectOptions['ignore_attachment'];
        $ignore_autonumber = $selectOptions['ignore_autonumber'];
        $ignore_multiple = $selectOptions['ignore_multiple'];
        $ignore_many_to_many = $selectOptions['ignore_many_to_many'];
        $only_system_grid_filter = $selectOptions['only_system_grid_filter'];
        $column_type_filter = $selectOptions['column_type_filter'];


        // get option key
        $optionKeyParams = [
            'view_pivot_column' => $view_pivot_column,
            'view_pivot_table' => $view_pivot_table,
        ];

        /// get system columns
        $setSystemColumn = function ($filter) use (&$options, $table_view_name, $append_table, $table_id, $optionKeyParams, $only_system_grid_filter, $column_type_filter) {
            foreach (SystemColumn::getOptions($filter) as $option) {
                if ($only_system_grid_filter && !array_boolval($option, 'grid_filter')) {
                    continue;
                }
                if ($column_type_filter && !$column_type_filter($option)) {
                    continue;
                }
                $key = static::getOptionKey(array_get($option, 'name'), $append_table, $table_id, $optionKeyParams);
                $value = exmtrans('common.'.array_get($option, 'name'));
                static::setKeyValueOption($options, $key, $value, $table_view_name);
            }
        };

        if ($include_system) {
            $setSystemColumn(['header' => true]);
        }

        if ($include_parent) {
            if (!$column_type_filter || $column_type_filter('parent_id')) {
                $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $table_id)->first();
                ///// if this table is child relation(1:n), add parent table
                if (isset($relation)) {
                    if (!$ignore_many_to_many || $relation->relation_type != RelationType::MANY_TO_MANY) {
                        $key = static::getOptionKey('parent_id', $append_table, $table_id);
                        $value = array_get($relation, 'parent_custom_table.table_view_name');
                        static::setKeyValueOption($options, $key, $value, $table_view_name);
                    }
                }
            }
        }

        if ($include_condition) {
            $array = [];
            foreach (ConditionTypeDetail::CONDITION_OPTIONS() as $key => $enum) {
                $array[$enum->getKey()] = $enum->lowerKey();
            }
            $options = array_merge(getTransArrayValue($array, 'condition.condition_type_options'), $options);
        }
        if ($include_form_type) {
            $options = array_merge([ConditionTypeDetail::FORM()->getKey() => exmtrans('condition.condition_type_options.form')], $options);
        }

        if ($include_column) {
            foreach ($custom_columns as $custom_column) {
                // if $index_enabled_only = true and options.index_enabled_only is false, continue
                if ($index_enabled_only && !$custom_column->index_enabled) {
                    continue;
                }
                if ($ignore_multiple && $custom_column->isMultipleEnabled()) {
                    continue;
                }
                if ($ignore_attachment && ColumnType::isAttachment($custom_column->column_type)) {
                    continue;
                }
                if ($ignore_autonumber && $custom_column->column_type == ColumnType::AUTO_NUMBER) {
                    continue;
                }
                if ($column_type_filter && !$column_type_filter($custom_column)) {
                    continue;
                }
                $key = static::getOptionKey(array_get($custom_column, 'id'), $append_table, $table_id, $optionKeyParams);
                $value = array_get($custom_column, 'column_view_name');
                static::setKeyValueOption($options, $key, $value, $table_view_name);
            }
        }

        if ($include_system) {
            $setSystemColumn(['footer' => true]);
        }

        if ($include_workflow && !is_null(Workflow::getWorkflowByTable($this))) {
            // check contains workflow in table
            $setSystemColumn(['name' => 'workflow_status']);
        }
        if ($include_workflow_work_users && !is_null(Workflow::getWorkflowByTable($this))) {
            // check contains workflow in table
            $setSystemColumn(['name' => 'workflow_work_users']);
        }
    }

    /**
     * get number columns select options. It contains integer, decimal, currency columns.
     *
     * @return array options
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

        ///// get parent table columns for summary
        $relations = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->id)->get();
        foreach ($relations as $rel) {
            $parent_custom_table = array_get($rel, 'parent_custom_table');
            $optionKeyParams = [
                'view_pivot_column' => SystemColumn::PARENT_ID,
                'view_pivot_table' => $this,
            ];
            $this->setSummarySelectOptionItem($options, $parent_custom_table, $parent_custom_table->custom_columns_cache, $parent_custom_table->table_view_name, $optionKeyParams);
        }

        ///// get child table columns for summary
        $relations = CustomRelation::with('child_custom_table')->where('parent_custom_table_id', $this->id)->get();
        foreach ($relations as $rel) {
            $child_custom_table = array_get($rel, 'child_custom_table');
            $this->setSummarySelectOptionItem($options, $child_custom_table, $child_custom_table->custom_columns_cache, $child_custom_table->table_view_name);
        }

        ///// get selected table columns
        $selected_table_columns = $this->getSelectedTableColumns();
        foreach ($selected_table_columns as $selected_table_column) {
            $custom_table = $selected_table_column->custom_table;
            $optionKeyParams = [
                'view_pivot_column' => $selected_table_column,
                'view_pivot_table' => $this,
            ];
            $this->setSummarySelectOptionItem($options, $custom_table, $custom_table->custom_columns_cache, $selected_table_column->column_view_name, $optionKeyParams);
        }

        return $options;
    }


    protected function setSummarySelectOptionItem(&$options, $custom_table, $custom_columns, ?string $view_name, $optionKeyParams = [])
    {

        /// get system columns for summary
        foreach (SystemColumn::getOptions(['summary' => true]) as $option) {
            $key = static::getOptionKey(array_get($option, 'name'), true, $custom_table->id, $optionKeyParams);
            $value = exmtrans('common.'.array_get($option, 'name'));
            static::setKeyValueOption($options, $key, $value, $view_name);
        }
        foreach ($custom_columns as $custom_column) {
            $column_type = array_get($custom_column, 'column_type');
            if (ColumnType::isCalc($column_type) || ColumnType::isDateTime($column_type)) {
                $key = static::getOptionKey(array_get($custom_column, 'id'), true, $custom_table->id, $optionKeyParams);
                $value = array_get($custom_column, 'column_view_name');
                static::setKeyValueOption($options, $key, $value, $view_name);
            }
        }
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
     * @param null|int|string|CustomValue $id CustomValue's id
     * @param bool $withTrashed if true, get already trashed value.
     * @return CustomValue|null CustomValue's model.
     */
    public function getValueModel($id = null, $withTrashed = false): ?CustomValue
    {
        if ($id instanceof CustomValue) {
            return $id;
        }

        $modelname = getModelName($this);
        if (isset($id)) {
            $key = sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $this->table_name, $id);
            $model = System::requestSession($key, function () use ($id) {
                return getModelName($this->table_name)::find($id);
            });

            if (!isset($model) && $withTrashed) {
                $model = getModelName($this->table_name)::withTrashed()->find($id);
            }
        } else {
            $model = new $modelname();
        }

        return $model;
    }

    /**
     * Get CustomValue's query.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getValueQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->getValueModel()->query();
    }

    /**
     * Find CustomValue, using only one key.
     *
     * @param string|CustomColumn $column
     * @param mixed $value filtering value
     * @return CustomValue|null
     */
    public function findValue($column, $value): ?CustomValue
    {
        if (is_string($column)) {
            $column = CustomColumn::getEloquent($column, $this);
        }

        /** @var CustomValue|null $result */
        $result = $this->getValueQuery()->where($column->getQueryKey(), $value)->first();
        return $result;
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
                && array_key_exists(Permission::SYSTEM, $permission_details)) {
                return true;
            }

            // check custom table permission(system and table)
            elseif (RoleType::SYSTEM == $role_type
                && array_key_exists(Permission::CUSTOM_TABLE, $permission_details)) {
                return true;
            }

            // if role type is system, and has key
            elseif (RoleType::SYSTEM == $role_type
                && array_keys_exists($role_key, $permission_details)) {
                return true;
            }

            // if role key is import or export, and has system custom_value_edit_all permisson
            elseif (RoleType::SYSTEM == $role_type
                && array_keys_exists(Permission::CUSTOM_VALUE_EDIT_ALL, $permission_details)
                && array_intersect($role_key, Permission::IMPORT_EXPORT)) {
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
        return System::userview_available() || $this->hasSystemViewPermission();
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
     * @param $action_type
     * @return bool
     */
    public function formActionDisable($action_type)
    {
        $disable_actions = $this->getOption('form_action_disable_flg', []);
        return in_array($action_type, $disable_actions);
    }

    /**
     * @param $action_type
     * @return bool
     */
    public function gridFilterDisable($action_type)
    {
        $grid_filter_disable_flg = System::grid_filter_disable_flg() ?? [];
        return in_array($action_type, $grid_filter_disable_flg);
    }

    /**
     * User can access this custom value
     *
     * @return bool|ErrorCode
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
     * @return ErrorCode|true
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
     * @param $checkFormAction
     * @return ErrorCode|true
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
     * This function checks as table. If have to check as data, please call $custom_value->enableEdit().
     *
     * @param $checkFormAction
     * @return ErrorCode|true
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
     * @return ErrorCode|true
     */
    public function enableExport()
    {
        if (!$this->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            return ErrorCode::PERMISSION_DENY();
        }

        if (!$this->hasPermission([Permission::CUSTOM_TABLE, Permission::CUSTOM_VALUE_EXPORT])) {
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
     * @return ErrorCode|true
     */
    public function enableImport()
    {
        if (!$this->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return ErrorCode::PERMISSION_DENY();
        }

        if (!$this->hasPermission([Permission::CUSTOM_TABLE, Permission::CUSTOM_VALUE_IMPORT])) {
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
     * @return ErrorCode|true
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
     * @return bool
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

    /**
     * get show positon for system values
     */
    public function getSystemValuesPosition()
    {
        $positon = $this->getOption('system_values_pos', ShowPositionType::DEFAULT);
        if ($positon == ShowPositionType::DEFAULT) {
            $positon = System::system_values_pos() ?? ShowPositionType::TOP;
        }
        return $positon;
    }

    /**
     * copy this table
     */
    public function copyTable($inputs = null)
    {
        \ExmentDB::transaction(function () use ($inputs) {
            $new_table = $this->replicate(['suuid'])->setRelations([]);
            foreach($inputs as $key => $input) {
                $new_table->{$key} = $input;
            }
            $new_table->saveOrFail();

            $replaceColumns = [];
            foreach ($this->custom_columns_cache as $custom_column) {
                $new_column = $custom_column->replicate(['suuid']);
                $new_column->custom_table_id = $new_table->id;
                $new_column->saveOrFail();
                // stack old column id => new column id
                $replaceColumns[$custom_column->id] = $new_column->id;
            }

            $targetOptions = ['unique1_id', 'unique2_id', 'unique3_id', 'compare_column1_id', 'compare_column2_id', 'table_label_id', 'share_column_id'];

            foreach($this->custom_column_multisettings as $custom_column_multi) {
                $new_setting = $custom_column_multi->replicate(['suuid']);
                $new_setting->custom_table_id = $new_table->id;

                // convert column id
                foreach ($targetOptions as $targetOption) {
                    $oldval = $custom_column_multi->getOption($targetOption);
                    if (isset($oldval) && array_key_exists($oldval, $replaceColumns)) {
                        $new_setting->setOption($targetOption, array_get($replaceColumns, $oldval));
                    }
                }
                $new_setting->saveOrFail();
            }

            return true;
        });

        return [
            'result'  => true,
            'toastr' => sprintf(exmtrans('common.message.success_execute')),
            'redirect' => admin_url('table'),
        ];
    }

    /**
     * validate before value delete.
     */
    public function validateValueDestroy($id)
    {
        $ids = stringToArray($id);

        // check if data referenced
        if ($this->checkReferenced($this, $ids)) {
            return [
                'status'  => false,
                'message' => exmtrans('custom_value.help.reference_error'),
            ];
        }

        $relations = CustomRelation::getRelationsByParent($this, RelationType::ONE_TO_MANY);
        // check if child data referenced
        foreach ($relations as $relation) {
            $child_table = $relation->child_custom_table;
            $list = getModelName($child_table)::whereIn('parent_id', $ids)
                ->where('parent_type', $this->table_name)
                ->pluck('id')->all();
            if ($this->checkReferenced($child_table, $list)) {
                return [
                    'status'  => false,
                    'message' => exmtrans('custom_value.help.reference_error'),
                ];
            }
        }

        foreach ($ids as $target_id) {
            $custom_value = $this->getValueModel($target_id, true);
            if ($custom_value) {
                $res = Plugin::pluginValidateDestroy($custom_value);
                if (!empty($res)) {
                    return $res;
                }
                $custom_value->setValidationDestroy(true);
            }
        }
    }

    /**
     * check if data is referenced.
     */
    protected function checkReferenced($custom_table, $list)
    {
        foreach ($custom_table->getSelectedItems() as $item) {
            $model = getModelName(array_get($item, 'custom_table_id'));
            $column_name = array_get($item, 'column_name');
            // ignore mail_template reference from mail_send_log
            if ($custom_table->table_name == SystemTableName::MAIL_TEMPLATE &&
                $item->custom_table->table_name == SystemTableName::MAIL_SEND_LOG) {
                continue;
            }
            if ($model::whereIn('value->'.$column_name, $list)->exists()) {
                return true;
            }
        }
        return false;
    }
}
