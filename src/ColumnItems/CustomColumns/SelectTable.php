<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Validator;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FilterOption;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;
use Illuminate\Support\Collection;

/**
 *
 */
class SelectTable extends CustomItem
{
    use SelectTrait;

    protected $target_table;
    protected $target_view;

    public function __construct($custom_column, $custom_value, $view_column_target = null)
    {
        parent::__construct($custom_column, $custom_value, $view_column_target);

        $this->target_table = CustomTable::getEloquent(array_get($custom_column, 'options.select_target_table'));
        $this->target_view = CustomView::getEloquent(array_get($custom_column, 'options.select_target_view'));
    }

    public function saving()
    {
        if (is_nullorempty($this->value)) {
            return;
        }

        // convert array or not, using multiple_enabled
        $v = toArray($this->value);
        $v = array_map(function ($n) {
            return strval($n);
        }, $v);
        if ($this->isMultipleEnabled()) {
            return $v;
        }
        return count($v) == 0 ? null : $v[0];
    }

    /**
     * get cast Options
     */
    protected function getCastOptions()
    {
        $type = $this->isMultipleEnabled() ? DatabaseDataType::TYPE_STRING_MULTIPLE : DatabaseDataType::TYPE_INTEGER;
        return [$type, false, []];
    }

    public function getSelectTable()
    {
        return $this->target_table;
    }

    protected function _value($v)
    {
        return $this->getValue($v, false, false);
    }

    protected function _text($v)
    {
        return $this->getValue($v, true, false);
    }

    protected function _html($v)
    {
        return $this->getValue($v, true, true);
    }

    protected function getValue($v, $text, $html)
    {
        if (!isset($this->target_table)) {
            return;
        }

        // if $v is null, return null;
        if (is_null($v)) {
            return null;
        }

        if (!is_array($v) && preg_match('/\[.+\]/i', $v)) {
            $v = json_decode_ex($v);
        }

        $isArray = is_list($v);
        $value = $isArray ? $v : [$v];

        // set custom value cache
        $this->target_table->setCustomValueModels($value);

        $result = [];

        foreach ($value as $v) {
            if (!isset($v)) {
                continue;
            }

            $model = $this->target_table->getValueModel($v);
            if (is_null($model)) {
                if ($this->target_table->hasCustomValueInDB($v)) {
                    $result[] = exmtrans('common.message.no_permission');
                }

                continue;
            }

            // if $model is array multiple, set as array
            if (!($model instanceof Collection)) {
                $model = [$model];
            }

            foreach ($model as $m) {
                if (is_null($m)) {
                    continue;
                }

                $result[] = $this->getResult($m, $text, $html);
            }
        }

        if ($text === false) {
            return count($result) > 0 && !$isArray ? $result[0] : $result;
        } else {
            return implode(exmtrans('common.separate_word'), $result);
        }
    }

    protected function getResult($model, $text, $html)
    {
        if ($text === false) {
            return $model;
        // get text column
        } elseif ($html && !$this->isPublicForm()) {
            return $model->getUrl(true);
        } else {
            return $model->getLabel();
        }
    }

    protected function getAdminFieldClass()
    {
        if ($this->isMultipleEnabled()) {
            return Field\MultipleSelect::class;
        } else {
            return Field\Select::class;
        }
    }

    /**
     * Get grid filter option. Use grid filter, Ex. LIKE search.
     *
     * @return string|null
     */
    protected function getGridFilterOption(): ?string
    {
        return (string)FilterOption::SELECT_EXISTS;
    }

