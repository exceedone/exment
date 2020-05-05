<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Row;
use Encore\Admin\Form\Field;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueAuthoritable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\FormActionType;
use Exceedone\Exment\Enums\CustomValuePageType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Services\PartialCrudService;
use Exceedone\Exment\Services\FormHelper;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Form\Widgets\ModalForm;

class CustomValueController extends AdminControllerTableBase
{
    use HasResourceTableActions, CustomValueGrid, CustomValueForm;
    use CustomValueShow, CustomValueSummary, CustomValueCalendar;

    /**
     * this custom table's plugin list.
     * * Filtering only user accessible. *
     *
     * @var array
     */
    protected $plugins = [];

    const CLASSNAME_CUSTOM_VALUE_SHOW = 'block_custom_value_show';
    const CLASSNAME_CUSTOM_VALUE_GRID = 'block_custom_value_grid';
    const CLASSNAME_CUSTOM_VALUE_FORM = 'block_custom_value_form';
    const CLASSNAME_CUSTOM_VALUE_PREFIX = 'custom_value_';

    /**
     * CustomValueController constructor.
     * @param Request $request
     */
    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);

        if (!$this->custom_table) {
            return;
        }

        $this->setPageInfo($this->custom_table->table_view_name, $this->custom_table->table_view_name, $this->custom_table->description, $this->custom_table->getOption('icon'));
    }

    /**
     * Execute an action on the controller.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function callAction($method, $parameters)
    {
        //Get all plugin satisfied
        $this->plugins = Plugin::getPluginsByTable($this->custom_table);

        return parent::callAction($method, $parameters);
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::GRID, null)) instanceof Response) {
            return $response;
        }
        $this->AdminContent($content);

        // if table setting is "one_record_flg" (can save only one record)
        if ($this->custom_table->isOneRecord()) {
            // get record list
            $record = $this->custom_table->getValueModel()->first();
            $id = isset($record)? $record->id: null;

            // if no edit permission show readonly form
            if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
                return $this->show($request, $content, $this->custom_table->table_name, $id);
            }

            // has record, execute
            if (isset($record)) {
                // check if form edit action disabled
                if ($this->custom_table->formActionDisable(FormActionType::EDIT)) {
                    admin_toastr(exmtrans('custom_value.message.action_disabled'), 'error');
                    return $this->show($request, $content, $this->custom_table->table_name, $id);
                }
                $form = $this->form($id)->edit($id);
                $form->setAction(admin_url("data/{$this->custom_table->table_name}/$id"));
                $row = new Row($form);
            }
            // no record
            else {
                // check if form create action disabled
                if ($this->custom_table->formActionDisable(FormActionType::CREATE)) {
                    admin_toastr(exmtrans('custom_value.message.action_disabled'), 'error');
                    return redirect(admin_url('/'));
                }
                $form = $this->form(null);
                $form->setAction(admin_url("data/{$this->custom_table->table_name}"));
                $row = new Row($form);
            }

            $form->disableViewCheck();
            $form->disableEditingCheck();
            $form->disableCreatingCheck();

            $row->class([static::CLASSNAME_CUSTOM_VALUE_FORM, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
        } else {
            $callback = null;
            if ($request->has('query') && $this->custom_view->view_kind_type != ViewKindType::ALLDATA) {
                $this->custom_view = CustomView::getAllData($this->custom_table);
            }
            if ($request->has('group_key')) {
                $group_keys = json_decode($request->query('group_key'));
                $callback = $this->getSummaryDetailFilter($group_keys);
            }
            switch ($this->custom_view->view_kind_type) {
                case ViewKindType::AGGREGATE:
                    $row = new Row($this->gridSummary());
                    break;
                case ViewKindType::CALENDAR:
                    $row = new Row($this->gridCalendar());
                    break;
                default:
                    $row = new Row($this->grid($callback));
                    $this->custom_table->saveGridParameter($request->path());
            }

            $row->class([static::CLASSNAME_CUSTOM_VALUE_GRID, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
        }

        $content->row($row);

        PartialCrudService::setGridContent($this->custom_table, $content);

        return $content;
    }
    
    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::CREATE)) instanceof Response) {
            return $response;
        }

        $this->AdminContent($content);
        
        Plugin::pluginExecuteEvent($this->plugins, PluginEventTrigger::LOADING);

        $row = new Row($this->form(null));
        $row->class([static::CLASSNAME_CUSTOM_VALUE_FORM, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
        $content->row($row);
        
        Plugin::pluginExecuteEvent($this->plugins, PluginEventTrigger::LOADED);
        return $content;
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit(Request $request, Content $content, $tableKey, $id)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::EDIT, $id)) instanceof Response) {
            return $response;
        }

        // if user doesn't have edit permission, redirect to show
        $redirect = $this->redirectShow($id);
        if (isset($redirect)) {
            return $redirect;
        }

        $this->AdminContent($content);
        Plugin::pluginExecuteEvent($this->plugins, PluginEventTrigger::LOADING);

        $row = new Row($this->form($id)->edit($id));
        $row->class([static::CLASSNAME_CUSTOM_VALUE_FORM, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
        $content->row($row);

        Plugin::pluginExecuteEvent($this->plugins, PluginEventTrigger::LOADED);
        return $content;
    }
    
    /**
     * Show interface.
     *
     * @param $id
     * @return Content
     */
    public function show(Request $request, Content $content, $tableKey, $id)
    {
        $modal = boolval($request->get('modal'));

        if (($response = $this->firstFlow($request, CustomValuePageType::SHOW, $id)) instanceof Response) {
            return $response;
        }

        if ($modal) {
            return $this->createShowForm($id, $modal);
        }

        $this->AdminContent($content);
        $content->row($this->createShowForm($id));
        $content->row(function ($row) use ($id) {
            $row->class(['row-eq-height', static::CLASSNAME_CUSTOM_VALUE_SHOW, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
            $this->setOptionBoxes($row, $id, false);
        });
        return $content;
    }

    /**
     * for file upload function.
     */
    public function fileupload(Request $request, $tableKey, $id)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::SHOW, $id)) instanceof Response) {
            return $response;
        }
        $httpfile = $request->file('file_data');
        // file put(store)
        $filename = $httpfile->getClientOriginalName();
        // $uniqueFileName = ExmentFile::getUniqueFileName($this->custom_table->table_name, $filename);
        // $file = ExmentFile::store($httpfile, config('admin.upload.disk'), $this->custom_table->table_name, $uniqueFileName);
        $custom_value = getModelName($this->custom_table)::find($id);
        $file = ExmentFile::storeAs($httpfile, $this->custom_table->table_name, $filename)
            ->saveCustomValue($custom_value->id, null, $this->custom_table);
        // save document model
        $document_model = $file->saveDocumentModel($custom_value, $filename);
        
        // loop for $notifies
        foreach ($custom_value->custom_table->notifies as $notify) {
            $notify->notifyCreateUpdateUser($custom_value, NotifySavedType::ATTACHMENT, ['attachment' => $filename]);
        }
        
        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.update_succeeded'),
        ]);
    }

    /**
     * file delete custom column.
     */
    public function filedelete(Request $request, $tableKey, $id)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::EDIT, $id)) instanceof Response) {
            return $response;
        }

        // get file delete flg column name
        $del_column_name = $request->input(Field::FILE_DELETE_FLAG);
        /// file remove
        $form = $this->form($id);
        $fields = $form->builder()->fields();
        // filter file
        $fields->filter(function ($field) use ($del_column_name) {
            return $field instanceof Field\Embeds;
        })->each(function ($field) use ($del_column_name, $id) {
            // get fields
            $embedFields = $field->fields();
            $embedFields->filter(function ($field) use ($del_column_name) {
                return $field->column() == $del_column_name;
            })->each(function ($field) use ($del_column_name, $id) {
                // get file path
                $obj = getModelName($this->custom_table)::find($id);
                $original = $obj->getValue($del_column_name, true);
                $field->setOriginal($obj->value);

                $field->destroy(); // delete file
                ExmentFile::deleteFileInfo($original); // delete file table
                $obj->setValue($del_column_name, null)
                    ->remove_file_columns($del_column_name)
                    ->save();
            });
        });

        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.delete_succeeded'),
        ]);
    }
 
    /**
     * add comment.
     */
    public function addComment(Request $request, $tableKey, $id)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::SHOW, $id)) instanceof Response) {
            return $response;
        }
        $comment = $request->get('comment');

        if (!empty($comment)) {
            // save Comment Model
            $model = CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel();
            $model->parent_id = $id;
            $model->parent_type = $tableKey;
            $model->setValue([
                'comment_detail' => $comment,
            ]);
            $model->save();
                
            // execute notify
            $custom_value = CustomTable::getEloquent($tableKey)->getValueModel($id);
            if (isset($custom_value)) {
                foreach ($custom_value->custom_table->notifies as $notify) {
                    $notify->notifyCreateUpdateUser($custom_value, NotifySavedType::COMMENT, ['comment' => $comment]);
                }
            }
        }

        $url = admin_urls('data', $this->custom_table->table_name, $id);
        admin_toastr(trans('admin.save_succeeded'));
        return redirect($url);
    }
 
    /**
     * get import modal
     */
    public function importModal(Request $request, $tableKey)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::CREATE)) instanceof Response) {
            return $response;
        }

        $service = $this->getImportExportService();
        $importlist = Plugin::pluginPreparingImport($this->plugins);
        return $service->getImportModal($importlist);
    }
    
    //Function handle plugin click event
    /**
     * @param Request $request
     * @return Response
     */
    public function pluginClick(Request $request, $tableKey, $id = null)
    {
        if ($request->input('uuid') === null) {
            abort(404);
        }
        // get plugin
        $plugin = Plugin::getPluginByUUID($request->input('uuid'));
        if (!isset($plugin)) {
            abort(404);
        }
        
        setTimeLimitLong();

        $class = $plugin->getClass($request->input('plugin_type'), [
            'custom_table' => $this->custom_table,
            'id' => $id
        ]);
        $response = $class->execute();
        
        if ($response === false) {
            return getAjaxResponse(false);
        } elseif ($response instanceof Response) {
            return $response;
        } elseif (is_array($response)) {
            return getAjaxResponse($response);
        }
        return getAjaxResponse([
            'result' => true,
            'toastr' => exmtrans('common.message.success_execute'),
        ]);
    }

    //Function handle workflow history click event
    /**
     * @param Request $request
     * @return Response
     */
    public function workflowHistoryModal(Request $request, $tableKey, $id = null)
    {
        // execute history
        $custom_value = $this->custom_table->getValueModel($id);
        $form = $this->getWorkflowHistory($custom_value, $id);
        
        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('common.workflow_history'),
            'showSubmit' => false,
            'modalSize' => 'modal-xl',
        ]);
    }

    /**
     * get action modal
     */
    public function actionModal(Request $request, $tableKey, $id)
    {
        if (is_null($id) || $request->input('action_id') === null) {
            abort(404);
        }
        // get action
        $action = WorkflowAction::find($request->input('action_id'));
        if (!isset($action)) {
            abort(404);
        }

        return $action->actionModal($this->custom_table->getValueModel($id));
    }

    //Function handle workflow click event
    /**
     * @param Request $request
     * @return Response
     */
    public function actionClick(Request $request, $tableKey, $id)
    {
        if (is_null($id) || $request->input('action_id') === null) {
            abort(404);
        }
        // get action
        $action = WorkflowAction::find($request->input('action_id'));
        if (!isset($action)) {
            abort(404);
        }

        $custom_value = $this->custom_table->getValueModel($id);

        //TODO:validation
        
        $action->executeAction($custom_value, [
            'comment' => $request->get('comment'),
            'next_work_users' => $request->get('next_work_users'),
        ]);

        return ([
            'result'  => true,
            'toastr' => sprintf(exmtrans('common.message.success_execute')),
        ]);
    }

    /**
     * get copy modal
     */
    public function copyModal(Request $request, $tableKey, $id)
    {
        if ($request->input('uuid') === null) {
            abort(404);
        }
        // get copy eloquent
        $uuid = $request->input('uuid');
        $copy = CustomCopy::findBySuuid($uuid);
        if (!isset($copy)) {
            abort(404);
        }

        $from_table_view_name = $this->custom_table->table_view_name;
        $to_table_view_name = $copy->to_custom_table->table_view_name;
        $path = admin_urls('data', $this->custom_table->table_name, $id, 'copyClick');
        
        // create form fields
        $form = new ModalForm();
        $form->action($path);
        $form->method('POST');

        $copy_input_columns = $copy->custom_copy_input_columns ?? [];

        // add form
        $form->description(sprintf(exmtrans('custom_copy.dialog_description'), $from_table_view_name, $to_table_view_name, $to_table_view_name));
        foreach ($copy_input_columns as $copy_input_column) {
            $field = FormHelper::getFormField($this->custom_table, $copy_input_column->to_custom_column, null);
            $form->pushField($field);
        }
        $form->hidden('uuid')->default($uuid);
        
        $form->setWidth(10, 2);

        // get label
        if (!is_null(array_get($copy, 'options.label'))) {
            $label = array_get($copy, 'options.label');
        } else {
            $label = exmtrans('common.copy');
        }

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => $label
        ]);
    }


    //Function handle copy click event
    /**
     * @param Request $request
     * @return Response
     */
    public function copyClick(Request $request, $tableKey, $id = null)
    {
        if ($request->input('uuid') === null) {
            abort(404);
        }
        // get copy eloquent
        $copy = CustomCopy::findBySuuid($request->input('uuid'));
        if (!isset($copy)) {
            abort(404);
        }
        
        // execute copy
        $custom_value = getModelName($this->custom_table)::find($id);
        $response = $copy->execute($custom_value, $request);

        if (isset($response)) {
            return getAjaxResponse($response);
        }
        //TODO:error
        return getAjaxResponse(false);
    }

    /**
     * create notify mail send form
     */
    public function notifyClick(Request $request, $tableKey, $id = null)
    {
        $targetid = $request->get('targetid');
        if (!isset($targetid)) {
            abort(404);
        }

        $notify = Notify::where('suuid', $targetid)->first();
        if (!isset($notify)) {
            abort(404);
        }

        $service = new NotifyService($notify, $targetid, $tableKey, $id);
        $form = $service->getNotifyDialogForm();
        
        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('custom_value.sendmail.title')
        ]);
    }

    /**
     * create share form
     */
    public function shareClick(Request $request, $tableKey, $id)
    {
        // get customvalue
        $custom_value = CustomTable::getEloquent($tableKey)->getValueModel($id);
        $form = CustomValueAuthoritable::getShareDialogForm($custom_value);
        
        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('common.shared')
        ]);
    }

    /**
     * restore trashed value
     */
    public function restoreClick(Request $request, $tableKey, $id)
    {
        return $this->restore($request, $tableKey, $id);
    }

    /**
     * restore trashed value
     */
    public function rowRestore(Request $request, $tableKey)
    {
        return $this->restore($request, $tableKey, $request->get('id'));
    }

    protected function restore(Request $request, $tableKey, $id)
    {
        $ids = stringToArray($id);

        \DB::beginTransaction();
        try {
            foreach ($ids as $id) {
                // get customvalue
                $custom_value = CustomTable::getEloquent($tableKey)->getValueModel($id, true);
                if (!isset($custom_value)) {
                    \DB::rollback();
                    return getAjaxResponse(false);
                }

                if (!$custom_value->trashed()) {
                    continue;
                }
                
                if (($response = $this->firstFlow($request, CustomValuePageType::EDIT)) instanceof Response) {
                    \DB::rollback();
                    return $response;
                }

                $custom_value->restore();
            }
            
            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollback();
        }
    
        return getAjaxResponse([
            'result'  => true,
            'message' => exmtrans('custom_value.message.restore_succeeded'),
        ]);
    }

    /**
     * set notify target users and  get form
     */
    public function sendTargetUsers(Request $request, $tableKey, $id = null)
    {
        $service = $this->getNotifyService($tableKey, $id);
        
        // get target users
        $target_users = request()->get('target_users');

        $form = $service->getNotifyDialogFormMultiple($target_users);
        
        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('custom_value.sendmail.title')
        ]);
    }
    /**
     * send mail
     */
    public function sendMail(Request $request, $tableKey, $id = null)
    {
        $service = $this->getNotifyService($tableKey, $id);
        
        return $service->sendNotifyMail($this->custom_table);
    }

    /**
     * set share users organizations
     */
    public function sendShares(Request $request, $tableKey, $id)
    {
        // get customvalue
        $custom_value = CustomTable::getEloquent($tableKey)->getValueModel($id);
        return CustomValueAuthoritable::saveShareDialogForm($custom_value);
    }

    protected function getNotifyService($tableKey, $id)
    {
        $targetid = request()->get('mail_template_id');
        if (!isset($targetid)) {
            abort(404);
        }

        $notify = Notify::where('suuid', $targetid)->first();
        if (!isset($notify)) {
            abort(404);
        }

        $service = new NotifyService($notify, $targetid, $tableKey, $id);
        return $service;
    }

    /**
     * Check whether user has edit permission
     */
    protected function redirectShow($id)
    {
        if (!$this->custom_table->hasPermissionEditData($id)) {
            return redirect(admin_url("data/{$this->custom_table->table_name}/$id"));
        }
        return null;
    }

    /**
     * get relation name etc for form block
     */
    protected function getRelationName($custom_form_block)
    {
        $target_table = $custom_form_block->target_table;
        // get label hasmany
        $block_label = $custom_form_block->form_block_view_name;
        if (!isset($block_label)) {
            $enum = FormBlockType::getEnum(array_get($custom_form_block, 'form_block_type'));
            $block_label = exmtrans("custom_form.table_".$enum->lowerKey()."_label") . $target_table->table_view_name;
        }
        // get form columns count
        $form_block_options = array_get($custom_form_block, 'options', []);
        $relation_name = CustomRelation::getRelationNameByTables($this->custom_table, $target_table);

        return [$relation_name, $block_label];
    }

    /**
     * First flow. check role and set form and view id etc.
     * different logic for new, update or show
     */
    protected function firstFlow(Request $request, $formActionType, $id = null)
    {
        // if this custom_table doesn't have custom_columns, redirect custom_column's page(admin) or back
        if (count($this->custom_table->custom_columns) == 0) {
            if ($this->custom_table->hasPermission(Permission::CUSTOM_TABLE)) {
                admin_toastr(exmtrans('custom_value.help.no_columns_admin'), 'error');
                return redirect(admin_urls('column', $this->custom_table->table_name));
            }

            admin_toastr(exmtrans('custom_value.help.no_columns_user'), 'error');
            return back();
        }

        $this->setFormViewInfo($request, $id);
 
        // id set, checking as update.
        // check for update
        if ($formActionType == CustomValuePageType::CREATE) {
            $code = $this->custom_table->enableCreate(true);
        } elseif ($formActionType == CustomValuePageType::EDIT) {
            $custom_value = $this->custom_table->getValueModel($id);
            $code = $custom_value ? $custom_value->enableEdit(true) : $this->custom_table->getNoDataErrorCode($id);
        } elseif ($formActionType == CustomValuePageType::SHOW) {
            $custom_value = $this->custom_table->getValueModel($id, boolval($request->get('trashed')) && $this->custom_table->enableShowTrashed() === true);
            $code = $custom_value ? $custom_value->enableAccess(true) : $this->custom_table->getNoDataErrorCode($id);
        } elseif ($formActionType == CustomValuePageType::GRID) {
            $code = $this->custom_table->enableView();
        } elseif ($formActionType == CustomValuePageType::DELETE) {
            $custom_value = $this->custom_table->getValueModel($id);
            $code = $custom_value ? $custom_value->enableDelete(true) : $this->custom_table->getNoDataErrorCode($id);
        }
        
        if ($code !== true) {
            Checker::error($code->getMessage());
            return false;
        }
        
        return true;
    }

    /**
     * check if data is referenced.
     */
    protected function checkReferenced($custom_table, $list)
    {
        foreach ($custom_table->getSelectedItems() as $item) {
            $model = getModelName(array_get($item, 'custom_table_id'));
            $column_name = array_get($item, 'column_name');
            if ($model::whereIn('value->'.$column_name, $list)->exists()) {
                return true;
            }
        }
        return false;
    }
    /**
     * validate before delete.
     */
    protected function validateDestroy($id)
    {
        $custom_table = $this->custom_table;

        // check if data referenced
        if ($this->checkReferenced($custom_table, [$id])) {
            return [
                'status'  => false,
                'message' => exmtrans('custom_value.help.reference_error'),
            ];
        }

        $relations = CustomRelation::getRelationsByParent($custom_table, RelationType::ONE_TO_MANY);
        // check if child data referenced
        foreach ($relations as $relation) {
            $child_table = $relation->child_custom_table;
            $list = getModelName($child_table)
                ::where('parent_id', $id)
                ->where('parent_type', $custom_table->table_name)
                ->pluck('id')->all();
            if ($this->checkReferenced($child_table, $list)) {
                return [
                    'status'  => false,
                    'message' => exmtrans('custom_value.help.reference_error'),
                ];
            }
        }
    }

    /**
     * set view and form info.
     * use session etc
     */
    protected function setFormViewInfo(Request $request, $id = null)
    {
        // set view
        $this->custom_view = CustomView::getDefault($this->custom_table);

        // set form
        $this->custom_form = $this->custom_table->getPriorityForm($id);
    }
}
