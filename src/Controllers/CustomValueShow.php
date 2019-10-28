<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Show;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Revisionable\Revision;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Services\PartialCrudService;

/**
 * CustomValueShow
 */
trait CustomValueShow
{
    /**
     * create show form list
     */
    protected function createShowForm($id = null, $modal = false)
    {
        return new Show($this->getModelNameDV()::firstOrNew(['id' => $id]), function (Show $show) use ($id, $modal) {
            $custom_value = $this->custom_table->getValueModel($id);

            if (isset($id) && !$modal) {
                $field = $show->column(null, 8)->system_values()->setWidth(12, 0);
                $field->border = false;
            }

            // add parent link if this form is 1:n relation
            $relation = CustomRelation::getRelationByChild($this->custom_table, RelationType::ONE_TO_MANY);
            if (isset($relation)) {
                $item = ColumnItems\ParentItem::getItem($relation->child_custom_table);

                $field = $show->field($item->name(), $item->label())->as(function ($v) use ($item) {
                    if (is_null($this)) {
                        return '';
                    }
                    return $item->setCustomValue($this)->html();
                })->setEscape(false);

                if ($modal) {
                    $field->setWidth(9, 3);
                }
            }

            // loop for custom form blocks
            foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
                // whether update set width
                $updateSetWidth = $custom_form_block->isMultipleColumn() || $modal;
    
                // if available is false, continue
                if (!$custom_form_block->available) {
                    continue;
                }
                ////// default block(no relation block)
                if (array_get($custom_form_block, 'form_block_type') == FormBlockType::DEFAULT) {
                    $hasMultiColumn = false;

                    foreach ($custom_form_block->custom_form_columns as $form_column) {
                        if ($form_column->form_column_type == FormColumnType::SYSTEM) {
                            continue;
                        }
                        
                        // if hidden field, continue
                        if(boolval(config('exment.hide_hiddenfield', false)) && boolval(array_get($form_column, 'options.hidden', false))){
                            continue;
                        }

                        $item = $form_column->column_item;
                        if (!isset($item)) {
                            continue;
                        }
                        $field = $show->field($item->name(), $item->label(), array_get($form_column, 'column_no'))
                            ->as(function ($v) use ($form_column, $item) {
                                if (is_null($this)) {
                                    return '';
                                }
                                return $item->setCustomValue($this)->html();
                            })->setEscape(false);
                        
                        if ($updateSetWidth) {
                            $field->setWidth(9, 3);
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
                    $show->{$relation_name}($block_label, function ($grid) use ($custom_form_block, $target_table, $id) {
                        $custom_view = CustomView::getDefault($target_table);
                        $custom_view->setGrid($grid);
                        $table_name = $target_table->table_name;
                        
                        $grid->disableFilter();
                        $grid->disableCreateButton();
                        $grid->disableExport();
                        $grid->tools(function ($tools) use ($table_name, $id, $custom_form_block) {
                            // Add new button if one_to_many
                            if ($custom_form_block->form_block_type == FormBlockType::ONE_TO_MANY) {
                                $tools->append(view(
                                    'exment::custom-value.new-button',
                                    ['table_name' => $table_name, 'params' => ['select_parent' => $id]]
                                ));
                            }

                            $tools->batch(function ($batch) {
                                $batch->disableDelete();
                            });
                        });
                        $grid->disableRowSelector();

                        $grid->actions(function ($actions) {
                            $actions->disableView();
                            $actions->disableEdit();
                            $actions->disableDelete();
                        
                            // add show link
                            $actions->append($actions->row->getUrl([
                                'tag' => true,
                                'modal' => true,
                                'icon' => 'fa-external-link',
                                'add_id' => true,
                            ]));
                        });
                    });
                }
            }

            // if user only view permission or one record table, disable delete and view
            $show->panel()->tools(function ($tools) use ($custom_value) {
                if ($custom_value->enableEdit(true) !== true) {
                    $tools->disableEdit();
                }
                if ($custom_value->enableDelete(true) !== true) {
                    $tools->disableDelete();
                }
            });

            // if modal, disable list and delete
            $show->panel()->tools(function ($tools) use ($modal, $custom_value, $id) {
                if ($modal) {
                    $tools->disableList();
                    $tools->disableDelete();

                    $tools->append(view('exment::tools.button', [
                        'href' => $custom_value->getUrl(),
                        'label' => trans('admin.show'),
                        'icon' => 'fa-eye',
                        'btn_class' => 'btn-default',
                    ]));
                }

                if (count($this->custom_table->getRelationTables()) > 0) {
                    $tools->append(view('exment::tools.button', [
                        'href' => $custom_value->getRelationSearchUrl(true),
                        'label' => exmtrans('search.header_relation'),
                        'icon' => 'fa-compress',
                        'btn_class' => 'btn-purple',
                    ]));
                }

                if ($this->custom_table->isOneRecord()) {
                    $tools->disableList();
                } elseif (!$modal) {
                    $tools->setListPath($this->custom_table->getGridUrl(true));
                    $tools->append((new Tools\GridChangePageMenu('data', $this->custom_table, false))->render());

                    $listButtons = Plugin::pluginPreparingButton($this->plugins, 'form_menubutton_show');
                    $copyButtons = $this->custom_table->from_custom_copies;
                    $notifies = $this->custom_table->notifies;

                    foreach ($listButtons as $plugin) {
                        $tools->append(new Tools\PluginMenuButton($plugin, $this->custom_table, $id));
                    }
                    foreach ($custom_value->getWorkflowActions(true)->reverse() as $action) {
                        $tools->append(new Tools\WorkflowMenuButton($action, $this->custom_table, $id));
                    }
                    foreach ($copyButtons as $copyButton) {
                        $b = new Tools\CopyMenuButton($copyButton, $this->custom_table, $id);
                    
                        $tools->append($b->toHtml());
                    }
                    foreach ($notifies as $notify) {
                        if ($notify->isNotifyTarget($custom_value, NotifyTrigger::BUTTON)) {
                            $tools->append(new Tools\NotifyButton($notify, $this->custom_table, $id));
                        }
                    }

                    // check share permission.
                    if ($this->hasPermissionShare($id)) {
                        $tools->append(new Tools\ShareButton($this->custom_table, $id));
                    }

                    PartialCrudService::setAdminShowTools($this->custom_table, $tools, $id);
                }
            });
        });
    }

    /**
     * set option boxes.
     * contains file uploads, revisions
     */
    protected function setOptionBoxes($row, $id, $modal = false)
    {
        $custom_value = $this->custom_table->getValueModel($id);
        
        $this->setDocumentBox($row, $custom_value, $id, $modal);

        $this->setRevisionBox($row, $custom_value, $id, $modal);
 
        $this->setCommentBox($row, $custom_value, $id, $modal);
    }
    
    /**
     * compare
     */
    public function compare(Request $request, Content $content, $tableKey, $id)
    {
        $this->firstFlow($request, $id);
        $this->AdminContent($content);
        $content->body($this->getRevisionCompare($id, $request->get('revision')));
        return $content;
    }
   
    /**
     * get compare item for pjax
     */
    public function compareitem(Request $request, Content $content, $tableKey, $id)
    {
        $this->firstFlow($request, $id);
        return $this->getRevisionCompare($id, $request->get('revision'), true);
    }
   
    /**
     * restore data
     */
    public function restoreRevision(Request $request, $tableKey, $id)
    {
        $this->firstFlow($request, $id);
        
        $revision_suuid = $request->get('revision');
        $custom_value = $this->getModelNameDV()::find($id);
        $custom_value->setRevision($revision_suuid)->save();
        return redirect($custom_value->getUrl());
    }
  
    /**
     * get revision compare.
     */
    protected function getRevisionCompare($id, $revision_suuid = null, $pjax = false)
    {
        $table_name = $this->custom_table->table_name;
        // get all revisions
        $revisions = $this->getRevisions($id, false, true);
        $newest_revision = $revisions->first();
        $newest_revision_suuid = $newest_revision->suuid;
        if (!isset($revision_suuid)) {
            $revision_suuid = $newest_revision_suuid ?? null;
        }

        // create revision value
        $old_revision = Revision::findBySuuid($revision_suuid);
        $revision_value = $this->getModelNameDV()::find($id)->setRevision($revision_suuid);
        $custom_value = $this->getModelNameDV()::find($id);

        // set table columns
        $table_columns = [];
        foreach ($this->custom_table->custom_columns as $custom_column) {
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
            'has_edit_permission' => $custom_value->enableEdit(true) === true,
            'form_url' => admin_urls('data', $table_name, $id, 'compare'),
            'has_diff' => collect($table_columns)->filter(function ($table_column) {
                return array_get($table_column, 'diff', false);
            })->count() > 0
        ];

        if ($pjax) {
            return view("exment::custom-value.revision-compare-inner", $prms);
        }
        
        $script = <<<EOT
        $("#revisions").off('change').on('change', function(e, params) {
            var url = admin_url(URLJoin('data', '$table_name', '$id', 'compare'));
            var query = {'revision': $(e.target).val()};

            $.pjax({container:'#pjax-container-revision', url: url +'?' + $.param(query) });
        });
    
EOT;
        Admin::script($script);
        
        return view("exment::custom-value.revision-compare", $prms);
    }

    protected function setRevisionBox($row, $custom_value, $id, $modal = false)
    {
        $revisions = $this->getRevisions($id, $modal);

        if (count($revisions) == 0) {
            return;
        }
    
        $form = new WidgetForm;
        $form->disableReset();
        $form->disableSubmit();
        $form->attribute(['class' => 'form-horizontal form-revision']);

        foreach ($revisions as $index => $revision) {
            $form->html(
                view('exment::form.field.revisionlink', [
                    'revision' => $revision,
                    'link' => admin_urls('data', $this->custom_table->table_name, $id, 'compare?revision='.$revision->suuid),
                    'index' => $index,
                ])->render(),
                'No.'.($revision->revision_no)
            )->setWidth(9, 2);
        }
        $row->column(['xs' => 12, 'sm' => 6], (new Box(exmtrans('revision.update_history'), $form))->style('info'));
    }
    
    /**
     * whether file upload field
     */
    protected function useFileUpload($custom_value, $modal = false)
    {
        // if no permission, return
        if ($custom_value->enableEdit() !== true) {
            return false;
        }
        
        return !$modal && boolval($this->custom_table->getOption('attachment_flg') ?? true);
    }
    
    /**
     * whether comment field
     */
    protected function useComment($modal = false)
    {
        return !$modal && boolval($this->custom_table->getOption('comment_flg') ?? true);
    }
    
    protected function getDocuments($id, $modal = false)
    {
        if ($modal) {
            return [];
        }
        return getModelName(SystemTableName::DOCUMENT)
            ::where('parent_id', $id)
            ->where('parent_type', $this->custom_table->table_name)
            ->get();
    }
    
    protected function setDocumentBox($row, $custom_value, $id, $modal = false)
    {
        $documents = $this->getDocuments($id, $modal);
        $useFileUpload = $this->useFileUpload($custom_value, $modal);

        if (count($documents) == 0 && !$useFileUpload) {
            return;
        }

        $form = new WidgetForm;
        $form->disableReset();
        $form->disableSubmit();

        // show document list
        if (isset($id)) {
            if (count($documents) > 0) {
                $html = [];
                foreach ($documents as $index => $d) {
                    $html[] = "<p>" . view('exment::form.field.documentlink', [
                        'document' => $d,
                        'candelete' => $custom_value->enableDelete(true) === true,
                    ])->render() . "</p>";
                }
                // loop and add as link
                $form->html(implode("", $html))
                    ->plain()
                    ->setWidth(8, 3);
            }
        }

        // add file uploader
        if ($useFileUpload) {
            $options = [
                'showUpload' => true,
                'showPreview' => false,
                'showCancel' => false,
                'uploadUrl' => admin_urls('data', $this->custom_table->table_name, $id, 'fileupload'),
                'uploadExtraData'=> [
                    '_token' => csrf_token()
                ],
            ];
            $options_json = json_encode($options);

            $input_id = 'file_data';

            $form->file($input_id, trans('admin.upload'))
                ->options($options)
                ->setLabelClass(['d-none'])
                ->setWidth(12, 0);
            $script = <<<EOT
    $(".$input_id").on('fileuploaded', function(e, params) {
        console.log('file uploaded', e, params);
        $.pjax.reload('#pjax-container');
    });
EOT;

            Admin::script($script);
        }

        $row->column(['xs' => 12, 'sm' => 6], (new Box(exmtrans("common.attachment"), $form))->style('info'));
    }
    
    protected function getComments($id, $modal = false)
    {
        if ($modal) {
            return [];
        }
        return getModelName(SystemTableName::COMMENT)
            ::where('parent_id', $id)
            ->where('parent_type', $this->custom_table->table_name)
            ->get();
    }
    
    protected function setCommentBox($row, $custom_value, $id, $modal = false)
    {
        $useComment = $this->useComment($modal);
        if (!$useComment) {
            return;
        }

        $comments = $this->getComments($id, $modal);
        $form = new WidgetForm;
        $form->disableReset();
        $form->action(admin_urls('data', $this->custom_table->table_name, $id, 'addcomment'));
        $form->setWidth(10, 2);

        if (count($comments) > 0) {
            $html = [];
            foreach ($comments as $index => $comment) {
                $html[] = "<p>" . view('exment::form.field.commentline', [
                    'comment' => $comment,
                    'table_name' => $this->custom_table->table_name,
                    'isAbleRemove' => ($comment->created_user_id == \Exment::user()->base_user_id),
                ])->render() . "</p>";
            }
            // loop and add as link
            $form->html(implode("", $html))
                ->plain()
                ->setWidth(8, 3);
        }

        $form->textarea('comment', exmtrans("common.comment"))
            ->rows(3)
            ->required()
            ->setLabelClass(['d-none'])
            ->setWidth(12, 0);

        $row->column(['xs' => 12, 'sm' => 6], (new Box(exmtrans("common.comment"), $form))->style('info'));
    }
    
    protected function getWorkflowHistory($custom_value, $id)
    {
        $workflows = $custom_value->getWorkflowHistories(true)->toArray();
        if (count($workflows) == 0) {
            return;
        }

        $form = new ModalForm;

        $workflow = $custom_value->workflow_value->workflow;

        $form->display('workflow_view_name', exmtrans('workflow.execute_workflow'))
            ->default($workflow->workflow_view_name ?? null);

        $form->display('current_status_name', exmtrans('workflow.current_status_name'))
            ->default($custom_value->workflow_status_name);

        $form->hasManyTable('workflow_histories', exmtrans('common.workflow_history'), function ($form) {
            $form->display('created_at', exmtrans("workflow.executed_at"));
            $form->display('workflow_action.action_name', exmtrans("workflow.action_name"));
            $form->display('workflow_action.status_from_to_name', exmtrans("workflow.status"));
            $form->display('created_user', exmtrans("workflow.executed_user"));
            $form->display('comment', exmtrans("common.comment"));
        })->setTableWidth(12, 0)
        ->disableOptions()
        ->disableHeader()
        ->setRelatedValue($workflows)
        ->setTableColumnWidth(2, 2, 2, 2, 4, 0);

        // $html = [];
        // foreach ($workflows as $index => $workflow) {
        //     $form->html(
        //         view('exment::form.field.workflowline', [
        //             'workflow' => $workflow,
        //             'index' => $index,
        //         ])->render(),
        //         'No.'. ($index + 1)
        //     )->setWidth(10, 2);
        // }
        
        return $form;
    }

    /**
     * get target data revisions
     */
    protected function getRevisions($id, $modal = false, $all = false)
    {
        if ($modal || !boolval($this->custom_table->getOption('revision_flg'))) {
            return [];
        }

        if (is_null($id)) {
            return [];
        }

        // if no permission, return
        if (!$this->custom_table->hasPermissionEditData($id)) {
            return [];
        }
        
        $query = $this->getModelNameDV()::find($id)
            ->revisionHistory()
            ->orderby('id', 'desc');
        
        // if not all
        if (!$all) {
            $query = $query->take(10);
        }
        return $query->get() ?? [];
    }

    /**
     * Whether showing share button
     *
     * @return boolean
     */
    protected function hasPermissionShare($id)
    {
        // if system doesn't use role, return false
        if (!System::permission_available()) {
            return false;
        }

        // if master, false
        if (in_array($this->custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_MASTER())) {
            return false;
        }

        // if not has edit data, return false
        if (!$this->custom_table->hasPermissionEditData($id)) {
            return false;
        }

        // if not has share data, return false
        if (!$this->custom_table->hasPermission(Permission::CUSTOM_VALUE_SHARE)) {
            return false;
        }

        return true;
    }
}
