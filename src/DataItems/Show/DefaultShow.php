<?php

namespace Exceedone\Exment\DataItems\Show;

use Illuminate\Http\Request;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Row;
use Exceedone\Exment\Form\Show;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Form\Field;
use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Revisionable\Revision;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Services\PartialCrudService;
use Exceedone\Exment\DataItems\DataTrait;

class DefaultShow extends ShowBase
{
    use DataTrait;

    public function __construct($custom_table, $custom_form)
    {
        $this->custom_table = $custom_table;
        $this->custom_form = $custom_form;
    }

    /**
     * set option boxes.
     * contains file uploads, revisions
     */
    public function setOptionBoxes($row)
    {
        $this->setChildBlockBox($row);

        $this->setDocumentBox($row);

        $this->setRevisionBox($row);
 
        $this->setCommentBox($row);
    }
    
    /**
     * create show form list
     */
    public function createShowForm()
    {
        return new Show($this->custom_value, function (Show $show) {
            if (!$this->modal) {
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

                if ($this->modal) {
                    $field->setWidth(9, 3);
                }
            }

            // loop for custom form blocks
            foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
                // whether update set width
                $updateSetWidth = $custom_form_block->isMultipleColumn() || $this->modal;
    
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
                        if (boolval(config('exment.hide_hiddenfield', false)) && boolval(array_get($form_column, 'options.hidden', false))) {
                            continue;
                        }

                        $item = $form_column->column_item;
                        if (!isset($item)) {
                            continue;
                        }
                        $field = $show->field($item->name(), $item->label(), array_get($form_column, 'column_no'))
                            ->as(function ($v) use ($item) {
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
            }
            
            // if modal, disable list and delete
            $show->panel()->tools(function ($tools) {
                $enableEdit = $this->custom_value->enableEdit(true);
                if ($this->custom_value->enableEdit(true) !== true) {
                    $tools->disableEdit();
                }
                if ($this->custom_value->enableDelete(true) !== true) {
                    $tools->disableDelete();
                }

                if (!is_null($parent_value = $this->custom_value->getParentValue()) && $parent_value->enableEdit(true) !== true) {
                    $tools->disableEdit();
                    $tools->disableDelete();
                }

                if ($this->modal) {
                    $tools->disableList();
                    $tools->disableDelete();

                    $tools->append(view('exment::tools.button', [
                        'href' => $this->custom_value->getUrl(),
                        'label' => trans('admin.show'),
                        'icon' => 'fa-eye',
                        'btn_class' => 'btn-default',
                    ]));
                }

                if (count($this->custom_table->getRelationTables()) > 0) {
                    $tools->append(view('exment::tools.button', [
                        'href' => $this->custom_value->getRelationSearchUrl(true),
                        'label' => exmtrans('search.header_relation'),
                        'icon' => 'fa-compress',
                        'btn_class' => 'btn-purple',
                    ]));
                }

                if ($this->custom_table->isOneRecord()) {
                    $tools->disableList();
                } elseif (!$this->modal) {
                    $tools->setListPath($this->custom_table->getGridUrl(true));
                    
                    if ($this->custom_table->enableTableMenuButton()) {
                        $tools->append((new Tools\CustomTableMenuButton('data', $this->custom_table)));
                    }

                    $listButtons = Plugin::pluginPreparingButton(PluginEventTrigger::FORM_MENUBUTTON_SHOW, $this->custom_table);
                    $copyButtons = $this->custom_table->from_custom_copies;
                    $notifies = $this->custom_table->notifies;
                    $operations = $this->custom_table->operations;
     
                    // only not trashed
                    if (!$this->custom_value->trashed()) {
                        foreach ($listButtons as $plugin) {
                            $tools->append(new Tools\PluginMenuButton($plugin, $this->custom_table, $this->custom_value->id));
                        }

                        foreach ($this->custom_value->getWorkflowActions(true)->reverse() as $action) {
                            $tools->append(new Tools\ModalMenuButton(
                                admin_urls("data", $this->custom_table->table_name, $this->custom_value->id, "actionModal"),
                                [
                                    'label' => array_get($action, 'action_name'),
                                    'expand' => ['action_id' => array_get($action, 'id')],
                                    'button_class' => 'btn-success',
                                    'icon' => 'fa-check-square',
                                ]
                            ));
                        }
                            
                        foreach ($copyButtons as $copyButton) {
                            $b = new Tools\CopyMenuButton($copyButton, $this->custom_table, $this->custom_value->id);
                        
                            $tools->append($b->toHtml());
                        }
                        foreach ($notifies as $notify) {
                            if ($notify->isNotifyTarget($this->custom_value, NotifyTrigger::BUTTON)) {
                                $tools->append(new Tools\NotifyButton($notify, $this->custom_table, $this->custom_value->id));
                            }
                        }
                        foreach ($operations as $operation) {
                            if ($operation->isOperationTarget($this->custom_value, CustomOperationType::BUTTON)) {
                                $tools->append(new Tools\OperationButton($operation, $this->custom_table, $this->custom_value->id));
                            }
                        }

                        // check share permission.
                        if ($this->custom_value->enableShare() === true) {
                            $tools->append(new Tools\ShareButton(
                                $this->custom_value->id,
                                admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, "shareClick")
                            ));
                        }
                    }
                    // only trashed
                    else {
                        if ($enableEdit === true || $enableEdit == ErrorCode::ALREADY_DELETED) {
                            $tools->disableDelete();

                            // add hard delete button
                            $tools->prepend(new Tools\SwalInputButton([
                                'url' => admin_urls_query("data", $this->custom_table->table_name, $this->custom_value->id, ['trashed' => 1]),
                                'label' => exmtrans('custom_value.hard_delete'),
                                'icon' => 'fa-trash',
                                'btn_class' => 'btn-danger',
                                'title' => exmtrans('custom_value.hard_delete'),
                                'text' => exmtrans('custom_value.message.hard_delete'),
                                'method' => 'delete',
                                'redirectUrl' => admin_urls("data", $this->custom_table->table_name),
                            ]));

                            // add restore button
                            $tools->prepend(new Tools\SwalInputButton([
                                'url' => admin_urls("data", $this->custom_table->table_name, $this->custom_value->id, "restoreClick"),
                                'label' => exmtrans('custom_value.restore'),
                                'icon' => 'fa-undo',
                                'btn_class' => 'btn-warning',
                                'title' => exmtrans('custom_value.message.restore'),
                                'method' => 'get',
                            ]));
                        }
                    }
                    
                    PartialCrudService::setAdminShowTools($this->custom_table, $tools, $this->custom_value->id);
                }
            });
        });
    }