    protected function setAdminOptions(&$field)
    {
        if (!isset($this->target_table)) {
            return;
        }
        if ($field instanceof Field\Display) {
            return;
        }

        // if this method calls for only validate, return
        if (boolval(array_get($this->options, 'forValidate'))) {
            return;
        }

        $linkage = $this->getLinkage();
        $linkage_expand = !is_null($linkage) ? [
            'parent_select_table_id' => $linkage->parent_column->select_target_table->id,
            'child_select_table_id' => $linkage->child_column->select_target_table->id,
            'search_type' => $linkage->searchType,
            'linkage_value_id' => $linkage->getParentValueId($this->custom_value),
        ] : null;

        // If modal, set config as modal
        if (boolval(array_get($this->options, 'as_modal'))) {
            $field->asModal();
        }

        // set buttons
        $buttons = [];
        if ($this->isShowSearchButton($this->form_column_options)) {
            $buttons[] = [
                'label' => trans('admin.search'),
                'btn_class' => 'btn-info',
                'icon' => 'fa-search',
                'attributes' => [
                    'data-widgetmodal_url' => admin_urls_query('data', $this->target_table->table_name, ['modalframe' => 1]),
                    'data-widgetmodal_expand' => json_encode([
                        'target_column_class' => 'class_' . $this->uniqueName(),
                        'target_column_id' => $this->custom_column->id,
                        'target_view_id' => $this->custom_column->getOption('select_target_view'),
                        'display_table_id' => $this->custom_table->id,
                        'linkage' => $linkage_expand,
                        'target_column_multiple' => $field instanceof \Encore\Admin\Form\Field\MultipleSelect ? 1 : 0,
                    ]),
                    'data-widgetmodal_getdata_fieldsgroup' => json_encode(['selected_items' => 'class_' . $this->uniqueName()]),
                ],
            ];
        }

        $callback = $this->getRelationFilterCallback($linkage);
        $selectOption = $this->getSelectFieldOptions($callback);

        $this->target_table->setSelectTableField($field, [
            'custom_value' => $this->custom_value, // select custom value, if called custom value's select table
            'custom_column' => $this->custom_column, // target custom column
            'buttons' => $buttons, // append buttons for select field searching etc.
            'label' => $this->label(), // almost use 'data-add-select2'.
            'linkage' => $linkage, // linkage \Closure|null info
            'target_view' => $this->target_view,
            'select_option' => $selectOption, // select option's option
            'as_modal' => array_get($this->options, 'as_modal'),
        ]);
    }

    public function getSelectOptions($value, $field, array $selectOption = [])
    {
        $selectOption = array_merge(
            $this->getSelectFieldOptions(),
            $selectOption
        );
        $selectOption['selected_value'] = (!empty($field) ? $field->getOld() : null) ?? $value;

        // get DB option value
        return $this->target_table->getSelectOptions($selectOption);
    }

    /**
     * Get select field option, for getting selectitem, and ajax.
     *
     * @param \Closure|null $callback
     * @return array
     */
    protected function getSelectFieldOptions($callback = null)
    {
        return [
            'custom_column' => $this->custom_column,
            'display_table' => $this->custom_column->custom_table_cache,
            'filterCallback' => $callback,
            'target_view' => $this->target_view,
            'target_id' => isset($this->custom_value) ? $this->custom_value->id : null,
            'all' => $this->custom_column->isGetAllUserOrganization(),
        ];
    }

    /**
     * Get relation filter object
     *
     * @return Linkage|null|void
     */
    protected function getLinkage()
    {
        // if config "select_relation_linkage_disabled" is true, not callback
        if (boolval(config('exment.select_relation_linkage_disabled', false))) {
            return null;
        }

        $relation_filter_target_column_id = array_get($this->form_column_options, 'relation_filter_target_column_id');
        if (is_nullorempty($relation_filter_target_column_id)) {
            return null;
        }

        return Linkage::getLinkage($relation_filter_target_column_id, $this->custom_column);
    }

    /**
     * Whether showing Search modal button
     *
     * @param mixed $form_column_options
     * @return bool
     */
    protected function isShowSearchButton($form_column_options): bool
    {
        if ($this->isPublicForm()) {
            return false;
        }
        if ($this->disableEdit()) {
            return false;
        }
        if (boolval(config('exment.select_table_modal_search_disabled', false))) {
            return false;
        }
        if (boolval(array_get($this->options, 'as_modal'))) {
            return false;
        }
        if (isset($this->target_table) && $this->target_table->isOneRecord()) {
            return false;
        }

        return true;
    }

