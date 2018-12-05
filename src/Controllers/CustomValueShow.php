<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Show;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\CustomFormBlockType;
use Exceedone\Exment\Enums\CustomFormColumnType;
use Exceedone\Exment\Services\Plugin\PluginInstaller;

trait CustomValueShow
{
    /**
     * create show form list
     */
    protected function createShowForm($id = null, $modal = false)
    {
        //PluginInstaller::pluginPreparing($this->plugins, 'loading');
        return Admin::show($this->getModelNameDV()::findOrFail($id), function (Show $show) use ($id, $modal) {
            // loop for custom form blocks
            foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
                // if available is false, continue
                if (!$custom_form_block->available) {
                    continue;
                }
                ////// default block(no relation block)
                if (array_get($custom_form_block, 'form_block_type') == CustomFormBlockType::DEFAULT) {
                    foreach ($custom_form_block->custom_form_columns as $form_column) {
                        //// change value using custom form value
                        switch (array_get($form_column, 'form_column_type')) {
                            // for table column
                            case CustomFormColumnType::COLUMN:
                                $column = $form_column->custom_column;
                                // set escape.
                                // select_table, url is false
                                $isUrl = in_array(array_get($column, 'column_type'), ['url', 'select_table']);
                                $show->field(array_get($column, 'column_name'), array_get($column, 'column_view_name'))->as(function ($v) use ($form_column, $column, $isUrl) {
                                    if (is_null($this)) {
                                        return '';
                                    }
                                    if ($isUrl) {
                                        return $this->getColumnUrl($column, true);
                                    }
                                    return $this->getValue($column, true);
                                })->setEscape(!$isUrl);
                                break;
                            case CustomFormColumnType::SYSTEM:
                                $form_column_obj = collect(ViewColumnType::SYSTEM_OPTIONS())->first(function ($item) use ($form_column) {
                                    return $item['id'] == array_get($form_column, 'form_column_target_id');
                                });
                                // get form column name
                                $form_column_name = array_get($form_column_obj, 'name');
                                $column_view_name =  exmtrans("common.".$form_column_name);
                                $show->field($form_column_name, $column_view_name)->as(function ($v) use ($form_column_name) {
                                    return array_get($this, $form_column_name);
                                });
                                break;
                            default:
                                continue;
                        }
                    }
                }
                ////// relation block
                else {
                    // if modal, dont show children
                    if ($modal) {
                        continue;
                    }
                    list($relation_name, $block_label) = $this->getRelationName($custom_form_block);
                    $target_table = $custom_form_block->target_table;
                    $show->{$relation_name}($block_label, function ($grid) use ($custom_form_block, $target_table) {
                        $custom_view = CustomView::getDefault($target_table);
                        $custom_view->setGrid($grid);
                        
                        $grid->disableFilter();
                        $grid->disableCreateButton();
                        $grid->disableExport();
                        $grid->tools(function ($tools) {
                            $tools->batch(function ($batch) {
                                $batch->disableDelete();
                            });
                        });
                        $grid->disableRowSelector();
                        $grid->disableActions();
                    });
                }
            }

            // if user only view permission, disable delete and view
            if (!Admin::user()->hasPermissionEditData($id, $this->custom_table->table_name)) {
                $show->panel()->tools(function ($tools) {
                    $tools->disableEdit();
                    $tools->disableDelete();
                });
            }

            // if modal, disable list and delete
            if ($modal) {
                $show->panel()->tools(function ($tools) {
                    $tools->disableList();
                    $tools->disableDelete();
                });
            }

            // show plugin button and copy button
            if (!$modal) {
                $listButtons = PluginInstaller::pluginPreparingButton($this->plugins, 'form_menubutton_show');
                $copyButtons = $this->custom_table->from_custom_copies;
                $show->panel()->tools(function ($tools) use ($listButtons, $copyButtons, $id) {
                    foreach ($listButtons as $plugin) {
                        $tools->append(new Tools\PluginMenuButton($plugin, $this->custom_table, $id));
                    }
                    foreach ($copyButtons as $copyButton) {
                        $b = new Tools\CopyMenuButton($copyButton, $this->custom_table, $id);
                    
                        $tools->append($b->toHtml());
                    }
                });
            }
        });
    }

    /**
     * set option boxes.
     * contains file uploads, revisions
     */
    protected function setOptionBoxes($row, $id, $modal = false)
    {
        $documents = $this->getDocuments($id, $modal);
        $useFileUpload = $this->useFileUpload($modal);
 
        $revisions = $this->getRevisions($id, $modal);

        if(count($documents) > 0 || $useFileUpload){
                
            $form = new WidgetForm;
            $form->disableReset();
            $form->disableSubmit();

            // show document list
            if (isset($id)) {
                if (count($documents) > 0) {
                    $html = [];
                    foreach ($documents as $index => $d) {
                        $html[] = "<p>" . view('exment::form.field.documentlink', [
                            'document' => $d
                        ])->render() . "</p>";
                    }
                    // loop and add as link
                    $form->html(implode("", $html))
                        ->plain()
                        ->setWidth(8,3);
                }
            }

            // add file uploader
            if ($useFileUpload) {
                $options = [
                    'showUpload' => true,
                    'showUpload' => true,
                    'showPreview' => false,
                    'uploadUrl' => admin_base_paths('data', $this->custom_table->table_name, $id, 'fileupload'),
                    'uploadExtraData'=> [
                        '_token' => csrf_token()
                    ],
                ];
                $options_json = json_encode($options);

                $input_id = 'file_data';
                $form->file($input_id, trans('admin.upload'))
                ->options($options)
                ->setWidth(8,3);
                // // create file upload option
                // $show->field($input_id, trans('admin.upload'))->as(function ($v) use ($input_id) {
                //     return '<input type="file" id="'.$input_id.'" />';
                // })->unescape();
                // $options = json_encode([
                //     'showPreview' => false,
                //     'uploadUrl' => admin_base_paths('data', $this->custom_table->table_name, $id, 'fileupload'),
                //     'uploadExtraData'=> [
                //         '_token' => csrf_token()
                //     ],
                // ]);

                $script = <<<EOT
    $("#$input_id").fileinput({$options_json})
    .on('fileuploaded', function(e, params) {
        console.log('file uploaded', e, params);
        $.pjax.reload('#pjax-container');
    });

EOT;
                Admin::script($script);
            }
            $row->column(6, (new Box(exmtrans("common.attachment"), $form))->style('info'));        
        }

        if(count($revisions) > 0){
            $form = new WidgetForm;
            $form->disableReset();
            $form->disableSubmit();
    
            foreach ($revisions as $index => $revision) {
                $form->html(
                    view('exment::form.field.revisionlink', [
                        'revision' => $revision,
                    ])->render()
                    , 'No.'.(count($revisions) - $index)
                )->setWidth(9,2);
            }
            $row->column(6, (new Box('更新履歴', $form))->style('info'));        
        }
    }
    
    /**
     * whether file upload field
     */
    protected function useFileUpload($modal = false){
        return !$modal && boolval($this->custom_table->getOption('attachment_flg'));
    }
    
    protected function getDocuments($id, $modal = false){
        if ($modal) {
            return [];
        }
        return getModelName(SystemTableName::DOCUMENT)
            ::where('parent_id', $id)
            ->where('parent_type', $this->custom_table->table_name)
            ->get();
    }
    
    protected function getRevisions($id, $modal = false){
        if ($modal && boolval($this->custom_table->getOption('revision_flg'))) {
            return [];
        }
        return $this->getModelNameDV()::find($id)
            ->revisionHistory()
            ->orderby('id', 'desc')
            ->get() ?? [];
    }

}
