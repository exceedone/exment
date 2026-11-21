<?php

namespace Exceedone\Exment\DataItems\Show;

use Exceedone\Exment\Services\ReferencePrefetcher;
use Illuminate\Http\Request;
use ExmentAdminCore\Admin\Facades\Admin;
use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Layout\Row;
use ExmentAdminCore\Admin\Widgets\Box;
use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
use ExmentAdminCore\Admin\Form\Field;
use ExmentAdminCore\Admin\Show;
use ExmentAdminCore\Admin\Show\Field as ShowField;
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
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Services\CommentMarkdown;
use Exceedone\Exment\Services\DocumentVersionService;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\ShowGridType;
use Exceedone\Exment\Enums\ShowPositionType;
use Exceedone\Exment\Services\PartialCrudService;
use Exceedone\Exment\ColumnItems\ItemInterface;
use Illuminate\Support\Str;

/** @phpstan-consistent-constructor */
class DefaultShow extends ShowBase
{
    // @phpstan-ignore-next-line
    protected static $showClassName = \Exceedone\Exment\Form\Show::class;

    /**
     * Set row count. if contains parent or system values, set increment.
     *
     * @var integer
     */
    protected $rowCount = 0;

    // @phpstan-ignore-next-line
    public function __construct($custom_table, $custom_form)
    {
        $this->custom_table = $custom_table;
        $this->custom_form = $custom_form;
        $this->rowCount = 0;
    }

    /**
     * set option boxes.
     * contains file uploads, revisions
     */
    // @phpstan-ignore-next-line
    public function setOptionBoxes($row)
    {
        $this->setChildBlockBox($row);

        $this->setDocumentBox($row);

        $this->setFileServerBox($row);

        $this->setRevisionBox($row);

        $this->setCommentBox($row);
    }

    /**
     * set system values
     *
     * @param $show
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    // @phpstan-ignore-next-line
    protected function setSystemValues($show)
    {
        $trashed = boolval(request()->get('trashed'));
        /** @phpstan-ignore-next-line class ExmentAdminCore\Admin\Show\Field constructor expects string, null given */
        $field = (new ShowField(null, null))->system_values([
            'withTrashed' => $trashed])->setWidth(12, 0);