    /**
     * get relation filter callback
     *
     * @return \Closure|null|void
     */
    protected function getRelationFilterCallback($linkage)
    {
        if (!isset($linkage)) {
            return null;
        }

        // get callback
        $callback = function (&$query) use ($linkage) {
            return $linkage->setQueryFilter($query, $this->getRelationParentValue($linkage));
        };

        return $callback;
    }


    /**
     * Get relation parent value. Consider other form field's default.
     * (1)parent field's value.
     * (2)parent field's default value.
     * (3)null
     *
     * @param Linkage $linkage
     * @return mixed
     */
    protected function getRelationParentValue($linkage)
    {
        $value = $linkage->getParentValueId($this->custom_value);
        if (!is_nullorempty($value)) {
            return $value;
        }

        $parent_column = $linkage->parent_column;
        // get parent form column using column id
        $parent_form_column = collect($this->other_form_columns)->filter(function ($other_form_column) use ($parent_column) {
            return array_get($other_form_column, 'form_column_type') == FormColumnType::COLUMN && array_get($other_form_column, 'form_column_target_id') == $parent_column->id;
        })->first();
        // check has default value
        if (isset($parent_form_column) && !is_nullorempty($value = $parent_form_column->column_item->getDefaultValue())) {
            return $value;
        }

        return null;
    }

    protected function setAdminFilterOptions(&$filter)
    {
        if (!isset($this->target_table)) {
            return;
        }
        $target_table = $this->target_table;

        $selectOption = $this->getSelectFieldOptions();
        $ajax = $target_table->getOptionAjaxUrl($selectOption);

        $filter->multipleSelect(function ($value) use ($target_table, $selectOption) {
            $selectOption['selected_value'] = $value;
            // get DB option value
            return $target_table->getSelectOptions($selectOption);
        })->ajax($ajax);
    }

    protected function setValidates(&$validates)
    {
        $validates[] = new Validator\SelectTableNumericRule();
        $validates[] = new Validator\CustomValueRule($this->target_table, $this->custom_column->getOption('select_target_view'));
    }

    protected function getRemoveValidates()
    {
        return [\Encore\Admin\Validator\HasOptionRule::class];
    }

    /**
     * replace value for import
     *
     * @param mixed $value
     * @param array $setting
     * @return array
     */
    public function getImportValue($value, $setting = [])
    {
        $result = true;
        $message = null;

        $isSingle = false;
        if (!is_array($value)) {
            $isSingle = true;
            $value = [$value];
        }

        foreach ($value as &$v) {
            // get id from datalist
            if (array_has($setting, 'datalist') && !is_null($target_column_name = array_get($setting, 'target_column_name'))) {
                $target_value = array_get($setting['datalist'], $v);

                if (!isset($target_value)) {
                    $result = false;
                } else {
                    $v = $target_value;
                }
            } elseif (!isset($this->target_table)) {
                $result = false;
            } elseif (is_null($target_column_name = array_get($setting, 'target_column_name'))) {
                // if get as id and not numeric, set error
                if (!is_numeric($v)) {
                    $result = false;
                    $message = trans('validation.integer', ['attribute' => $this->label()]);
                }
            } else {
                // get target value
                $target_custom_column = CustomColumn::getEloquent($target_column_name, $this->target_table);
                if (isset($target_custom_column)) {
                    $target_value = $this->target_table->getValueModel()->where($target_custom_column->getQueryKey(), $v)->select(['id'])->first();
                }

                if (!isset($target_value)) {
                    $result = false;
                } else {
                    $v = $target_value->id;
                }
            }
        }

        if ($isSingle && count($value) == 1) {
            $value = $value[0];
        }

        return [
            'result' => $result,
            'value' => $value,
            'message' => $message,
        ];
    }