    /**
     * Append child block box.
     *
     * @param Row $row
     * @param CustomValue $this->custom_value
     * @param int $id
     * @param boolean $modal
     * @return void
     */
    protected function setChildBlockBox($row)
    {
        // if modal, dont show children
        if ($this->modal) {
            return;
        }

        // loop for custom form blocks
        foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
            // if available is false, continue
            if (!$custom_form_block->available) {
                continue;
            }
            ////// default block(no relation block)
            if (array_get($custom_form_block, 'form_block_type') == FormBlockType::DEFAULT) {
                continue;
            }
            ////// relation block
            else {
                list($relation_name, $block_label) = $this->getRelationName($custom_form_block);
                $target_table = $custom_form_block->target_table;
                if (!isset($target_table)) {
                    return;
                }
                // if user doesn't have permission, hide child block
                if ($target_table->enableView() !== true) {
                    continue;
                }

                $classname = getModelName($target_table);
                $grid = new Grid(new $classname);
                $grid->setTitle($block_label);
                
                // one to many
                if ($custom_form_block->form_block_type == FormBlockType::ONE_TO_MANY) {
                    // append filter
                    $grid->model()->where('parent_id', $this->custom_value->id);
                }
                // one to many
                elseif ($custom_form_block->form_block_type == FormBlockType::MANY_TO_MANY) {
                    // first, getting children ids
                    $children_ids = $this->custom_value->{$relation_name}()->get()->pluck('id');
                    // second, filtering children ids
                    $grid->model()->whereIn('id', $children_ids->toArray());
                }
                
                $custom_view = CustomView::getAllData($target_table);
                $custom_view->setGrid($grid);
                $custom_view->setValueSort($grid->model());
                
                $grid->disableFilter();
                $grid->disableCreateButton();
                $grid->disableExport();

                $custom_value = $this->custom_value;
                $grid->tools(function ($tools) use ($custom_value, $target_table, $custom_form_block) {
                    // Add new button if one_to_many
                    if ($custom_form_block->form_block_type == FormBlockType::ONE_TO_MANY && $custom_value->enableEdit(true) === true && $target_table->enableCreate(true) === true) {
                        $tools->append(view(
                            'exment::custom-value.new-button',
                            ['table_name' => $target_table->table_name, 'params' => ['select_parent' => $this->custom_value->id]]
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

                $row->column(['xs' => 12, 'sm' => 12], $grid->render());
            }
        }
    }

    /**
     * get revision compare.
     */
    public function getRevisionCompare($revision_suuid = null, $pjax = false)
    {
        $table_name = $this->custom_table->table_name;
        // get all revisions
        $revisions = $this->getRevisions(true);
        $newest_revision = $revisions->first();
        $newest_revision_suuid = $newest_revision->suuid;
        if (!isset($revision_suuid)) {
            $revision_suuid = $newest_revision_suuid ?? null;
        }

        // create revision value
        $id = $this->custom_value->id;
        $old_revision = Revision::findBySuuid($revision_suuid);
        $revision_value = getModelName($this->custom_table)::withTrashed()->find($id)->setRevision($revision_suuid);
        $custom_value = getModelName($this->custom_table)::withTrashed()->find($id);

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

        $trashed = boolval(request()->get('trashed'));

        $change_page_menu = null;
        if ($this->custom_table->enableTableMenuButton()) {
            $change_page_menu = (new Tools\CustomTableMenuButton('data', $this->custom_table));
        }

        $prms = [
            'change_page_menu' => $change_page_menu,
            'revisions' => $revisions,
            'custom_value' => $custom_value,
            'table_columns' => $table_columns,
            'newest_revision' => $newest_revision,
            'newest_revision_suuid' => $newest_revision_suuid,
            'old_revision' => $old_revision,
            'revision_suuid' => $revision_suuid,
            'trashed' => $trashed || !is_nullorempty(array_get($newest_revision, 'deleted_at')) || !is_nullorempty(array_get($old_revision, 'deleted_at')),
            'has_edit_permission' => $custom_value->enableEdit(true) === true,
            'show_url' => $custom_value->getUrl() . ($trashed ? '?trashed=1' : ''),
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
            let url = admin_url(URLJoin('data', '$table_name', '$id', 'compare'));
            let query = {'revision': $(e.target).val()};

            if('$trashed' == true){
                query['trashed'] = 1;
            }

            $.pjax({container:'#pjax-container-revision', url: url +'?' + $.param(query) });
        });
    
EOT;
        Admin::script($script);
        
        return view("exment::custom-value.revision-compare", $prms);
    }

    protected function setRevisionBox($row)
    {
        $revisions = $this->getRevisions();

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
                    'link' => admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, 'compare?revision='.$revision->suuid . (boolval(request()->get('trashed')) ? '&trashed=1' : '')),
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
    protected function useFileUpload()
    {
        // if no permission, return
        if ($this->custom_value->enableEdit() !== true) {
            return false;
        }
        
        return !$this->modal && boolval($this->custom_table->getOption('attachment_flg') ?? true);
    }
    
    /**
     * whether comment field
     */
    protected function useComment()
    {
        return !$this->modal && boolval($this->custom_table->getOption('comment_flg') ?? true);
    }
    
    protected function getDocuments()
    {
        if ($this->modal) {
            return [];
        }
        return $this->custom_value->getDocuments();
    }
    
    protected function setDocumentBox($row)
    {
        $documents = $this->getDocuments();
        $useFileUpload = $this->useFileUpload();

        if (count($documents) == 0 && !$useFileUpload) {
            return;
        }

        $form = new WidgetForm;
        $form->disableReset();
        $form->disableSubmit();

        // show document list
        if (count($documents) > 0) {
            $html = [];
            foreach ($documents as $index => $d) {
                $html[] = "<p>" . view('exment::form.field.documentlink', [
                    'document' => $d,
                    'candelete' => $this->custom_value->enableDelete(true) === true,
                ])->render() . "</p>";
            }
            // loop and add as link
            $form->html(implode("", $html))
                ->plain()
                ->setWidth(8, 3);
        }

        // add file uploader
        if ($useFileUpload) {
            $max_count = config('exment.document_upload_max_count', 5);
            $options = array_merge(Define::FILE_OPTION(), [
                'showUpload' => true,
                'showPreview' => true,
                'showCancel' => false,
                'uploadUrl' => admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, 'fileupload'),
                'uploadExtraData'=> [
                    '_token' => csrf_token()
                ],
                'minFileCount' => 1,
                'maxFileCount' => $max_count,
            ]);

            $options_json = json_encode($options);

            $input_id = 'file_data';

            $form->multipleFile($input_id, trans('admin.upload'))
                ->options($options)
                ->setLabelClass(['d-none'])
                ->help(exmtrans('custom_value.help.document_upload', ['max_size' => bytesToHuman(getUploadMaxFileSize()), 'max_count' => $max_count]))
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
    
    protected function getComments()
    {
        if ($this->modal) {
            return [];
        }
        return getModelName(SystemTableName::COMMENT)
            ::where('parent_id', $this->custom_value->id)
            ->where('parent_type', $this->custom_table->table_name)
            ->get();
    }
    
    protected function setCommentBox($row)
    {
        $useComment = $this->useComment();
        if (!$useComment) {
            return;
        }

        $comments = $this->getComments();
        $form = new WidgetForm;
        $form->disableReset();
        $form->action(admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, 'addcomment'));
        $form->setWidth(10, 2);

        if (count($comments) > 0) {
            $html = [];
            foreach ($comments as $index => $comment) {
                $html[] = "<p>" . view('exment::form.field.commentline', [
                    'comment' => $comment,
                    'table_name' => $this->custom_table->table_name,
                    'isAbleRemove' => ($comment->created_user_id == \Exment::getUserId()),
                    'deleteUrl' => admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, 'deletecomment', $comment->suuid),
                ])->render() . "</p>";
            }
            // loop and add as link
            $form->html(implode("", $html))
                ->plain()
                ->setWidth(8, 3);
        }

        if ($this->custom_value->trashed()) {
            $form->disableSubmit();
        } else {
            $form->textarea('comment', exmtrans("common.comment"))
            ->rows(3)
            ->required()
            ->setLabelClass(['d-none'])
            ->setWidth(12, 0);
        }

        $row->column(['xs' => 12, 'sm' => 6], (new Box(exmtrans("common.comment"), $form))->style('info'));
    }
    
    public function getWorkflowHistory()
    {
        $workflows = $this->custom_value->getWorkflowHistories(true)->toArray();
        if (count($workflows) == 0) {
            return;
        }

        $form = new ModalForm;

        $workflow = $this->custom_value->workflow_value->workflow;

        $form->display('workflow_view_name', exmtrans('workflow.execute_workflow'))
            ->default($workflow->workflow_view_name ?? null);

        $form->display('current_status_name', exmtrans('workflow.current_status_name'))
            ->default($this->custom_value->workflow_status_name);

        $form->hasManyTable('workflow_histories', exmtrans('common.workflow_history'), function ($form) {
            $form->display('created_at', exmtrans("workflow.executed_at"));
            $form->display('workflow_action.action_name', exmtrans("workflow.action_name"));
            $form->display('workflow_action.status_from_to_name', exmtrans("workflow.status"));
            $form->display('created_user', exmtrans("common.executed_user"));
            $form->display('comment', exmtrans("common.comment"));
        })->setTableWidth(12, 0)
        ->disableOptions()
        ->disableHeader()
        ->setRelatedValue($workflows)
        ->setTableColumnWidth(2, 2, 2, 2, 4, 0);
        
        return $form;
    }

    /**
     * restore data
     */
    public function restoreRevision($revision_suuid)
    {
        $this->custom_value->setRevision($revision_suuid)->save();
        return redirect($this->custom_value->getUrl());
    }
  
    /**
     * get target data revisions
     */
    protected function getRevisions($all = false)
    {
        if ($this->modal || !boolval($this->custom_table->getOption('revision_flg', true))) {
            return [];
        }

        if (is_null($this->custom_value->id)) {
            return [];
        }

        // if no permission, return
        if (!$this->custom_table->hasPermissionEditData($this->custom_value->id)) {
            return [];
        }
        
        $query = $this->custom_value
            ->revisionHistory()
            ->orderby('id', 'desc');
        
        // if not all
        if (!$all) {
            $query = $query->take(10);
        }
        return $query->get() ?? [];
    }

    
    /**
     * for file upload function.
     */
    public function fileupload($httpfiles)
    {
        if (is_nullorempty($httpfiles)) {
            return getAjaxResponse([
                'result'  => false,
                'message' => exmtrans('common.message.error_execute'),
            ]);
        }

        // file put(store)
        foreach (toArray($httpfiles) as $httpfile) {
            $filename = $httpfile->getClientOriginalName();
            // $uniqueFileName = ExmentFile::getUniqueFileName($this->custom_table->table_name, $filename);
            // $file = ExmentFile::store($httpfile, config('admin.upload.disk'), $this->custom_table->table_name, $uniqueFileName);
            $custom_value = $this->custom_value;
            $file = ExmentFile::storeAs($httpfile, $this->custom_table->table_name, $filename)
                ->saveCustomValue($custom_value->id, null, $this->custom_table);
            // save document model
            $document_model = $file->saveDocumentModel($custom_value, $filename);
            
            // loop for $notifies
            foreach ($this->custom_table->notifies as $notify) {
                $notify->notifyCreateUpdateUser($custom_value, NotifySavedType::ATTACHMENT, ['attachment' => $filename]);
            }
        }

        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.update_succeeded'),
        ]);
    }

