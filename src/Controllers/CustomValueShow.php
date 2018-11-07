<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Show;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomCopy;
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
                if (array_get($custom_form_block, 'form_block_type') == Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT) {
                    foreach ($custom_form_block->custom_form_columns as $form_column) {
                        //// change value using custom form value
                        switch (array_get($form_column, 'form_column_type')) {
                            // for table column
                            case Define::CUSTOM_FORM_COLUMN_TYPE_COLUMN:
                                $column = $form_column->custom_column;
                                // set escape.
                                // select_table, url is false
                                $isUrl = in_array(array_get($column, 'column_type'), ['url', 'select_table']);
                                $show->field(array_get($column, 'column_name'), array_get($column, 'column_view_name'))->as(function ($v) use ($form_column, $column, $isUrl) {
                                    if (is_null($this)) {
                                        return '';
                                    }
                                    if($isUrl){
                                        return getUrl($this, $column, true);
                                    }
                                    return $this->getValue($column, true);
                                })->setEscape(!$isUrl);
                                break;
                            case Define::CUSTOM_FORM_COLUMN_TYPE_SYSTEM:
                                $form_column_obj = collect(Define::VIEW_COLUMN_SYSTEM_OPTIONS)->first(function ($item) use ($form_column) {
                                    return $item['id'] == array_get($form_column, 'form_column_target_id');
                                });
                                // get form column name
                                $form_column_name = array_get($form_column_obj, 'name');
                                $column_view_name =  exmtrans("custom_column.system_columns.".$form_column_name);
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
                else{
                    // if modal, dont show children
                    if($modal){
                        continue;
                    }
                    list($relation_name, $block_label) = $this->getRelationName($custom_form_block);
                    $target_table = $custom_form_block->target_table;
                    $show->{$relation_name}($block_label, function($grid) use($custom_form_block, $target_table){
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

            // show document list
            if(isset($id)){
                $documents = getModelName(Define::SYSTEM_TABLE_NAME_DOCUMENT)
                    ::where('parent_id', $id)
                    ->where('parent_type', $this->custom_table->table_name)
                    ->get();
                // loop and add as link
                foreach($documents as $index => $d){
                    $show->field('document_'.array_get($d, 'id'), '書類')->as(function($v) use($d){
                        $link = '<a href="'.admin_base_path(url_join('files', $d->getValue('file_uuid', true))).'" target="_blank">'. $d->getValue('document_name').'</a>';
                        $comment = "<small>(作成日：".$d->created_at." 作成者：".$d->created_user.")</small>";
                        return $link.$comment;
                    })->unescape();
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
            if($modal){
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
}
