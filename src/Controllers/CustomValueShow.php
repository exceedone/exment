<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Show;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Plugin\PluginInstaller;


trait CustomValueShow
{
    
    /** 
     * create show form list
     */
    protected function createShowForm($id = null)
    {
        //PluginInstaller::pluginPreparing($this->plugins, 'loading');
        return Admin::show($this->getModelNameDV()::findOrFail($id), function (Show $show) use ($id) {
            // loop for custom form blocks
            foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
                // if available is false, continue
                if (!$custom_form_block->available) {
                    continue;
                }
                foreach ($custom_form_block->custom_form_columns as $form_column) {
                    $column = $form_column->custom_column;
                    $show->field(array_get($column, 'column_name'), array_get($column, 'column_view_name'))->as(function($v) use($column){
                        if(is_null($this)){return '';}
                        return $this->getValue($column, true);
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
            
            // show plugin button
            $listButtons = PluginInstaller::pluginPreparingButton($this->plugins, 'form_menubutton_show');
            if(count($listButtons) > 0){
                $show->panel()->tools(function ($tools) use($listButtons, $id) {
                    foreach($listButtons as $plugin){
                        $tools->append(new Tools\PluginMenuButton($plugin, $this->custom_table, $id));
                    }
                });
            }
        });
    }
}