        $show->addFieldAndOption($field, [
            'row' => $this->rowCount++,
            'column' => 1,
            'width' => 4,
            'calcWidth' => false,
        ]);
        $field->border = false;
    }

    /**
     * create show form list
     */
    // @phpstan-ignore-next-line
    public function createShowForm()
    {
        return new static::$showClassName($this->custom_value, function ($show) {
            $system_values_pos = $this->custom_table->getSystemValuesPosition();

            if (!$this->modal && $system_values_pos == ShowPositionType::TOP) {
                $this->setSystemValues($show);
            }

            if ($this->gridShows()) {
                $show->gridShows();
            }

            // add parent link
            $relations = CustomRelation::getRelationsByChild($this->custom_table);
            if (isset($relations)) {
                foreach ($relations as $relation) {
                    $item = ColumnItems\ParentItem::getItemWithParent($relation->child_custom_table, $relation->parent_custom_table);
                    $this->setColumnItemOption($item);

                    $field = new ShowField($item->name(), $item->label());
                    $field->as(function ($v) use ($item) {
                        // @phpstan-ignore-next-line
                        if (is_null($this)) {
                            return '';
                        }
                        return $item->setCustomValue($this)->html();
                    })->setEscape(false);

                    if ($this->modal) {
                        $field->setWidth(9, 3);
                    }
                    $show->addFieldAndOption($field, [
                        'row' => $this->rowCount++,
                        'column' => 1,
                        'width' => 4,
                        'calcWidth' => false,
                    ]);
                }
            }

            // loop for custom form blocks
            foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
                ////// default block(no relation block)
                if (array_get($custom_form_block, 'form_block_type') != FormBlockType::DEFAULT) {
                    continue;
                }
                $this->setByCustomFormBlock($show, $custom_form_block);
            }

            if (!$this->modal && $system_values_pos == ShowPositionType::BOTTOM) {
                $this->rowCount = $show->getMaxRow() + 1;
                $this->setSystemValues($show);
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

                if ($this->custom_table->enableView(true) !== true) {
                    $tools->disableList();
                }

                if (!is_null($parent_value = $this->custom_value->getParentValue(null, true))) {
                    if (boolval($this->custom_table->getOption('editable_with_parent')??1)) {
                        if ($parent_value->enableEdit(true, false) !== true) {
                            $tools->disableEdit();
                            $tools->disableDelete();
                        }
                    } elseif ($parent_value->enableAccess() !== true)  {
                        $tools->disableEdit();
                        $tools->disableDelete();
                    }
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

                // not for trashed data: relation search targets undeleted data only
                if (count($this->custom_table->getRelationTables()) > 0 && !$this->custom_value->trashed()) {
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

                        // no button for an approval this user already gave (TC-WT-43)
                        foreach ($this->custom_value->getWorkflowActions(true, false, true)->reverse() as $action) {
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

                            $tools->append($b->render());
                        }
                        foreach ($notifies as $notify) {
                            if ($notify->isNotifyTarget($this->custom_value, NotifyTrigger::BUTTON)) {
                                $tools->append(new Tools\NotifyButton($notify, $this->custom_table, $this->custom_value->id));
                            }
                        }

                        foreach ($operations as $operation) {
                            if (!$this->enableOperationButton($operation, $enableEdit)) {
                                continue;
                            }
                            if ($operation->active_flg && $operation->isOperationTarget($this->custom_value, CustomOperationType::BUTTON)) {
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

                            // if parent data does not exist or has not been deleted 
                            if (!$parent_value || !$parent_value->trashed()) {
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
                    }

                    PartialCrudService::setAdminShowTools($this->custom_table, $tools, $this->custom_value->id);
                }
            });
        });
    }


    /**
     * Set show item by custom form block
     *
     * @param Show $show
     * @param CustomFormBlock $custom_form_block
     * @return void
     */
    public function setByCustomFormBlock($show, $custom_form_block)
    {
        // if available is false, continue
        if (!$custom_form_block->available) {
            return;
        }
        $hasMultiColumn = false;

        foreach ($custom_form_block->custom_form_columns as $form_column) {
            if ($form_column->form_column_type == FormColumnType::SYSTEM) {
                continue;
            }

            $item = $form_column->column_item;
            if (!isset($item)) {
                continue;
            }

            $this->setColumnItemOption($item, $form_column);

            // if hidden field, continue
            if ($item->disableDisplayWhenShow()) {
                continue;
            }

            $field = new ShowField($item->name(), $item->label());
            $item->setShowFieldOptions($field, [
                'gridShows' => $this->gridShows(),
            ]);

            if ($this->modal) {
                $field->setWidth(9, 3);
            }

            $show->addFieldAndOption($field, [
                'row' => $this->rowCount + $form_column->row_no,
                'column' => $form_column->column_no,
                'width' => $form_column->width ?? 4,
            ]);
        }
    }

    /**
     * Append child block box.
     *
     * @param Row $row
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function setChildBlockBox($row)
    {
        // if modal, dont show children
        if ($this->modal) {
            return;
        }

        $trashed = boolval(request()->get('trashed'));

        $custom_form_blocks = $this->custom_form->custom_form_blocks->sortBy(function ($item, $key) {
            return $item->getOption('form_block_order')?? -1;
        });
        // loop for custom form blocks
        foreach ($custom_form_blocks as $custom_form_block) {
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
                list($relation, $relation_name, $block_label) = $custom_form_block->getRelationInfo();
                $target_table = $custom_form_block->target_table;
                if (!isset($target_table)) {
                    return;
                }
                // if user doesn't have permission, hide child block
                if ($target_table->enableView() !== true) {
                    continue;
                }

                $classname = getModelName($target_table);
                $grid = new Grid(new $classname());
                $grid->setTitle($block_label);
                $grid->setName($target_table->table_name);
                $grid->model()->setSortName($target_table->table_name . '_sort');

                // one to many
                if ($custom_form_block->form_block_type == FormBlockType::ONE_TO_MANY) {
                    $table_name = getDBTableName($target_table);
                    // append filter
                    $grid->model()
                        ->where("$table_name.parent_id", $this->custom_value->id)
                        ->where("$table_name.parent_type", $this->custom_table->table_name);
                    if ($trashed) {
                        $grid->model()->withTrashed();
                    }
                }
                // many to many
                elseif ($custom_form_block->form_block_type == FormBlockType::MANY_TO_MANY) {
                    $table_name = getDBTableName($target_table);
                    // first, getting children ids
                    $children_ids = $this->custom_value->{$relation_name}()->get()->pluck('id');
                    // second, filtering children ids
                    $grid->model()->whereIn("$table_name.id", $children_ids->toArray());
                }

                $custom_view = CustomView::getAllData($target_table);
                $custom_view->setGrid($grid);
                $custom_view->setValueSort($grid->model());

                // the records and files the rows point at, read together as in the list (ISS00413)
                // (the callbacks are kept for every grid of the request: only for this one)
                $grid->getDataCallback(function ($target) use ($grid, $target_table, $custom_view) {
                    if ($target !== $grid) {
                        return;
                    }
                    $customValueCollection = $target->getOriginalCollection();
                    $target_table->setSelectTableValues($customValueCollection);
                    ReferencePrefetcher::prefetch($target_table, $customValueCollection, $custom_view->custom_view_columns_cache, false);
                });

                $grid->disableFilter();
                $grid->disableCreateButton();
                $grid->disableExport();

                $custom_value = $this->custom_value;
                $grid->tools(function ($tools) use ($custom_value, $target_table, $custom_form_block) {
                    // Add new button if one_to_many
                    if ($custom_form_block->form_block_type == FormBlockType::ONE_TO_MANY && $custom_value->enableEdit(true, false) === true && $target_table->enableCreate(true) === true) {
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
                // @phpstan-ignore-next-line
                $row->column(['xs' => 12, 'sm' => 12], $grid->render());
            }
        }
    }

    /**
     * get revision compare.
     */
    // @phpstan-ignore-next-line
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

            $.pjax({
                container: '#pjax-container-revision',
                fragment: '#pjax-container-revision',
                url: url +'?' + $.param(query)
            });
        });

