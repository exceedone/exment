<?php

namespace Exceedone\Exment\DataItems\Form;

use Symfony\Component\HttpFoundation\Response;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ValidateCalledType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Services\PartialCrudService;

class DefaultForm extends FormBase
{
    public function __construct($custom_table, $custom_form)
    {
        $this->custom_table = $custom_table;
        $this->custom_form = $custom_form;
    }


    /**
     * Make a form builder.
     * @param $id if edit mode, set model id
     * @return Form
     */
    public function form()
    {
        $request = request();
        
        $classname = getModelName($this->custom_table);
        $form = new Form(new $classname);

        if (isset($this->id)) {
            $form->systemValues()->setWidth(12, 0);
        }

        //TODO: escape laravel-admin bug.
        //https://github.com/z-song/laravel-admin/issues/1998
        $form->hidden('laravel_admin_escape');

        // for lock data
        $form->hidden('updated_at');

        // get select_parent
        $select_parent = $request->has('select_parent') ? intval($request->get('select_parent')) : null;
        // set one:many select
        $this->setParentSelect($request, $form, $select_parent);

        $calc_formula_array = [];
        $changedata_array = [];
        $relatedlinkage_array = [];
        $count_detail_array = [];
        $this->setCustomFormEvents($calc_formula_array, $changedata_array, $relatedlinkage_array, $count_detail_array);

        // loop for custom form blocks
        foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
            // if available is false, continue
            if (!$custom_form_block->available) {
                continue;
            }
            // when default block, set as normal form columns.
            if ($custom_form_block->form_block_type == FormBlockType::DEFAULT) {
                $form->embeds('value', exmtrans("common.input"), $this->getCustomFormColumns($form, $custom_form_block, $this->custom_value))
                    ->disableHeader();
            }
            // one_to_many or manytomany
            else {
                list($relation, $relation_name, $block_label) = $custom_form_block->getRelationInfo();
                $target_table = $custom_form_block->target_table;
                // if user doesn't have edit permission, hide child block
                if ($target_table->enableEdit() !== true) {
                    continue;
                }
                // 1:n
                if ($custom_form_block->form_block_type == FormBlockType::ONE_TO_MANY) {
                    // get form columns count
                    $form_block_options = array_get($custom_form_block, 'options', []);
                    // if form_block_options.hasmany_type is 1, hasmanytable
                    if (boolval(array_get($form_block_options, 'hasmany_type'))) {
                        $hasmany = $form->hasManyTable(
                            $relation_name,
                            $block_label,
                            function ($form) use ($custom_form_block, $relation_name) {
                                $form->nestedEmbeds('value', $this->custom_form->form_view_name, function (Form\EmbeddedForm $form) use ($custom_form_block) {
                                    $this->setCustomFormColumns($form, $custom_form_block);
                                })->setRelationName($relation_name);
                            }
                        )->setTableWidth(12, 0);
                    }
                    // default,hasmany
                    else {
                        $hasmany = $form->hasMany(
                            $relation_name,
                            $block_label,
                            function ($form, $model = null) use ($custom_form_block, $relation, $relation_name) {
                                $form->nestedEmbeds('value', $this->custom_form->form_view_name, $this->getCustomFormColumns($form, $custom_form_block, $model, $relation))
                                ->disableHeader()->setRelationName($relation_name);
                            }
                        );
                    }
                    if (array_key_exists($relation_name, $count_detail_array)) {
                        $hasmany->setCountScript(array_get($count_detail_array, $relation_name));
                    }
                }
                // n:n
                else {
                    // get select classname
                    $isListbox = $target_table->isGetOptions();
                    if ($isListbox) {
                        $class = Field\Listbox::class;
                    } else {
                        $class = Field\MultipleSelect::class;
                    }

                    $field = new $class(
                        CustomRelation::getRelationNameByTables($this->custom_table, $target_table),
                        [$custom_form_block->target_table->table_view_name]
                    );
                    $custom_table = $this->custom_table;
                    $field->options(function ($select) use ($custom_table, $target_table, $isListbox) {
                        return $target_table->getSelectOptions(
                            [
                                'selected_value' => $select,
                                'display_table' => $custom_table,
                                'notAjax' => $isListbox,
                            ]
                        );
                    });
                    if (!$isListbox) {
                        $field->ajax($target_table->getOptionAjaxUrl());
                    } else {
                        $field->settings(['nonSelectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.selectedListLabel')]);
                        $field->help(exmtrans('common.bootstrap_duallistbox_container.help'));
                    }
                    $form->pushField($field);
                }
            }
        }

        PartialCrudService::setAdminFormOptions($this->custom_table, $form, $this->id);

        // add calc_formula_array and changedata_array info
        if (count($calc_formula_array) > 0) {
            $json = json_encode($calc_formula_array);
            $script = <<<EOT
            var json = $json;
            Exment.CommonEvent.setCalcEvent(json);
EOT;
            Admin::script($script);
        }
        if (count($changedata_array) > 0) {
            $json = json_encode($changedata_array);
            $script = <<<EOT
            var json = $json;
            Exment.CommonEvent.setChangedataEvent(json);
EOT;
            Admin::script($script);
        }
        if (count($relatedlinkage_array) > 0) {
            $json = json_encode($relatedlinkage_array);
            $script = <<<EOT
            var json = $json;
            Exment.CommonEvent.setRelatedLinkageEvent(json);
EOT;
            Admin::script($script);
        }

        // ignore select_parent
        $form->ignore('select_parent');

        // add form saving and saved event
        $this->manageFormSaving($form);
        $this->manageFormSaved($form, $select_parent);

        $form->disableReset();

        $custom_table = $this->custom_table;
        $custom_form = $this->custom_form;

        $this->manageFormToolButton($form, $custom_table, $custom_form);
        return $form;
    }

    /**
     * set custom form columns
     */
    protected function setCustomFormColumns($form, $custom_form_block)
    {
        $fields = []; // setting fields.
        foreach ($custom_form_block->custom_form_columns as $form_column) {
            // exclusion header and html
            if ($form_column->form_column_type == FormColumnType::OTHER) {
                continue;
            }

            $item = $form_column->column_item;
            if (isset($this->id)) {
                $item->id($this->id);
            }
            $form->pushField($item->getAdminField($form_column));
        }
    }

    /**
     * set custom form columns
     *
     * @param Form $form Laravel-admin's form
     * @param CustomFormBlock $custom_form_block
     * @param CustomValue|null $target_custom_value target customvalue. if Child block, this arg is child custom value.
     * @param CustomRelation|null $this form block's relation
     * @return array
     */
    protected function getCustomFormColumns($form, $custom_form_block, $target_custom_value = null, ?CustomRelation $relation = null)
    {
        $closures = [];
        if (is_numeric($target_custom_value)) {
            $target_custom_value = $this->custom_table->getValueModel($target_custom_value);
        }
        // setting fields.
        foreach ($custom_form_block->custom_form_columns as $form_column) {
            if (!isset($target_custom_value) && $form_column->form_column_type == FormColumnType::SYSTEM) {
                continue;
            }

            if (is_null($form_column->column_item)) {
                continue;
            }

            $field = $form_column->column_item->setCustomValue($target_custom_value)->getAdminField($form_column);

            // set $closures using $form_column->column_no
            if (isset($field)) {
                $column_no = array_get($form_column, 'column_no');
                $closures[$column_no][] = $field;
            }
        }

        $is_grid = array_key_exists(1, $closures) && array_key_exists(2, $closures);
        return collect($closures)->map(function ($closure, $key) use ($is_grid) {
            return function ($form) use ($closure, $key, $is_grid) {
                foreach ($closure as $field) {
                    if ($is_grid && in_array($key, [1, 2])) {
                        $field->setWidth(8, 3);
                    } else {
                        $field->setWidth(8, 2);
                    }
                    // push field to form
                    $form->pushField($field);
                }
            };
        })->toArray();
    }

    /**
     * set custom form columns
     */
    protected function setCustomFormEvents(&$calc_formula_array, &$changedata_array, &$relatedlinkage_array, &$count_detail_array)
    {
        foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
            foreach ($custom_form_block->custom_form_columns as $form_column) {
                if ($form_column->form_column_type != FormColumnType::COLUMN) {
                    continue;
                }
                if (!isset($form_column->custom_column)) {
                    continue;
                }
                $column = $form_column->custom_column;
                $form_column_options = $form_column->options;
                $options = $column->options;
                
                // set calc rule for javascript
                if (array_key_value_exists('calc_formula', $options)) {
                    $is_default = $custom_form_block->form_block_type == FormBlockType::DEFAULT;
                    $this->setCalcFormulaArray($column, $options, $calc_formula_array, $count_detail_array, $is_default);
                }
                // data changedata
                // if set form_column_options changedata_target_column_id, and changedata_column_id
                if (array_key_value_exists('changedata_target_column_id', $form_column_options) && array_key_value_exists('changedata_column_id', $form_column_options)) {
                    ///// set changedata info
                    $this->setChangeDataArray($column, $form_column_options, $options, $changedata_array);
                }
                    
                // set relatedlinkage_array
                // if set form_column_options relation_filter_target_column_id
                if (array_key_value_exists('relation_filter_target_column_id', $form_column_options)) {
                    $this->setRelatedLinkageArray($custom_form_block, $form_column, $relatedlinkage_array);
                }
            }
        }
    }


    protected function manageFormSaving($form)
    {
        // before saving
        $id = $this->id;
        $form->saving(function ($form) use ($id) {
            $result = PartialCrudService::saving($this->custom_table, $form, $id);
            if ($result instanceof Response) {
                return $result;
            }
        });

        // form validation saving event
        $form->validatorSavingCallback(function ($input, $message, $form) {
            $model = $form->model();
            if (!$model) {
                return;
            }

            if (is_array($validateResult = $model->validateSaving($input, [
                'column_name_prefix' => 'value.',
                'calledType' => ValidateCalledType::FORM,
            ]))) {
                $message = $message->merge($validateResult);
            }


            // validation relations ----------------------------------------------------
            foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
                // if available is false, continue
                if (!$custom_form_block->available) {
                    continue;
                }
                // when not 1:n, set as normal form columns.
                if (!isMatchString($custom_form_block->form_block_type, FormBlockType::ONE_TO_MANY)) {
                    continue;
                }
                list($custom_relation, $relation_name, $block_label) = $custom_form_block->getRelationInfo();
                if (!$custom_relation) {
                    continue;
                }
                if (!method_exists($model, $relation_name)) {
                    continue;
                }
    
                // get relation value
                $relation = $model->$relation_name();
                $keyName = $relation->getRelated()->getKeyName();
                $relationValues = array_get($input, $relation_name, []);

                // ignore ids
                $ignoreIds = collect($relationValues)->filter(function ($val, $key) {
                    return is_int($key);
                })->map(function ($val) {
                    return array_get($val, 'id');
                })->values()->toArray();
                
                // skip _remove_ flg
                $relationValues = array_filter($relationValues, function ($val) {
                    if (array_get($val, Form::REMOVE_FLAG_NAME) == 1) {
                        return false;
                    }
                    return true;
                });

                // loop input's value
                foreach ($relationValues as $relationK => $relationV) {
                    $instance = $relation->findOrNew(array_get($relationV, $keyName));
                    // remove self item
                    $uniqueCheckSiblings = array_filter($relationValues, function ($relationValue, $key) use ($relationK) {
                        return !isMatchString($relationK, $key);
                    }, ARRAY_FILTER_USE_BOTH);
                    
                    if (is_array($validateResult = $instance->validateSaving($relationV, [
                        'column_name_prefix' => "$relation_name.$relationK.value.",
                        'uniqueCheckSiblings' => array_values($uniqueCheckSiblings),
                        'uniqueCheckIgnoreIds' => $ignoreIds,
                        'calledType' => ValidateCalledType::FORM,
                    ]))) {
                        $message = $message->merge($validateResult);
                    }
                }
            }
        });
        
        // form prepare callback event
        $form->prepareCallback(function ($input) {
            array_forget($input, 'updated_at');
            return $input;
        });
    }

    protected function manageFormSaved($form, $select_parent = null)
    {
        // after saving
        $form->savedInTransaction(function ($form) {
            PartialCrudService::saved($this->custom_table, $form, $form->model()->id);
        });
        
        $form->saved(function ($form) use ($select_parent) {
            // if $one_record_flg, redirect
            $one_record_flg = boolval(array_get($this->custom_table->options, 'one_record_flg'));
            if ($one_record_flg) {
                admin_toastr(trans('admin.save_succeeded'));
                return redirect(admin_urls('data', $this->custom_table->table_name));
            } elseif (!empty($select_parent)) {
                admin_toastr(trans('admin.save_succeeded'));
                return redirect(admin_url('data/'.$form->model()->parent_type.'/'. $form->model()->parent_id));
            } elseif (empty(request('after-save'))) {
                admin_toastr(trans('admin.save_succeeded'));
                return redirect($this->custom_table->getGridUrl(true));
            }
        });
    }

    protected function manageFormToolButton($form, $custom_table, $custom_form)
    {
        $form->disableEditingCheck(false);
        $form->disableCreatingCheck(false);
        $form->disableViewCheck(false);

        $id = $this->id;
        $form->tools(function (Form\Tools $tools) use ($id, $custom_table) {
            $custom_value = $custom_table->getValueModel($id);

            // create
            if (!isset($id)) {
                $isButtonCreate = true;
                $listButtons = Plugin::pluginPreparingButton(PluginEventTrigger::FORM_MENUBUTTON_CREATE, $custom_table);
            }
            // edit
            else {
                $isButtonCreate = false;
                $listButtons = Plugin::pluginPreparingButton(PluginEventTrigger::FORM_MENUBUTTON_EDIT, $custom_table);
            }
            
            $tools->disableView(false);
            $tools->setListPath($custom_table->getGridUrl(true));

            // if one_record_flg, disable list
            if (array_get($custom_table->options, 'one_record_flg')) {
                $tools->disableListButton();
                $tools->disableDelete();
                $tools->disableView();
            }

            // if user only view, disable delete and view
            elseif (!$custom_table->hasPermissionEditData($id)) {
                $tools->disableDelete();
            }

            if (boolval(array_get($custom_value, 'disabled_delete'))) {
                $tools->disableDelete();
            }

            // add plugin button
            if ($listButtons !== null && count($listButtons) > 0) {
                foreach ($listButtons as $listButton) {
                    $tools->append(new Tools\PluginMenuButton($listButton, $custom_table, $id));
                }
            }

            PartialCrudService::setAdminFormTools($custom_table, $tools, $id);
            
            if ($custom_table->enableTableMenuButton()) {
                $tools->add((new Tools\CustomTableMenuButton('data', $custom_table)));
            }
        });
    }
    
    /**
     * Create calc formula info.
     */
    protected function setCalcFormulaArray($column, $options, &$calc_formula_array, &$count_detail_array, $is_default = true)
    {
        if (is_null($calc_formula_array)) {
            $calc_formula_array = [];
        }
        // get format for calc formula
        $option_calc_formulas = array_get($options, "calc_formula");
        if ($option_calc_formulas == "null") {
            return;
        } //TODO:why???
        if (!is_array($option_calc_formulas) && is_json($option_calc_formulas)) {
            $option_calc_formulas = json_decode($option_calc_formulas, true);
        }

        // keys for calc trigger on display
        $keys = [];
        // loop $option_calc_formulas and get column_name
        foreach ($option_calc_formulas as &$option_calc_formula) {
            $child_select_table = array_get($option_calc_formula, 'table');
            if (isset($child_select_table)) {
                $option_calc_formula['relation_name'] = CustomRelation::getRelationNameByTables($this->custom_table, $child_select_table);
            }
            switch (array_get($option_calc_formula, 'type')) {
                case 'count':
                    if (array_has($option_calc_formula, 'relation_name')) {
                        $relation_name = $option_calc_formula['relation_name'];
                        if (!array_has($count_detail_array, $relation_name)) {
                            $count_detail_array[$relation_name] = [];
                        }
                        $count_detail_array[$relation_name][] =  [
                            'options' => $option_calc_formulas,
                            'to' => $column->column_name,
                            'is_default' => $is_default
                        ];
                    }
                    break;
                case 'dynamic':
                case 'summary':
                case 'select_table':
                    // set column name
                    $formula_column = CustomColumn::getEloquent(array_get($option_calc_formula, 'val'));
                    // get column name as key
                    $key = $formula_column->column_name ?? null;
                    if (!isset($key)) {
                        break;
                    }
                    $keys[] = $key;
                    // set $option_calc_formula val using key
                    $option_calc_formula['val'] = $key;

                    // if select table, set from value
                    if ($option_calc_formula['type'] == 'select_table') {
                        $column_from = CustomColumn::getEloquent(array_get($option_calc_formula, 'from'));
                        $option_calc_formula['from'] = $column_from->column_name ?? null;
                    }
                    break;
            }
        }

        $keys = array_unique($keys);
        // loop for $keys and set $calc_formula_array
        foreach ($keys as $key) {
            // if not exists $key in $calc_formula_array, set as array
            if (!array_has($calc_formula_array, $key)) {
                $calc_formula_array[$key] = [];
            }
            // set $calc_formula_array
            $calc_formula_array[$key][] = [
                'options' => $option_calc_formulas,
                'to' => $column->column_name,
                'is_default' => $is_default
            ];
        }
    }

    /**
     * set change data array.
     * "change data": When selecting a list, paste the value of that item into another form item.
     * "changedata_target_column_id" : trigger column when user select
     * "changedata_column_id" : set column when getting selected value
     */
    protected function setChangeDataArray($column, $form_column_options, $options, &$changedata_array)
    {
        // get this table
        $column_table = $column->custom_table;

        // get getting target model name
        $changedata_target_column_id = array_get($form_column_options, 'changedata_target_column_id');
        $changedata_target_column = CustomColumn::getEloquent($changedata_target_column_id);
        if (is_nullorempty($changedata_target_column)) {
            return;
        }

        $changedata_target_table = $changedata_target_column->custom_table;
        if (is_nullorempty($changedata_target_table)) {
            return;
        }

        // get table column. It's that when get model data, copied from column
        $changedata_column_id = array_get($form_column_options, 'changedata_column_id');
        $changedata_column = CustomColumn::getEloquent($changedata_column_id);
        if (is_nullorempty($changedata_column)) {
            return;
        }

        $changedata_table = $changedata_column->custom_table;
        if (is_nullorempty($changedata_table)) {
            return;
        }

        // get select target table
        $select_target_table = $changedata_target_column->select_target_table;
        if (is_nullorempty($select_target_table)) {
            return;
        }

        // if different $column_table and changedata_target_table, get to_target block name using relation
        if ($column_table->id != $changedata_target_table->id) {
            $to_block_name = CustomRelation::getRelationNameByTables($changedata_target_table, $column_table);
        } else {
            $to_block_name = null;
        }

        // if not exists $changedata_target_column->column_name in $changedata_array
        if (!array_has($changedata_array, $changedata_target_column->column_name)) {
            $changedata_array[$changedata_target_column->column_name] = [];
        }
        if (!array_has($changedata_array[$changedata_target_column->column_name], $select_target_table->table_name)) {
            $changedata_array[$changedata_target_column->column_name][$select_target_table->table_name] = [];
        }
        // push changedata column from and to column name
        $changedata_array[$changedata_target_column->column_name][$select_target_table->table_name][] = [
            'from' => $changedata_column->column_name, // target_table's column
            'to' => $column->column_name, // set data
            'to_block' => is_null($to_block_name) ? null : '.has-many-' . $to_block_name . ',.has-many-table-' . $to_block_name,
            'to_block_form' => is_null($to_block_name) ? null : '.has-many-' . $to_block_name . '-form,.has-many-table-' . $to_block_name.'-form',
        ];
    }
    
    /**
     * set related linkage array.
     * "related linkage": When selecting a value, change the choices of other list. It's for 1:n relation.
     */
    protected function setRelatedLinkageArray($custom_form_block, $form_column, &$relatedlinkage_array)
    {
        // if config "select_relation_linkage_disabled" is true, return
        if (boolval(config('exment.select_relation_linkage_disabled', false))) {
            return;
        }

        // if available is false, continue
        if (!$custom_form_block->available || !isset($custom_form_block->target_table)) {
            return;
        }

        $relation_filter_target_column_id = array_get($form_column, 'options.relation_filter_target_column_id');
        if (!isset($relation_filter_target_column_id)) {
            return;
        }

        $custom_column = $form_column->custom_column_cache;
        if (!isset($custom_column)) {
            return;
        }

        // get relation columns
        $linkages = Linkage::getLinkages($relation_filter_target_column_id, $custom_column);

        foreach ($linkages as $linkage) {
            $parent_column = $linkage->parent_column;
            $parent_column_name = array_get($parent_column, 'column_name');
            $parent_select_table = $parent_column->select_target_table;

            $child_column = $linkage->child_column;
            $child_select_table = $child_column->select_target_table;
                    
            // skip same table
            if ($parent_select_table->id == $child_select_table->id) {
                continue;
            }

            // if not exists $column_name in $relatedlinkage_array
            if (!array_has($relatedlinkage_array, $parent_column_name)) {
                $relatedlinkage_array[$parent_column_name] = [];
            }

            // add array. key is column name.
            $relatedlinkage_array[$parent_column_name][] = [
                'url' => admin_urls('webapi', 'data', $parent_select_table->table_name ?? null, 'relatedLinkage'),
                'expand' => [
                    'child_column_id' => $child_column->id ?? null,
                    'parent_select_table_id' => $parent_select_table->id ?? null,
                    'child_select_table_id' => $child_select_table->id ?? null,
                    'search_type' => $linkage->searchType,
                    'display_table_id' => $this->custom_table->id,
                ],
                'to' => array_get($child_column, 'column_name'),
            ];
        }
    }

    protected function setParentSelect($request, $form, $select_parent)
    {
        // add parent select
        $relations = CustomRelation::getRelationsByChild($this->custom_table);
        if (!isset($relations)) {
            return;
        }

        foreach ($relations as $relation) {
            if ($relation->relation_type == RelationType::ONE_TO_MANY) {
                // set one:many select
                $this->setParentSelectOneToMany($request, $form, $select_parent, $relation);
            } else {
                // set many:many select
                $this->setParentSelectManyToMany($request, $form, $relation);
            }
        }
    }

    protected function setParentSelectOneToMany($request, $form, $select_parent, $relation)
    {
        $parent_custom_table = $relation->parent_custom_table;

        $form->hidden('select_parent')->default($select_parent);
        $form->hidden('parent_type')->default($parent_custom_table->table_name);

        // if create data and not has $select_parent, select item
        if (!isset($this->id) && !isset($select_parent)) {
            $select = $form->select('parent_id', $parent_custom_table->table_view_name)
                ->options(function ($value) use ($parent_custom_table) {
                    return $parent_custom_table->getSelectOptions([
                        'selected_value' => $value,
                        'showMessage_ifDeny' => true,
                    ]);
                })
                ->required()
                ->ajax($parent_custom_table->getOptionAjaxUrl())
                ->attribute(['data-target_table_name' => array_get($parent_custom_table, 'table_name')]);

            // set buttons
            $select->buttons([
                [
                    'label' => trans('admin.search'),
                    'btn_class' => 'btn-info',
                    'icon' => 'fa-search',
                    'attributes' => [
                        'data-widgetmodal_url' => admin_urls_query('data', $parent_custom_table->table_name, ['modalframe' => 1]),
                        'data-widgetmodal_expand' => json_encode(['target_column_class' => 'parent_id']),
                        'data-widgetmodal_getdata_fieldsgroup' => json_encode(['selected_items' => 'parent_id']),
                    ],
                ],
            ]);
        }
        // if edit data or has $select_parent, only display
        else {
            if ($request->has('parent_id')) {
                $parent_id = $request->get('parent_id');
            } else {
                $parent_id = isset($select_parent) ? $select_parent : getModelName($this->custom_table)::find($this->id)->parent_id;
            }
            $parent_value = $parent_custom_table->getValueModel($parent_id);

            if (isset($parent_id) && isset($parent_value) && isset($parent_custom_table)) {
                $form->hidden('parent_id')->default($parent_id)->attribute(['data-target_table_name' => array_get($parent_custom_table, 'table_name')]);
                $form->display('parent_id_display', $parent_custom_table->table_view_name)->default($parent_value->label);
            }
        }
    }

    protected function setParentSelectManyToMany($request, $form, $relation)
    {
        $parent_custom_table = $relation->parent_custom_table;

        if ($parent_custom_table->table_name == SystemTableName::ORGANIZATION &&
            $this->custom_table->table_name == SystemTableName::USER) {
            return;
        }

        $pivot_name = $relation->getRelationName();

        $select = $form->multipleSelect($pivot_name, $parent_custom_table->table_view_name)
            ->options(function ($value) use ($parent_custom_table) {
                return $parent_custom_table->getSelectOptions([
                    'selected_value' => $value,
                    'showMessage_ifDeny' => true,
                ]);
            });

        // set select options
        $select->ajax($parent_custom_table->getOptionAjaxUrl());

        // set buttons
        $select->buttons([
            [
                'label' => trans('admin.search'),
                'btn_class' => 'btn-info',
                'icon' => 'fa-search',
                'attributes' => [
                    'data-widgetmodal_url' => admin_urls_query('data', $parent_custom_table->table_name, ['modalframe' => 1]),
                    'data-widgetmodal_expand' => json_encode(['target_column_class' => $pivot_name, 'target_column_multiple' => true]),
                    'data-widgetmodal_getdata_fieldsgroup' => json_encode(['selected_items' => $pivot_name]),
                ],
            ],
        ]);
    }
}