    /**
     * file delete custom column.
     */
    public function filedelete(Request $request, $form)
    {
        // get file delete flg column name
        $del_column_name = $request->input(Field::FILE_DELETE_FLAG);
        /// file remove
        $fields = $form->builder()->fields();
        // filter file
        $fields->filter(function ($field) {
            return $field instanceof Field\Embeds;
        })->each(function ($field) use ($del_column_name) {
            // get fields
            $embedFields = $field->fields();
            $embedFields->filter(function ($field) use ($del_column_name) {
                return $field->column() == $del_column_name;
            })->each(function ($field) use ($del_column_name) {
                // get file path
                $obj = $this->custom_value;
                $original = $obj->getValue($del_column_name, true);
                $field->setOriginal($obj->value);

                $field->destroy(); // delete file
                ExmentFile::deleteFileInfo($original); // delete file table
                $obj->setValue($del_column_name, null)
                    ->remove_file_columns($del_column_name)
                    ->save();
            });
        });

        // reget custom value
        $updated_value = getModelName($this->custom_table)::find($this->custom_value->id);
        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.delete_succeeded'),
            'reload' => false,
            'updateValue' => [
                'updated_at' => $updated_value->updated_at->format('Y-m-d H:i:s'),
            ],
        ]);
    }
 
    /**
     * add comment.
     */
    public function addComment($comment)
    {
        if (!empty($comment)) {
            // save Comment Model
            $model = CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel();
            $model->parent_id = $this->custom_value->id;
            $model->parent_type = $this->custom_table->table_name;
            $model->setValue([
                'comment_detail' => $comment,
            ]);
            $model->save();
                
            // execute notify
            foreach ($this->custom_table->notifies as $notify) {
                $notify->notifyCreateUpdateUser($this->custom_value, NotifySavedType::COMMENT, ['comment' => $comment]);
            }
        }

        $url = admin_urls('data', $this->custom_table->table_name, $this->custom_value->id);
        admin_toastr(trans('admin.save_succeeded'));
        return redirect($url);
    }

 
    /**
     * delete comment.
     */
    public function deleteComment($id, $suuid)
    {
        if (!empty($suuid)) {
            // save Comment Model
            CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel()
                ->where('suuid', $suuid)
                ->where('parent_id', $id)
                ->where('parent_type', $this->custom_table->table_name)
                ->delete();
        }
        return getAjaxResponse([
            'result' => true,
            'toastr' => trans('admin.delete_succeeded'),
        ]);
    }
}