    /**
     * Get Key and Id List
     *
     * @param array $datalist
     * @param string $key
     * @return array
     */
    public function getKeyAndIdList($datalist, $key)
    {
        if (is_nullorempty($datalist) || is_nullorempty($key)) {
            return [];
        }

        // if has request session
        $sessionkey = sprintf(Define::SYSTEM_KEY_SESSION_IMPORT_KEY_VALUE, $this->custom_table->table_name, $this->custom_column->column_name, $key);
        return System::requestSession($sessionkey, function () use ($datalist, $key) {
            // get key and value list
            $keyValueList = collect($datalist)->map(function ($d) {
                $val = array_get($d, 'value.' . $this->custom_column->column_name);
                if (ColumnType::isMultipleEnabled($this->custom_column->column_type)
                    && $this->custom_column->getOption('multiple_enabled')) {
                    return explode(",", $val);
                } else {
                    return $val;
                }
            })->flatten()->filter()->toArray();

            $target_custom_column = CustomColumn::getEloquent($key, $this->target_table);
            $values = [];
            if ($target_custom_column) {
                $indexName = $target_custom_column->index_enabled ? $target_custom_column->getIndexColumnName() : "value->$key";
                $values = $this->target_table->getValueModel()->whereIn($indexName, $keyValueList)->select(['value', 'id'])
                    ->get()->mapWithKeys(function ($v) use ($key) {
                        return [array_get($v, "value.$key") => $v->id];
                    });
            }

            return $values;
        });
    }

    /**
     * Get pure value by query string
     *
     * @return mixed
     */
    protected function getPureValueByQuery($default)
    {
        if (boolval(config('exment.publicform_urlparam_suuid', false))) {
            $select_table = $this->custom_column->select_target_table ?? null;
            if (!isset($select_table)) {
                return null;
            }

            $query = $select_table->getValueModel()::query();
            $query->where('suuid', $default);

            $ids = $query->pluck('id');
            return is_nullorempty($ids) ? null : ($this->isMultipleEnabled() ? $ids->toArray() : $ids->first());
        } else {
            return parent::getPureValueByQuery($default);
        }
    }

    /**
     * Get pure value. If you want to change the search value, change it with this function.
     *
     * @param string $label
     * @return ?string string:matched, null:not matched
     */
    public function getPureValue($label)
    {
        $select_table = $this->custom_column->select_target_table ?? null;
        if (!isset($select_table)) {
            return null;
        }

        // get label columns
        $labelColumns = $select_table->getLabelColumns();

        // not support table_label_format
        if (is_string($labelColumns)) {
            return null;
        }

        $use_table_label_id = boolval($select_table->getOption('use_label_id_flg', false));

        // searching select table query
        $query = $select_table->getValueModel()::query();
        $executeSearch = false;


        // not has label column, get custom column's first.
        if (!$use_table_label_id && count($labelColumns) == 0) {
            if ($this->setSelectTableQuery($query, $select_table->custom_columns_cache->first(), $label)) {
                $executeSearch = true;
            }
        }
        // only single search column, search
        elseif (!$use_table_label_id && count($labelColumns) == 1) {
            if ($this->setSelectTableQuery($query, array_get($labelColumns[0], 'table_label_id'), $label)) {
                $executeSearch = true;
            }
        } else {
            // split $label space. zen-han
            $label = str_replace('　', ' ', $label);
            $label = preg_replace('/\s+/', ' ', $label);
            $items = preg_split('/[\s|\x{3000}]+/u', $label);

            $searchAsId = $use_table_label_id && substr($items[0], 0, 1) == '#';

            if ($searchAsId) {
                $searchId = substr($items[0], 1);
                $query->where('id', $searchId);

                $executeSearch = true;
            }

            $labelColumnIndex = 0;
            for ($i = ($searchAsId ? 1 : 0); $i < count($items); $i++) {
                if (count($labelColumns) <= $labelColumnIndex) {
                    return null;
                }

                if ($this->setSelectTableQuery($query, array_get($labelColumns[$labelColumnIndex++], 'table_label_id'), $items[$i])) {
                    $executeSearch = true;
                }
            }
        }

        if (!$executeSearch) {
            return null;
        }

        // get value
        $ids = $query->pluck('id');
        return is_nullorempty($ids) ? null : ($this->isMultipleEnabled() ? $ids->toArray() : $ids->first());
    }

