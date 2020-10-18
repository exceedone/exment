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
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Form\Field as ExmentField;
use Exceedone\Exment\Grid\Filter\Where as ExmWhere;
use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;
use Illuminate\Support\Collection;

class SelectTable extends CustomItem
{
    use SelectTrait;

    protected $target_table;
    protected $target_view;
    
    public function __construct($custom_column, $custom_value)
    {
        parent::__construct($custom_column, $custom_value);

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
        if ($this->isMultipleEnabled()) {
            return $v;
        }
        return count($v) == 0 ? null : $v[0];
    }

    /**
     * sortable for grid
     */
    public function sortable()
    {
        if ($this->isMultipleEnabled()) {
            return false;
        }
        return parent::sortable();
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
        
        if (!is_array($v) && preg_match('/\[.+\]/i', $v)) {
            $v = json_decode($v);
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
        } elseif ($html) {
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
    
    protected function getAdminFilterClass()
    {
        if ($this->isMultipleEnabled()) {
            return ExmWhere::class;
        }
        return Filter\Equal::class;
    }

    protected function setAdminOptions(&$field, $form_column_options)
    {
        if (!isset($this->target_table)) {
            return;
        }
        if ($field instanceof ExmentField\Display) {
            return;
        }

        // if this method calls for only validate, return
        if (boolval(array_get($this->options, 'forValidate'))) {
            return;
        }

        $linkage = $this->getLinkage($form_column_options);
        $linkage_expand = !is_null($linkage) ? [
            'parent_select_table_id' => $linkage->parent_column->select_target_table->id,
            'child_select_table_id' => $linkage->child_column->select_target_table->id,
            'search_type' => $linkage->searchType,
            'linkage_value_id' => $linkage->getParentValueId($this->custom_value),
        ] : null;

        // set buttons
        $buttons = [];
        if (!$this->disableEdit($form_column_options) && !boolval(config('exment.select_table_modal_search_disabled', false))) {
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
     * @param ?array $form_column_options
     * @return Linkage|null
     */
    protected function getLinkage($form_column_options)
    {
        // if config "select_relation_linkage_disabled" is true, not callback
        if (boolval(config('exment.select_relation_linkage_disabled', false))) {
            return;
        }

        $relation_filter_target_column_id = array_get($form_column_options, 'relation_filter_target_column_id');
        if (!isset($relation_filter_target_column_id)) {
            return;
        }

        return Linkage::getLinkage($relation_filter_target_column_id, $this->custom_column);
    }

    /**
     * get relation filter callback
     *
     * @return \Closure|null
     */
    protected function getRelationFilterCallback($linkage)
    {
        if (!isset($linkage)) {
            return;
        }

        // get callback
        $callback = function (&$query) use ($linkage) {
            return $linkage->setQueryFilter($query, $linkage->getParentValueId($this->custom_value));
        };
        
        return $callback;
    }
    
    public function getAdminFilterWhereQuery($query, $input)
    {
        $this->getSelectFilterQuery($query, $input);
    }

    protected function setAdminFilterOptions(&$filter)
    {
        if (!isset($this->target_table)) {
            return;
        }
        $target_table = $this->target_table;
        
        $selectOption = $this->getSelectFieldOptions();
        $ajax = $target_table->getOptionAjaxUrl($selectOption);
        
        $filter->select(function ($value) use ($target_table, $selectOption) {
            $selectOption['selected_value'] = $value;
            // get DB option value
            return $target_table->getSelectOptions($selectOption);
        })->ajax($ajax);
    }
    
    protected function setValidates(&$validates, $form_column_options)
    {
        $validates[] = new Validator\SelectTableNumericRule();
        $validates[] = new Validator\CustomValueRule($this->target_table);
    }
    
    /**
     * replace value for import
     *
     * @param mixed $value
     * @param array $setting
     * @return void
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
     * @return void
     */
    public function getKeyAndIdList($datalist, $key)
    {
        if (is_nullorempty($datalist) || is_nullorempty($key)) {
            return [];
        }

        // if has request session
        $sessionkey = sprintf(Define::SYSTEM_KEY_SESSION_IMPORT_KEY_VALUE, $this->custom_table, $this->custom_column->column_name, $key);
        return System::requestSession($sessionkey, function () use ($datalist, $key) {
            // get key and value list
            $keyValueList = collect($datalist)->map(function ($d) {
                return array_get($d, 'value.' . $this->custom_column->column_name);
            })->flatten()->filter()->toArray();

            $target_custom_column = CustomColumn::getEloquent($key, $this->target_table);
            $indexName = $target_custom_column ?? $target_custom_column->index_enabled ? $target_custom_column->getIndexColumnName() : "value->$key";
            $values = $this->target_table->getValueModel()->whereIn($indexName, $keyValueList)->select(['value', 'id'])
                ->get()->mapWithKeys(function ($v) use ($key) {
                    return [array_get($v, "value.$key") => $v->id];
                });

            return $values;
        });
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
        return is_nullorempty($ids) ? null : $ids;
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
     * @param [type] $mark
     * @param [type] $value
     * @param [type] $takeCount
     * @return void
     */
    public function getSearchQueries($mark, $value, $takeCount, $q, $options = [])
    {
        if (!$this->isMultipleEnabled()) {
            return parent::getSearchQueries($mark, $value, $takeCount, $q, $options);
        }

        $query = $this->custom_table->getValueModel()->query();
        $this->getAdminFilterWhereQuery($query, $value);

        $query->take($takeCount)->select('id');

        return [$query];
    }
    
    public function isMultipleEnabled()
    {
        return $this->isMultipleEnabledTrait();
    }
}