EOT;
        Admin::script($script);

        return view("exment::custom-value.revision-compare", $prms);
    }

    // @phpstan-ignore-next-line
    protected function setRevisionBox($row)
    {
        $revisions = $this->getRevisions();

        if (count($revisions) == 0) {
            return;
        }

        $form = new WidgetForm();
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
    // @phpstan-ignore-next-line
    protected function useFileUpload()
    {
        // if no permission, return
        if ($this->custom_value->enableEdit(false, false) !== true) {
            return false;
        }

        return !$this->modal && boolval($this->custom_table->getOption('attachment_flg') ?? true);
    }

    /**
     * whether comment field
     */
    // @phpstan-ignore-next-line
    protected function useComment()
    {
        return !$this->modal && boolval($this->custom_table->getOption('comment_flg') ?? true);
    }

    // @phpstan-ignore-next-line
    protected function getDocuments()
    {
        if ($this->modal) {
            return [];
        }
        return $this->custom_value->getDocuments();
    }

    // @phpstan-ignore-next-line
    protected function setDocumentBox($row)
    {
        $documents = $this->getDocuments();
        $useFileUpload = $this->useFileUpload();

        if (count($documents) == 0 && !$useFileUpload) {
            return;
        }

        $form = new WidgetForm();
        $form->disableReset();
        $form->disableSubmit();

        // show document list
        if (count($documents) > 0) {
            $html = [];
            $allow_delete = config('exment.allow_delete_attachment', false);
            // replacing keeps the older file as a version; the numbers come in one query
            $useVersions = DocumentVersionService::available();
            $documentIds = [];
            foreach ($documents as $d) {
                $documentIds[] = $d->id;
            }
            $versionNos = $useVersions ? DocumentVersionService::currentNumbers($documentIds) : [];
            foreach ($documents as $index => $d) {
                $html[] = "<p>" . view('exment::form.field.documentlink', [
                    'document' => $d,
                    'candelete' => $this->custom_value->enableDelete(!$allow_delete) === true,
                    'canreplace' => $useVersions && $useFileUpload,
                    'version_no' => $versionNos[intval($d->id)] ?? 1,
                    'replace_url' => admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, 'documents', $d->id, 'replace'),
                    'versions_url' => admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, 'documents', $d->id, 'versions'),
                ])->render() . "</p>";
            }
            if ($useVersions) {
                Admin::script(static::documentVersionScript());
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

            $input_id = 'file_data';

            $form->multipleFile($input_id, trans('admin.upload'))
                ->options($options)
                ->setLabelClass(['d-none'])
                ->help(exmtrans('custom_value.help.document_upload', ['max_size' => bytesToHuman(\Exment::getUploadMaxFileSize()), 'max_count' => $max_count]))
                ->setWidth(12, 0);
            $script = <<<EOT
            var uploadCount = null;
            $(".$input_id").on('filepreupload', function(event, data, previewId, index) {
                if(uploadCount === null){
                    uploadCount = data.files.length;
                }
            });
            $(".$input_id").on('fileuploaded', function(e, params, fileId, index) {
                uploadCount--;
                console.log('upload uploadCount : ' + uploadCount);
                console.log('upload index : ' + index);
                if(0 >= uploadCount){
                    $.pjax.reload('#pjax-container');
                }
            });
EOT;

            Admin::script($script);
        }
        $row->column(['xs' => 12, 'sm' => 6], (new Box(exmtrans("common.attachment"), $form))->style('info'));
    }

    /**
     * The folders and files of the file server linked to this data (ISS00378),
     * those the user may see. Nothing when there are none.
     */
    // @phpstan-ignore-next-line
    protected function setFileServerBox($row)
    {
        if ($this->modal || !isset($this->custom_value) || !isset($this->custom_value->id)) {
            return;
        }
        // the file server not used (Feature management): no box
        if (!\Exceedone\Exment\Services\FileServer\Library::featureEnabled()) {
            return;
        }
        try {
            $library = new \Exceedone\Exment\Services\FileServer\Library(\Exceedone\Exment\Services\FileServer\Access::current());
            $nodes = (new \Exceedone\Exment\Services\FileServer\DataLinks($library))->nodesOf($this->custom_table->table_name, intval($this->custom_value->id));
        } catch (\Throwable $e) {
            // the tables of the file server are not there yet (before the update)
            return;
        }
        if (count($nodes) === 0) {
            return;
        }
        $html = view('exment::file_server._data_files', ['nodes' => $nodes])->render();
        $row->column(['xs' => 12, 'sm' => 6], (new Box(exmtrans('file_server.data_files'), $html))->style('info'));
    }

    /**
     * Whether the button of the data update setting is shown for this data.
     *
     * The button is for the users who can edit the data. A data the workflow locks cannot be
     * edited by anyone, so it has no button, unless the setting says to show the button also
     * for such a data (options.button_workflow_locked). CustomValue::enableEdit() answers
     * WORKFLOW_LOCK only after the permission check, so the user still has to be allowed to
     * edit the data.
     *
     * @param \Exceedone\Exment\Model\CustomOperation $operation
     * @param bool|ErrorCode $enableEdit the answer of CustomValue::enableEdit()
     * @return bool
     */
    protected function enableOperationButton($operation, $enableEdit): bool
    {
        if ($enableEdit === true) {
            return true;
        }

        return $enableEdit == ErrorCode::WORKFLOW_LOCK && boolval($operation->getOption('button_workflow_locked'));
    }

    // @phpstan-ignore-next-line
    protected function getComments()
    {
        if ($this->modal) {
            return [];
        }
        return getModelName(SystemTableName::COMMENT)::where('parent_id', $this->custom_value->id)
            ->where('parent_type', $this->custom_table->table_name)
            ->get();
    }

    // @phpstan-ignore-next-line
    protected function setCommentBox($row)
    {
        $useComment = $this->useComment();
        if (!$useComment) {
            return;
        }

        $comments = $this->getComments();
        $form = new WidgetForm();
        $form->disableReset();
        $form->action(admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, 'addcomment'));
        $form->setWidth(10, 2);

        if (count($comments) > 0) {
            $login_user = \Exment::user();
            $isAdministrator = isset($login_user) && $login_user->isAdministrator();
            $html = [];
            foreach ($comments as $index => $comment) {
                $html[] = "<div class='commentline'>" . view('exment::form.field.commentline', [
                    'comment' => $comment,
                    'table_name' => $this->custom_table->table_name,
                    'isAbleRemove' => ($comment->created_user_id == \Exment::getUserId() || $isAdministrator),
                    'deleteUrl' => admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, 'deletecomment', $comment->suuid),
                ])->render() . "</div>";
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
            // "@" opens the member picker; mention.js reads both attributes off
            // the field, so no page-level script tag is needed
            // comment_markdown.js reads the data-comment-* attributes: the preview, and a
            // pasted or dropped image, which is attached to the data and written as Markdown
            ->attribute([
                'data-mention' => '1',
                'data-mention-url' => admin_url('webapi/mention/users'),
                'placeholder' => exmtrans('common.message.comment_mention'),
                'data-comment-markdown' => '1',
                'data-comment-preview-url' => admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, 'previewcomment'),
                'data-comment-image-url' => admin_urls('data', $this->custom_table->table_name, $this->custom_value->id, 'commentimage'),
                'data-comment-label-write' => exmtrans('common.comment_markdown.write'),
                'data-comment-label-preview' => exmtrans('common.comment_markdown.preview'),
                'data-comment-label-uploading' => exmtrans('common.comment_markdown.uploading'),
                'data-comment-label-error' => exmtrans('common.comment_markdown.upload_error'),
            ])
            ->help(exmtrans('common.comment_markdown.help'))
            ->setWidth(12, 0);
        }
        $row->column(['xs' => 12, 'sm' => 6], (new Box(exmtrans("common.comment"), $form))->style('info'));
    }

    // @phpstan-ignore-next-line
    public function getWorkflowHistory()
    {
        $workflows = $this->custom_value->getWorkflowHistories(true)->toArray();
        if (count($workflows) == 0) {
            return;
        }

        $form = new ModalForm();

        // the history of a deleted (deactivated) workflow still names it
        $workflow = Workflow::getEloquentWithTrashed($this->custom_value->workflow_value->workflow_id);

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
    // @phpstan-ignore-next-line
    public function restoreRevision($revision_suuid)
    {
        $this->custom_value->setRevision($revision_suuid)->restore_revision()->save();
        return redirect($this->custom_value->getUrl());
    }

    /**
     * get target data revisions
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function fileupload($httpfiles)
    {
        // the page offers the upload to those who may edit the record, and only
        // when the table keeps attachments (useFileUpload); the request is checked the same
        if (!$this->custom_value || $this->custom_value->enableEdit(false, false) !== true
            || !boolval($this->custom_table->getOption('attachment_flg') ?? true)) {
            return getAjaxResponse([
                'result'  => false,
                'message' => trans('admin.deny'),
            ]);
        }

        if (is_nullorempty($httpfiles)) {
            return getAjaxResponse([
                'result'  => false,
                'message' => exmtrans('common.message.error_execute'),
            ]);
        }

        // file put(store)
        foreach (toArray($httpfiles) as $httpfile) {
            $filename = $httpfile->getClientOriginalName();
            $custom_value = $this->custom_value;
            $file = ExmentFile::storeAs(FileType::CUSTOM_VALUE_DOCUMENT, $httpfile, $this->custom_table->table_name, $filename)
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
     * Replace the file of an attachment: the file it had becomes an older version.
     * Who may: as for adding an attachment (useFileUpload), and, when only the
     * uploader may delete a file (file_delete_useronly), the uploader of the attachment.
     *
     * @param \Illuminate\Http\UploadedFile|array<mixed>|null $httpfile
     * @param int|string $document_id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function documentReplace($httpfile, $document_id)
    {
        if (!DocumentVersionService::available() || !$this->custom_value || !$this->useFileUpload()) {
            return response()->json(['result' => false, 'message' => trans('admin.deny')], 403);
        }
        $document = DocumentVersionService::findDocument($this->custom_value, $document_id);
        if (!$document) {
            return response()->json(['result' => false, 'message' => exmtrans('common.message.notfound')], 404);
        }
        if (boolval(config('exment.file_delete_useronly', false)) && !isMatchString($document->created_user_id, \Exment::getUserId())) {
            return response()->json(['result' => false, 'message' => trans('admin.deny')], 403);
        }
        if (is_array($httpfile)) {
            $httpfile = count($httpfile) === 1 ? reset($httpfile) : null;
        }
        if (!($httpfile instanceof \Illuminate\Http\UploadedFile) || !$httpfile->isValid()) {
            return response()->json(['result' => false, 'message' => exmtrans('common.message.error_execute')], 400);
        }

        // replacing needs the right to edit; dropping the older versions beyond the limit, the right to delete
        $canDelete = $this->custom_value->enableDelete(!config('exment.allow_delete_attachment', false)) === true;
        DocumentVersionService::replace($this->custom_value, $document, $httpfile, $canDelete);

        $this->custom_value->notify(false);
        foreach ($this->custom_table->notifies as $notify) {
            $notify->notifyCreateUpdateUser($this->custom_value, NotifySavedType::ATTACHMENT, ['attachment' => $httpfile->getClientOriginalName()]);
        }

        // the earlier file is opened from the "Version n" link of the row, n being this number
        $no = DocumentVersionService::currentNumbers([$document->id])[intval($document->id)] ?? 2;
        return getAjaxResponse([
            'result' => true,
            'message' => exmtrans('custom_value.document_replaced', ['no' => $no]),
        ]);
    }

    /**
     * The older versions of an attachment, as html (opened from the list of attachments).
     *
     * @param int|string $document_id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function documentVersions($document_id)
    {
        $document = DocumentVersionService::available() && $this->custom_value
            ? DocumentVersionService::findDocument($this->custom_value, $document_id) : null;
        if (!$document) {
            return response()->json(['result' => false, 'message' => exmtrans('common.message.notfound')], 404);
        }
        $versions = DocumentVersionService::versions(intval($document->id))->map(function ($version) {
            $user = isset($version->created_user_id) ? getModelName(SystemTableName::USER)::find($version->created_user_id) : null;
            return [
                'version_no' => $version->version_no,
                'name' => strval($version->document_name),
                'url' => admin_urls('files', $version->file_uuid),
                'created_at' => strval($version->created_at),
                'created_user' => $user ? strval($user->getLabel()) : '',
            ];
        });

        return getAjaxResponse([
            'result' => true,
            'html' => view('exment::form.field.documentversions', ['versions' => $versions])->render(),
        ]);
    }

    /**
     * The script of the list of attachments: replace a file, open the older versions.
     */
    protected static function documentVersionScript(): string
    {
        $failed = json_encode(exmtrans('common.message.error_execute'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        return <<<EOT
        $(document).off('click.exmdocver').on('click.exmdocver', '[data-exment-document-replace]', function (e) {
            e.preventDefault();
            $(this).siblings('input[type=file].exment-document-replace-file').trigger('click');
        });
        $(document).off('change.exmdocver').on('change.exmdocver', 'input[type=file].exment-document-replace-file', function () {
            if (!this.files || this.files.length !== 1) { return; }
            var data = new FormData();
            data.append('file_data', this.files[0]);
            data.append('_token', LA.token);
            $.ajax({ url: $(this).data('url'), type: 'POST', data: data, processData: false, contentType: false })
                .done(function (res) {
                    if (res && res.result) {
                        // the toast is outside the pjax container: it stays while the page is read again
                        if (res.message) { toastr.success(res.message); }
                        $.pjax.reload('#pjax-container');
                        return;
                    }
                    toastr.error((res && res.message) || {$failed});
                })
                .fail(function (xhr) { toastr.error((xhr.responseJSON && xhr.responseJSON.message) || {$failed}); });
            this.value = '';
        });
        $(document).off('click.exmdocverlist').on('click.exmdocverlist', '[data-exment-document-versions]', function (e) {
            e.preventDefault();
            var box = $(this).closest('p').find('.exment-document-versions');
            // open or closed is kept on the element (its visibility depends on the theme's classes)
            if (box.data('open')) { box.data('open', false).hide(); return; }
            $.get($(this).data('url')).done(function (res) {
                if (res && res.result) { box.html(res.html).css('display', 'block').data('open', true); return; }
                toastr.error((res && res.message) || {$failed});
            }).fail(function () { toastr.error({$failed}); });
        });
EOT;
    }

    /**
     * The html a comment will be shown as, for the preview of the comment box.
     *
     * @param string|null $comment
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function previewComment($comment)
    {
        return getAjaxResponse([
            'result' => true,
            'html' => CommentMarkdown::toHtml($comment),
        ]);
    }

    /**
     * Attach an image pasted or dropped into the comment box to the data, and answer the
     * Markdown that shows it. The file is an attachment of the data like any other, so it is
     * seen by the users who can see the data.
     *
     * @param \Illuminate\Http\UploadedFile|array<mixed>|null $httpfile
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function uploadCommentImage($httpfile)
    {
        // only where comments can be written (useComment)
        if (!boolval($this->custom_table->getOption('comment_flg') ?? true)) {
            return response()->json([
                'result' => false,
                'message' => trans('admin.deny'),
            ], 403);
        }

        $validator = \Validator::make(['file' => $httpfile], [
            'file' => [
                'required',
                'file',
                // by the content, not by the name. No svg: it can hold a script.
                'mimetypes:image/png,image/jpeg,image/gif,image/webp',
                'max:' . intval(config('exment.comment_image_max_size_kb', 10240)),
            ],
        ]);
        if ($validator->fails() || !($httpfile instanceof \Illuminate\Http\UploadedFile)) {
            return response()->json([
                'result' => false,
                'message' => $validator->errors()->first('file') ?: exmtrans('common.message.error_execute'),
            ], 400);
        }

        // the name decides whether the file is shown as a picture, so it follows the content
        $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $extension = $extensions[strval($httpfile->getMimeType())];
        $filename = pathinfo(strval($httpfile->getClientOriginalName()), PATHINFO_FILENAME);
        $filename = (is_nullorempty($filename) ? 'image' : $filename) . '.' . $extension;

        $file = ExmentFile::storeAs(FileType::CUSTOM_VALUE_DOCUMENT, $httpfile, $this->custom_table->table_name, $filename)
            ->saveCustomValue($this->custom_value->id, null, $this->custom_table);
        $file->saveDocumentModel($this->custom_value, $filename);

        $url = admin_urls('files', $file->uuid);
        // "[" and "]" would end the alt text
        $alt = str_replace(['[', ']', "\r", "\n"], ['(', ')', ' ', ' '], pathinfo($filename, PATHINFO_FILENAME));

        return getAjaxResponse([
            'result' => true,
            'url' => $url,
            'markdown' => "![{$alt}]({$url})",
        ]);
    }

    /**
     * file delete custom column.
     */
   public function filedelete(Request $request, $form)
    {
        // get file delete flg column name
        $del_column_name = $request->input(Field::FILE_DELETE_FLAG);
        // get key name for delete
        $del_key = $request->input('key');

        // get custom column and item
        $custom_column = CustomColumn::getEloquent($del_column_name, $this->custom_table);
        /** @var ColumnItems\CustomItem|null $custom_item */
        $custom_item = $custom_column ? $custom_column->column_item : null;
        if ($custom_item && method_exists($custom_item, 'deleteFile')) {
            $custom_item->setCustomValue($this->custom_value)->deleteFile($del_key);
        }

        // Reget custom value and updated_at
        $updated_at = null;
        $parent_value = $this->custom_value->getParentValue();
        $referer = $request->header('referer');
        
        if ($parent_value && $referer && Str::contains($referer, '/' . $parent_value->custom_table->table_name . '/')) {
            $updated_value = $parent_value->custom_table->getValueQuery()
                ->select(['updated_at'])
                ->find($parent_value->id);
            $updated_at = $updated_value->updated_at ?? null;
        } else {
            $updated_value = getModelName($this->custom_table)::find($this->custom_value->id);
            $updated_at = $updated_value->updated_at ?? null;
        }

        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.delete_succeeded'),
            'reload' => false,
            'updateValue' => [
                'updated_at' => $updated_at ? $updated_at->format('Y-m-d H:i:s') : null,
            ],
        ]);
    }
    /**
     * add comment.
     */
    // @phpstan-ignore-next-line
    public function addComment($comment)
    {
        $url = admin_urls('data', $this->custom_table->table_name, $this->custom_value->id);

        // a table set not to use comments has no comment box: refuse a request made by hand
        if (!$this->custom_value->isCommentEnabled()) {
            admin_toastr(ErrorCode::FORM_ACTION_DISABLED()->getMessage(), 'error');
            return redirect($url);
        }

        if (!empty($comment)) {
            // save Comment Model and notify. The API (ApiDataController) shares this.
            $this->custom_value->addComment($comment);
        }

        admin_toastr(trans('admin.save_succeeded'));
        return redirect($url);
    }


    /**
     * delete comment.
     */
    // @phpstan-ignore-next-line
    public function deleteComment($id, $suuid)
    {
        // The API (ApiDataController) shares this. The trash icon is only shown to the user
        // who wrote the comment, and the model refuses the others also when asked by hand.
        $result = $this->custom_value->deleteComment($suuid);
        if ($result instanceof ErrorCode && $result == ErrorCode::PERMISSION_DENY) {
            return getAjaxResponse([
                'result' => false,
                'toastr' => trans('admin.deny'),
            ]);
        }

        return getAjaxResponse([
            'result' => true,
            'toastr' => trans('admin.delete_succeeded'),
        ]);
    }

    /**
     * Set ColumnItem's option to column item
     *
     * @param ItemInterface $column_item
     * @param CustomFormColumn|null $form_column
     * @return void
     */
    protected function setColumnItemOption(ItemInterface $column_item, ?CustomFormColumn $form_column = null)
    {
        if ($form_column) {
            $column_item->setFormColumnOptions($form_column->options);
        }
        $column_item->setCustomForm($this->custom_form);
    }

    /**
     * Whether this form is publicform
     *
     * @return bool
     */
    protected function isPublicForm(): bool
    {
        return $this instanceof PublicFormShow;
    }

    /**
     * Whether this show is grid.
     *
     * @return bool
     */
    protected function gridShows()
    {
        if ($this->modal) {
            return false;
        }

        $show_grid_type = $this->custom_form->getOption('show_grid_type') ?? ShowGridType::GRID;
        return isMatchString($show_grid_type, ShowGridType::GRID);
    }
}
