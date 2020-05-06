<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Validator;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Form\Field as ExmentField;
use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;
use Illuminate\Support\Collection;

class SelectTable extends CustomItem
{
    protected $target_table;
    protected $target_view;
    
    public function __construct($custom_column, $custom_value)
    {
        parent::__construct($custom_column, $custom_value);

        $this->target_table = CustomTable::getEloquent(array_get($custom_column, 'options.select_target_table'));
        $this->target_view = CustomView::getEloquent(array_get($custom_column, 'options.select_target_view'));
    }

    public function getSelectTable()
    {
        return $this->target_table;
    }

    public function value()
    {
        return $this->getValue(false, false);
    }

    public function text()
    {
        return $this->getValue(true, false);
    }

    public function html()
    {
        return $this->getValue(true, true);
    }

    protected function getValue($text, $html)
    {
        if (!isset($this->target_table)) {
            return;
        }
        
        if (!is_array($this->value()) && preg_match('/\[.+\]/i', $this->value())) {
            $this->value = json_decode($this->value());
        }

        $isArray = is_array($this->value());
        $value = $isArray ? $this->value() : [$this->value()];
        $result = [];

        // if can select table relation, set value
        if (!is_null($select_table_value = array_get($this->custom_value, $this->custom_column->getSelectTableRelationName()))) {
            $result[] = $this->getResult($select_table_value, $text, $html);
        } else {
            foreach ($value as $v) {
                if (!isset($v)) {
                    continue;
                }
                
                $model = $this->target_table->getValueModel($v);
                if (is_null($model)) {
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
        if (boolval(array_get($this->custom_column, 'options.multiple_enabled'))) {
            return Field\MultipleSelect::class;
        } else {
            return Field\Select::class;
        }
    }
    
    protected function getAdminFilterClass()
    {
        if (boolval($this->custom_column->getOption('multiple_enabled'))) {
            return Filter\Where::class;
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

        // add table info
        $field->attribute(['data-target_table_name' => array_get($this->target_table, 'table_name')]);

        // add view info
        if (isset($this->target_view)) {
            $field->attribute(['data-select2_expand' => json_encode([
                    'target_view_id' => array_get($this->target_view, 'id')
                ])
            ]);
        }

        // if this method calls for only validate, return
        if (boolval(array_get($this->options, 'forValidate'))) {
            return;
        }

        $relationColumn = collect($this->custom_column->custom_table
            ->getSelectTableRelationColumns())
            ->first(function ($relationColumn) {
                return array_get($relationColumn, 'child_column')->id == $this->custom_column->id;
            });

        // get callback
        //TODO:refactor
        $callback = null;
        // if config "select_relation_linkage_disabled" is true, not callback
        if (boolval(config('exment.select_relation_linkage_disabled', false))) {
        } elseif (isset($relationColumn)) {
            $parent_value = $this->custom_column->custom_table->getValueModel($this->id);
            $parent_v = array_get($parent_value, 'value.' . $relationColumn['parent_column']->column_name);
            $parent_target_table_id = $relationColumn['parent_column']->select_target_table->id;
            $parent_target_table_name = $relationColumn['parent_column']->select_target_table->table_name;
                
            if ($relationColumn['searchType'] == SearchType::ONE_TO_MANY) {
                $callback = function (&$query) use ($parent_v, $parent_target_table_name) {
                    $query = $query->where("parent_id", $parent_v)->where('parent_type', $parent_target_table_name);
                    return $query;
                };
            }
            // elseif ($relationColumn['searchType'] == SearchType::MANY_TO_MANY) {
            //     $callback = function (&$query) use ($parent_v, $parent_target_table_name, $relationColumn) {
            //         $relation = $relationColumn['relation'];
            //         $query->whereHas($relation->getRelationName(), function ($query) use($relation, $parent_v) {
            //             $query->where($relation->getRelationName() . '.parent_id', $parent_v);
            //         });
            //         return $query;
            //     };
            // }
            else {
                $child_target_table_id = $relationColumn['child_column']->select_target_table->id;
                if ($parent_target_table_id != $child_target_table_id) {
                    $searchColumn = $relationColumn['child_column']->select_target_table->custom_columns()
                        ->where('column_type', ColumnType::SELECT_TABLE)
                        ->whereIn('options->select_target_table', [strval($parent_target_table_id), intval($parent_target_table_id)])
                        ->first();
                    if (isset($searchColumn)) {
                        $callback = function (&$query) use ($parent_v, $searchColumn) {
                            $query = $query->where("value->{$searchColumn->column_name}", $parent_v);
                            return $query;
                        };
                    }
                }
            }
        }

        $selectOption = [
            'custom_column' => $this->custom_column,
            'display_table' => $this->custom_column->custom_table,
            'filterCallback' => $callback,
            'target_view' => $this->target_view,
            'target_id' => isset($this->custom_value) ? $this->custom_value->id : null,
        ];

        $field->options(function ($value) use ($selectOption, $relationColumn) {
            $selectOption['selected_value'] = $value;

            // get DB option value
            return $this->target_table->getSelectOptions($selectOption);
        });
        $ajax = $this->target_table->getOptionAjaxUrl($selectOption);
        if (isset($ajax)) {
            $field->attribute([
                'data-add-select2' => $this->label(),
                'data-add-select2-ajax' => $ajax
            ]);
        }
    }
    
    public function getAdminFilterWhereQuery($query, $input)
    {
        $index = \DB::getQueryGrammar()->wrap($this->index());
        // index is wraped
        $query->whereRaw("FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\\\"', ''))", $input);
    }

    protected function setAdminFilterOptions(&$filter)
    {
        if (isset($this->target_table)) {
            $options = $this->target_table->getSelectOptions();
            $ajax = $this->target_table->getOptionAjaxUrl();
    
            if (isset($ajax)) {
                $filter->select([])->ajax($ajax);
            } else {
                $filter->select($options);
            }
        }
    }
    
    protected function setValidates(&$validates, $form_column_options)
    {
        $validates[] = new Validator\SelectTableNumericRule($this->target_table);
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
                $indexName = $target_custom_column ?? $target_custom_column->index_enabled ? $target_custom_column->getIndexColumnName() : "value->$target_column_name";
                $target_value = $this->target_table->getValueModel()->where($indexName, $v)->select(['id'])->first();

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
     * @param [type] $datalist
     * @param [type] $key
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
     * @param [type] $value
     * @return ?string string:matched, null:not matched
     */
    public function getValFromLabel($label)
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

        // only single search column, search
        if (!$use_table_label_id && count($labelColumns) == 1) {
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

        $searchValue = $column_item->getValFromLabel($value);
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
}
