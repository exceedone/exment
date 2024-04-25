<?php

namespace Exceedone\Exment\DataItems\Form;

use Exceedone\Exment\Model\CustomFormColumn;
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
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ValidateCalledType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Enums\ShowPositionType;
use Exceedone\Exment\Enums\DataSubmitRedirectEx;
use Exceedone\Exment\Services\PartialCrudService;
use Exceedone\Exment\Services\Calc\CalcService;
use Exceedone\Exment\ColumnItems\ItemInterface;

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
        $form = new Form(new $classname());

        $form->setHorizontal(boolval($this->custom_form->getOption('form_label_type') ?? true));

        $system_values_pos = $this->custom_table->getSystemValuesPosition();

        if (isset($this->id) && $system_values_pos == ShowPositionType::TOP) {
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
        $force_caculate_column = [];
        $this->setCustomFormEvents($calc_formula_array, $changedata_array, $relatedlinkage_array, $force_caculate_column);

        // loop for custom form blocks
        foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
            // if available is false, continue
            if (!$custom_form_block->available) {
                continue;
            }
            // when default block, set as normal form columns.
            if ($custom_form_block->form_block_type == FormBlockType::DEFAULT) {
                $form->embeds('value', exmtrans("common.input"), $this->getCustomFormColumns($form, $custom_form_block, $this->custom_value))
                    ->disableHeader()
                    ->gridEmbeds();
            }
            // one_to_many or manytomany
            else {
                list($relation, $relation_name, $block_label) = $custom_form_block->getRelationInfo($this->custom_table);
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
                                $form->nestedEmbeds('value', $form->getKey(), $this->custom_form->form_view_name, function (Form\EmbeddedForm $form) use ($custom_form_block) {
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
                                $form->nestedEmbeds('value', $form->getKey(), $this->custom_form->form_view_name, $this->getCustomFormColumns($form, $custom_form_block, $model, $relation))
                                    ->disableHeader()
                                    ->setRelationName($relation_name)
                                    ->gridEmbeds();
                            }
                        );
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

        if (isset($this->id) && $system_values_pos == ShowPositionType::BOTTOM) {
            $form->systemValues()->setWidth(12, 0);
        }

        // add calc_formula_array and changedata_array info

        if (count($calc_formula_array) > 0) {
            $json = json_encode($calc_formula_array);
            $columns = json_encode($force_caculate_column);
            $script = <<<EOT
            var json = $json;
            var columns = $columns;
            Exment.CalcEvent.setCalcEvent(json, columns);
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
        $custom_form_columns = $custom_form_block->custom_form_columns; // setting fields.
        $target_id = $this->id;
        if (method_exists($form, 'getDataKey')) {
            $data_key = $form->getDataKey();
            if (is_numeric($data_key)) {
                $target_id = $data_key;
            }
        }
        foreach ($custom_form_columns as $form_column) {
            // exclusion header and html
            if ($form_column->form_column_type == FormColumnType::OTHER) {
                continue;
            }

            $item = $form_column->column_item;
            if (isset($target_id)) {
                $item->id($target_id);
            }
            $this->setColumnItemOption($item, $custom_form_columns);

            $form->pushField($item->getAdminField($form_column));
        }
    }

    /**
     * set custom form columns
     *
     * @param Form $form
     * @param CustomFormBlock $custom_form_block
     * @param CustomValue|number|null $target_custom_value
     * @param CustomRelation|null $relation
     * @return \Closure
     */
    protected function getCustomFormColumns($form, $custom_form_block, $target_custom_value = null, ?CustomRelation $relation = null)
    {
        if (is_numeric($target_custom_value)) {
            $target_custom_value = $this->custom_table->getValueModel($target_custom_value);
        }

        return function ($form) use ($custom_form_block, $target_custom_value) {
            $custom_form_columns = $custom_form_block->custom_form_columns;
            // setting fields.
            foreach ($custom_form_columns as $form_column) {
                if (!isset($target_custom_value) && $form_column->form_column_type == FormColumnType::SYSTEM) {
                    continue;
                }

                $column_item = $form_column->column_item;
                if (is_null($column_item)) {
                    continue;
                }
                $this->setColumnItemOption($column_item, $custom_form_columns);

                $field = $column_item
                    ->setCustomValue($target_custom_value)
                    ->getAdminField($form_column);

                // set $closures using $form_column->column_no
                if (!isset($field)) {
                    continue;
                }

                $field->setWidth(8, 2);
                // push field to form
                $form->pushFieldAndOption($field, [
                    'row' => $form_column->row_no,
                    'column' => $form_column->column_no,
                    'width' => $form_column->width ?? 4,
                ]);
            }
        };
    }

    /**
     * set custom form columns
     */
    protected function setCustomFormEvents(&$calc_formula_array, &$changedata_array, &$relatedlinkage_array, &$force_caculate_column)
    {
        foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
            // set calc rule for javascript
            $relation = $custom_form_block->getRelationInfo($this->custom_table)[0];
            $calc_formula_key = $relation ? $relation->getRelationName() : '';
            $force_caculate_column_key = $relation ? $relation->getRelationName() : 'default';
            $force_caculate_column[$force_caculate_column_key] = [];
            $calc_formula_array[$calc_formula_key] = CalcService::getCalcFormArray($this->custom_table, $custom_form_block);
            /** @var CustomFormColumn $form_column */
            foreach ($custom_form_block->custom_form_columns as $form_column) {
                if ($form_column->form_column_type != FormColumnType::COLUMN) {
                    continue;
                }
                if (!isset($form_column->custom_column)) {
                    continue;
                }
                /** @var CustomColumn $column */
                $column = $form_column->custom_column;
                if(array_get($column->options, 'force_caculate')) {
                    $force_caculate_column[$force_caculate_column_key][]  = $column->column_name;
                }
                $form_column_options = $form_column->options;
                $options = $column->options;

                // data changedata
                // if set form_column_options changedata_target_column_id, and changedata_column_id
                if (array_key_value_exists('changedata_target_column_id', $form_column_options) && array_key_value_exists('changedata_column_id', $form_column_options)) {
                    ///// set changedata info
                    $this->setChangeDataArray($column, $custom_form_block, $form_column_options, $options, $changedata_array);
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

        if (!$this->disableDefaultSavedRedirect) {
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
    }

    /**
     * Manage form tools button
     *
     * @param Form $form
     * @param CustomTable $custom_table
     * @param CustomForm $custom_form
     * @return void
     */
    protected function manageFormToolButton($form, $custom_table, $custom_form)
    {
        if (!$this->disableSavingButton) {
            if (!$this->disableSavedRedirectCheck) {
                $data_submit_redirect = $custom_table->getOption('data_submit_redirect');
                if (empty($data_submit_redirect) || $data_submit_redirect == DataSubmitRedirectEx::INHERIT) {
                    $data_submit_redirect = System::data_submit_redirect();
                }
                $checkboxes = collect([
                    [
                        'key' => 'continue_editing',
                        'value' => 1,
                    ],
                    [
                        'key' => 'continue_creating',
                        'value' => 2,
                    ],
                    [
                        'key' => 'view',
                        'value' => 3,
                    ],
                    [
                        'key' => 'list',
                        'value' => 4,
                        'redirect' => admin_urls('data', $this->custom_table->table_name),
                    ],
                ])->map(function ($checkbox) use($data_submit_redirect) {
                    return array_merge([
                        'label' => trans('admin.' . $checkbox['key']),
                        'default' => isMatchString($data_submit_redirect, $checkbox['value']),
                    ], $checkbox);
                })->each(function ($checkbox) use ($form) {
                    $form->submitRedirect($checkbox);
                });
            }
        } else {
            $form->disableSubmit();
        }


        $id = $this->id;
        $disableToolsButton = $this->disableToolsButton;
        $form->tools(function (Form\Tools $tools) use ($id, $custom_table, $disableToolsButton) {
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

            // if all disable tools button
            if ($disableToolsButton) {
                $tools->disableListButton();
                $tools->disableDelete();
                $tools->disableView();
            }

            // add plugin button
            if (!$disableToolsButton && $listButtons !== null && count($listButtons) > 0) {
                foreach ($listButtons as $listButton) {
                    $tools->append(new Tools\PluginMenuButton($listButton, $custom_table, $id));
                }
            }

            if (!$disableToolsButton) {
                PartialCrudService::setAdminFormTools($custom_table, $tools, $id);
            }

            if (!$disableToolsButton && $custom_table->enableTableMenuButton()) {
                $tools->add((new Tools\CustomTableMenuButton('data', $custom_table)));
            }
        });
    }


    /**
     * set change data array.
     * "change data": When selecting a list, paste the value of that item into another form item.
     * "changedata_target_column_id" : trigger column when user select
     * "changedata_column_id" : set column when getting selected value
     */
    protected function setChangeDataArray(CustomColumn $column, CustomFormBlock $custom_form_block, array $form_column_options, $options, &$changedata_array)
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

        //// get from block name.
        // if not match form block's and $changedata_target_table. from block is default
        if (!isMatchString($custom_form_block->form_block_target_table_id, $changedata_target_table->id)) {
            $from_block_name = 'default';
        //$from_block_name = CustomRelation::getRelationNameByTables($changedata_target_table->id, $custom_form_block->form_block_target_table_id);
        }
        // if child form
        elseif ($custom_form_block->form_block_type != FormBlockType::DEFAULT) {
            $from_block_name = $custom_form_block->getRelationInfo()[1];
        } else {
            $from_block_name = null;
        }

        // get group key for changedata trigger
        $group_key = "{$from_block_name}/{$changedata_target_column->column_name}";

        // if not exists $changedata_target_column->column_name in $changedata_array
        if (!array_has($changedata_array, $group_key)) {
            $changedata_array[$group_key] = [];
        }
        if (!array_has($changedata_array[$group_key], $select_target_table->table_name)) {
            $changedata_array[$group_key][$select_target_table->table_name] = [];
        }
        // push changedata column from and to column name
        $changedata_array[$group_key][$select_target_table->table_name][] = [
            'from' => $changedata_column->column_name, // target_table's column
            'from_block' => $from_block_name, // target_table's block
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
                'uri' => url_join('data', $parent_select_table->table_name ?? null, 'relatedLinkage'),
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

        // get parentId, parentValue
        if ($request->has('parent_id')) {
            $parent_id = $request->get('parent_id');
        } elseif (isset($select_parent)) {
            $parent_id = $select_parent;
        } else {
            $custom_value = getModelName($this->custom_table)::find($this->id);
            $parent_id = $custom_value ? $custom_value->parent_id : null;
        }
        $parent_value = $parent_custom_table->getValueModel($parent_id);

        // if create data and not has $select_parent, select item
        // or if edit data and not have parent record
        if ((!isset($this->id) && !isset($select_parent)) || (isset($this->id) && !isset($parent_id))) {
            $select = $form->select('parent_id', $parent_custom_table->table_view_name)
                ->options(function ($value) use ($parent_custom_table) {
                    return $parent_custom_table->getSelectOptions([
                        'selected_value' => $value,
                        'showMessage_ifDeny' => true,
                    ]);
                })
                ->required()
                ->ajax($parent_custom_table->getOptionAjaxUrl())
                ->attribute(['data-target_table_name' => array_get($parent_custom_table, 'table_name'), 'data-parent_id' => true]);

            // set buttons
            if (!$this->isPublicForm()) {
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
        }
        // if edit data or has $select_parent, only display
        else {
            if (isset($parent_id) && isset($parent_value) && isset($parent_custom_table)) {
                $form->hidden('parent_id')->default($parent_id)->attribute(['data-target_table_name' => array_get($parent_custom_table, 'table_name'), 'data-parent_id' => true]);
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
        if (!$this->isPublicForm()) {
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


    /**
     * Set ColumnItem's option to column item
     *
     * @param ItemInterface $column_item
     * @return void
     */
    protected function setColumnItemOption(ItemInterface $column_item, $custom_form_columns)
    {
        $column_item->setCustomForm($this->custom_form);
        $column_item->setOtherFormColumns($custom_form_columns);

        if ($this->enableDefaultQuery) {
            $column_item->options(['enable_default_query' => true]);
        }
        if ($this->asConfirm) {
            $column_item->options(['as_confirm' => true]);
        }
    }

    /**
     * Whether this form is publicform
     *
     * @return boolean
     */
    protected function isPublicForm(): bool
    {
        return $this instanceof PublicFormForm;
    }
}