    protected function setSelectTableQuery($query, $custom_column_id, $value)
    {
        $custom_column = CustomColumn::getEloquent($custom_column_id);
        if (!isset($custom_column)) {
            return false;
        }

        $column_item = $custom_column->column_item;
        if (!isset($column_item)) {
            return false;
        }

        $searchValue = $column_item->getPureValue($value);
        if (!isset($searchValue)) {
            $searchValue = $value;
        }

        if (System::filter_search_type() == FilterSearchType::ALL) {
            $searchValue = '%' . $searchValue . '%';
        } else {
            $searchValue = $searchValue . '%';
        }

        $name = $custom_column->getQueryKey();
        $query->where($name, 'LIKE', $searchValue);

        return true;
    }

    /**
     * Get Search queries for free text search
     *
     * @param string $mark
     * @param mixed $value
     * @param int $takeCount
     * @param string $q
     * @param array $options
     * @return array
     */
    public function getSearchQueries($mark, $value, $takeCount, $q, $options = [])
    {
        if (!$this->isMultipleEnabled()) {
            return parent::getSearchQueries($mark, $value, $takeCount, $q, $options);
        }

        // If multiple enabled,
        $query = $this->custom_table->getValueQuery();
        $this->getAdminFilterWhereQuery($query, $value);

        $query->take($takeCount)->select('id');

        return [$query];
    }

    public function isMultipleEnabled()
    {
        return $this->isMultipleEnabledTrait();
    }
    protected function getFilterFieldClass()
    {
        if ($this->isMultipleEnabled()) {
            return Field\MultipleSelect::class;
        } else {
            return Field\Select::class;
        }
    }


