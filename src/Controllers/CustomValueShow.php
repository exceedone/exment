<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Show;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Revisionable\Revision;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
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
                if (array_get($custom_form_block, 'form_block_type') == FormBlockType::DEFAULT) {
                    foreach ($custom_form_block->custom_form_columns as $form_column) {
                        //// change value using custom form value
                        switch (array_get($form_column, 'form_column_type')) {
                            // for table column
                            case FormColumnType::COLUMN:
                                $column = $form_column->custom_column;
                                // set escape.
                                // select_table, url is false
                                $isEscape = ColumnType::isNotEscape(array_get($column, 'column_type'));
                                $show->field(array_get($column, 'column_name'), array_get($column, 'column_view_name'))->as(function ($v) use ($form_column, $column) {
                                    if (is_null($this)) {
                                        return '';
                                    }
                                    $column_type = array_get($column, 'column_type');
                                    if (ColumnType::isUrl($column_type)) {
                                        return $this->getColumnUrl($column, true);
                                    }
                                    if ($column_type == ColumnType::EDITOR || $column_type == ColumnType::TEXTAREA) {
                                        return '<div class="show-tinymce">'.preg_replace("/\\\\r\\\\n|\\\\r|\\\\n|\\r\\n|\\r|\\n/", "<br/>", $this->getValue($column, true)).'</div>' ?? null;
                                    }
                                    return $this->getValue($column, true);
                                })->setEscape(!$isEscape);
                                break;
                            case FormColumnType::SYSTEM:
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
            if (!$this->custom_table->hasPermissionEditData($id)) {
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
            }else{
                $show->panel()->tools(function ($tools) {
                    $tools->append((new Tools\GridChangePageMenu('data', $this->custom_table, false))->render());
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
        $custom_value = $this->getModelNameDV()::find($id);
        $documents = $this->getDocuments($id, $modal);
        $useFileUpload = $this->useFileUpload($modal);
 
        $revisions = $this->getRevisions($id, $modal);

        if(count($documents) > 0 || $useFileUpload){
                
            $form = new WidgetForm;
            $form->disableReset();
            //$form->action($custom_value->getUrl(['uri' => 'fileupload']));
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
                    'showPreview' => false,
                    'showCancel' => false,
                    'uploadUrl' => admin_base_paths('data', $this->custom_table->table_name, $id, 'fileupload'),
                    'uploadExtraData'=> [
                        '_token' => csrf_token()
                    ],
                ];
                $options_json = json_encode($options);

                $input_id = 'file_data';
                $form->file($input_id, trans('admin.upload'))
                ->options($options)
                ->setLabelClass(['d-none'])
                ->setWidth(12,0);
                // // create file upload option
    //             $form->html('<input type="file" id="'.$input_id.'" />')->plain();
                $script = <<<EOT
    $(".$input_id").on('fileuploaded', function(e, params) {
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
            $form->attribute(['class' => 'form-horizontal form-revision']);
    
            foreach ($revisions as $index => $revision) {
                $form->html(
                    view('exment::form.field.revisionlink', [
                        'revision' => $revision,
                        'link' => admin_base_paths('data', $this->custom_table->table_name, $id, 'compare?revision='.$revision->suuid),
                        'index' => $index,
                    ])->render()
                    , 'No.'.($revision->revision_no)
                )->setWidth(9,2);
            }
            $row->column(6, (new Box('更新履歴', $form))->style('info'));        
        }
    }
    
    /**
     * compare
     */
    public function compare(Request $request, $id, Content $content)
    {
        $this->firstFlow($request, $id);
        $this->AdminContent($content);
        $content->body($this->getRevisionCompare($id, $request->get('revision')));
        return $content;
    }
   
    /**
     * get compare item for pjax
     */
    public function compareitem(Request $request, $id, Content $content)
    {
        $this->firstFlow($request, $id);
        return $this->getRevisionCompare($id, $request->get('revision'), true);
    }
   
    /**
     * restore data
     */
    public function restoreRevision(Request $request, $id)
    {
        $this->firstFlow($request, $id);
        
        $revision_suuid = $request->get('revision');
        $custom_value = $this->getModelNameDV()::find($id);
        $custom_value->setRevision($revision_suuid)->save();
        return redirect($custom_value->getUrl());
    }
  
    /**
     * gt revision compare.
     */
    protected function getRevisionCompare($id, $revision_suuid = null, $pjax = false)
    {
        $table_name = $this->custom_table->table_name;
        // get all revisions
        $revisions = $this->getRevisions($id, false, true);
        $newest_revision = $revisions->first();
        $newest_revision_suuid = $newest_revision->suuid;
        if(!isset($revision_suuid)){
            $revision_suuid = $newest_revision_suuid ?? null;
        }

        // create revision value
        $old_revision = Revision::findBySuuid($revision_suuid);
        $revision_value = $this->getModelNameDV()::find($id)->setRevision($revision_suuid);
        $custom_value = $this->getModelNameDV()::find($id);

        // set table columns
        $table_columns = [];
        foreach($this->custom_table->custom_columns as $custom_column){
            $revision_value_column = $revision_value->getValue($custom_column, true);
            $custom_value_column = $custom_value->getValue($custom_column, true);

            $table_columns[] = [
                'old_value' => $revision_value_column,
                'new_value' => $custom_value_column,
                'diff' => $revision_value_column != $custom_value_column,
                'label' => $custom_column->column_view_name,
            ];
        }

        $prms = [
            'change_page_menu' => (new Tools\GridChangePageMenu('data', $this->custom_table, false))->render(),
            'revisions' => $revisions,
            'custom_value' => $custom_value,
            'table_columns' => $table_columns,
            'newest_revision' => $newest_revision,
            'newest_revision_suuid' => $newest_revision_suuid,
            'old_revision' => $old_revision,
            'revision_suuid' => $revision_suuid,
            'form_url' => admin_base_paths('data', $table_name, $id, 'compare'),
            'has_diff' => collect($table_columns)->filter(function($table_column){
                return array_get($table_column, 'diff', false);
            })->count() > 0
        ];

        if($pjax){
            return view("exment::custom-value.revision-compare-inner", $prms);    
        }
        
        $script = <<<EOT
        $("#revisions").off('change').on('change', function(e, params) {
            var url = admin_base_path(URLJoin('data', '$table_name', '$id', 'compare'));
            var query = {'revision': $(e.target).val()};

            $.pjax({container:'#pjax-container-revision', url: url +'?' + $.param(query) });
        });
    
EOT;
        Admin::script($script);
        
        return view("exment::custom-value.revision-compare", $prms);
    }
    

    /**
     * whether file upload field
     */
    protected function useFileUpload($modal = false){
        return !$modal && boolval($this->custom_table->getOption('attachment_flg') ?? true);
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
    
    /**
     * get target data revisions
     */
    protected function getRevisions($id, $modal = false, $all = false){
        if ($modal || !boolval($this->custom_table->getOption('revision_flg'))) {
            return [];
        }

        $query = $this->getModelNameDV()::find($id)
            ->revisionHistory()
            ->orderby('id', 'desc');
        
        // if not all
        if(!$all){
            $query = $query->take(10);
        }
        return $query->get() ?? [];
    }
}