    /**
     * Set Custom Column Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnOptionForm(&$form)
    {
        $this->setCustomColumnOptionFormSelectTable($form);
    }


    protected function setCustomColumnOptionFormSelectTable(&$form, string $user_org = null)
    {
        $id = request()->route('id');
        $column_type = isset($id) ? CustomColumn::getEloquent($id)->column_type : null;
        // define select-target table

        if (is_nullorempty($user_org)) {
            if (!isset($id)) {
                $form->select('select_target_table', exmtrans("custom_column.options.select_target_table"))
                ->help(exmtrans("custom_column.help.select_target_table"))
                ->required()
                ->options(function ($select_table) {
                    $options = CustomTable::filterList()->whereNotIn('table_name', [SystemTableName::USER, SystemTableName::ORGANIZATION])->pluck('table_view_name', 'id')->toArray();
                    return $options;
                })
                ->attribute([
                    'data-linkage' => json_encode([
                        'options_select_import_column_id' => [
                            'url' => admin_url('webapi/table/indexcolumns'),
                            'text' => 'column_view_name',
                        ],
                        'options_select_export_column_id' => [
                            'url' => admin_url('webapi/table/columns'),
                            'text' => 'column_view_name',
                        ],
                        'options_select_target_view' => [
                            'url' => admin_url('webapi/table/filterviews'),
                            'text' => 'view_view_name',
                        ]
                    ]),
                ]);
            } else {
                // if already set, display only
                $form->display('select_target_table', exmtrans("custom_column.options.select_target_table"))
                    ->displayText(function ($val) {
                        if (!isset($val)) {
                            return $val;
                        }
                        $custom_table = CustomTable::getEloquent($val);
                        if (!isset($custom_table)) {
                            return null;
                        }
                        return $custom_table->table_view_name;
                    });
                $form->hidden('select_target_table');
            }
        }

        // define select-target table view
        $form->select('select_target_view', exmtrans("custom_column.options.select_target_view"))
            ->help(exmtrans("custom_column.help.select_target_view"))
            ->options(function ($value, $field) use ($column_type) {
                if (is_nullorempty($field)) {
                    return [];
                }

                // check $value or $field->data()
                $custom_table = null;
                if (isset($value)) {
                    $custom_view = CustomView::getEloquent($value);
                    $custom_table = $custom_view ? $custom_view->custom_table : null;
                } elseif (!is_nullorempty($field->data())) {
                    $custom_table = CustomTable::getEloquent(array_get($field->data(), 'select_target_table'));
                }

                if (!isset($custom_table)) {
                    if (!ColumnType::isUserOrganization($column_type)) {
                        return [];
                    }
                    $custom_table = CustomTable::getEloquent($column_type);
                }

                if (!isset($custom_table)) {
                    return [];
                }

                return CustomTable::getEloquent($custom_table)->custom_views
                    ->filter(function ($value) {
                        return array_get($value, 'view_kind_type') == ViewKindType::FILTER;
                    })->pluck('view_view_name', 'id');
            });

        $custom_table = $this->custom_table;
        $manual_url = getManualUrl('data_import_export?id='.exmtrans('custom_column.help.select_import_column_id_key'));
        $form->select('select_import_column_id', exmtrans("custom_column.options.select_import_column_id"))
            ->help(exmtrans("custom_column.help.select_import_column_id", $manual_url))
            ->options(function ($select_table, $field) use ($id, $custom_table, $user_org) {
                return SelectTable::getImportExportColumnSelect($custom_table, $select_table, $field, $id, $user_org);
            });

        $form->select('select_export_column_id', exmtrans("custom_column.options.select_export_column_id"))
            ->help(exmtrans("custom_column.help.select_export_column_id"))
            ->options(function ($select_table, $field) use ($id, $custom_table, $user_org) {
                return SelectTable::getImportExportColumnSelect($custom_table, $select_table, $field, $id, $user_org, false);
            });

        $form->switchbool('select_load_ajax', exmtrans("custom_column.options.select_load_ajax"))
            ->help(exmtrans("custom_column.help.select_load_ajax", config('exment.select_table_limit_count', 100)))
            ->default("0");

        // enable multiple
        $form->switchbool('multiple_enabled', exmtrans("custom_column.options.multiple_enabled"));
    }


    /**
     * Get import export select list
     *
     * @return array
     */
    protected static function getImportExportColumnSelect($custom_table, $value, $field, $id, $column_type, $isImport = true)
    {
        if (is_nullorempty($field)) {
            return [];
        }

        // whether column_type is user or org
        if (!is_null(old('column_type'))) {
            $model = CustomColumn::getEloquent(old('column_type'), $custom_table);
            /** @phpstan-ignore-next-line Right side of || is always false.  */
        } elseif (isset($id) || old('column_type')) {
            $model = CustomColumn::getEloquent($id);
        }
        if (isset($model)) {
            $column_type = $model->column_type;
        }
        if (isset($column_type) && in_array($column_type, [ColumnType::USER, ColumnType::ORGANIZATION])) {
            return CustomTable::getEloquent($column_type)->getColumnsSelectOptions([
                'index_enabled_only' => $isImport,
                'include_system' => false,
            ]) ?? [];
        }

        // get seletct target table
        if (isset($value)) {
            $custom_column = CustomColumn::getEloquent($value);
            $custom_table = $custom_column ? CustomTable::getEloquent($custom_column) : null;
        } elseif (!is_nullorempty($field->data())) {
            $custom_table = CustomTable::getEloquent(array_get($field->data(), 'select_target_table'));
        }
        if (!isset($custom_table)) {
            return [];
        }

        return $custom_table->getColumnsSelectOptions([
            'index_enabled_only' => $isImport,
            'include_system' => false,
        ]) ?? [];
    }
}
