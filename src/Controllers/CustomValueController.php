<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Row;
use Encore\Admin\Widgets\Box;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomValueAuthoritable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\FormActionType;
use Exceedone\Exment\Enums\CustomValuePageType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Services\PartialCrudService;
use Exceedone\Exment\Services\FormHelper;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Form\Widgets\ModalForm;

class CustomValueController extends AdminControllerTableBase
{
    use HasResourceTableActions{
        HasResourceTableActions::update as updateTrait;
        HasResourceTableActions::store as storeTrait;
        HasResourceTableActions::destroy as destroyTrait;
    }

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
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($tableKey, $id)
    {
        $request = request();
        if (($response = $this->firstFlow($request, CustomValuePageType::EDIT, $id)) instanceof Response) {
            return $response;
        }
        return $this->updateTrait($tableKey, $id);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return mixed
     */
    public function store()
    {
        $request = request();
        if (($response = $this->firstFlow($request, CustomValuePageType::CREATE)) instanceof Response) {
            return $response;
        }
        return $this->storeTrait();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy($tableKey, $id)
    {
        $request = request();
        // if destory, id is comma string
        foreach (stringtoArray($id) as $i) {
            if (($response = $this->firstFlow($request, CustomValuePageType::DELETE, $i)) instanceof Response) {
                return $response;
            }
        }
        
        return $this->destroyTrait($tableKey, $id);
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $modal = $request->has('modal');
        $modalframe = $request->has('modalframe');

        if ($modalframe) {
            if (($response = $this->firstFlow($request, CustomValuePageType::GRIDMODAL, null)) instanceof Response) {
                return $response;
            }
        } else {
            if (($response = $this->firstFlow($request, CustomValuePageType::GRID, null)) instanceof Response) {
                return $response;
            }
        }

        // checking export
        if ($request->get('action') == 'export') {
            if (($response = $this->firstFlow($request, CustomValuePageType::EXPORT)) instanceof Response) {
                return $response;
            }
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
            // if modal, set alldata view
            if ($modalframe) {
                $this->custom_view = CustomView::getAllData($this->custom_table);
            }

            $grid_item = $this->custom_view->grid_item
                ->modal($modal);
            $grid_item->callback($grid_item->getCallbackFilter());
            
            if ($request->has('filter_ajax')) {
                return $grid_item->getFilterHtml();
            }

            // Append ----------------------------------------------------
            if (boolval($this->custom_view->use_view_infobox)) {
                $box = new Box($this->custom_view->view_infobox_title, html_clean($this->custom_view->view_infobox));
                $content->row($box);
            }

            $grid = $grid_item->grid($callback);

            if ($modal) {
                return $grid_item->renderModal($grid);
            } elseif ($modalframe) {
                return $grid_item->renderModalFrame();
            }

            $row = new Row($grid);
            $row->class([static::CLASSNAME_CUSTOM_VALUE_GRID, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
        }

        $content->row($row);

        if (!$modal) {
            PartialCrudService::setGridContent($this->custom_table, $content);
        }

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
        
        Plugin::pluginExecuteEvent(PluginEventTrigger::LOADING, $this->custom_table);

        $row = new Row($this->form(null));
        $row->class([static::CLASSNAME_CUSTOM_VALUE_FORM, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
        $content->row($row);
        
        Plugin::pluginExecuteEvent(PluginEventTrigger::LOADED, $this->custom_table);
        return $content;
    }


    /**
     * edit
     *
     * @param Request $request
     * @param Content $content
     * @param string $tableKey
     * @param string|int|null $id
     * @return Response
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
        Plugin::pluginExecuteEvent(PluginEventTrigger::LOADING, $this->custom_table);

        $row = new Row($this->form($id)->edit($id));
        $row->class([static::CLASSNAME_CUSTOM_VALUE_FORM, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
        $content->row($row);

        Plugin::pluginExecuteEvent(PluginEventTrigger::LOADED, $this->custom_table);
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

        $show_item = $this->custom_form->show_item->id($id)->modal($modal);
        if ($modal) {
            return $show_item->createShowForm();
        }

        $this->AdminContent($content);
        $content->row($show_item->createShowForm());
        $content->row(function ($row) use ($show_item) {
            $row->class(['row-eq-height', static::CLASSNAME_CUSTOM_VALUE_SHOW, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
            $show_item->setOptionBoxes($row);
        });
        return $content;
    }


    /**
     * compare
     */
    public function compare(Request $request, Content $content, $tableKey, $id)
    {
        $this->firstFlow($request, CustomValuePageType::SHOW, $id);
        $this->AdminContent($content);

        $show_item = $this->custom_form->show_item->id($id);

        $content->body($show_item->getRevisionCompare($request->get('revision')));
        return $content;
    }
   
    /**
     * get compare item for pjax
     */
    public function compareitem(Request $request, Content $content, $tableKey, $id)
    {
        $this->firstFlow($request, CustomValuePageType::SHOW, $id);
        
        $show_item = $this->custom_form->show_item->id($id);

        return $show_item->getRevisionCompare($request->get('revision'), true);
    }
   
    /**
     * restore data
     */
    public function restoreRevision(Request $request, $tableKey, $id)
    {
        $this->firstFlow($request, CustomValuePageType::EDIT, $id);
        
        $show_item = $this->custom_form->show_item->id($id);

        $revision_suuid = $request->get('revision');

        return $show_item->restoreRevision($revision_suuid);
    }
  
    /**
     * for file upload function.
     */
    public function fileupload(Request $request, $tableKey, $id)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::SHOW, $id)) instanceof Response) {
            return $response;
        }
        $show_item = $this->custom_form->show_item->id($id);
        return $show_item->fileupload($request->file('file_data'));
    }

    /**
     * file delete custom column.
     */
    public function filedelete(Request $request, $tableKey, $id)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::EDIT, $id)) instanceof Response) {
            return $response;
        }

        $show_item = $this->custom_form->show_item->id($id);
        return $show_item->filedelete($request, $this->form($id));
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

        $show_item = $this->custom_form->show_item->id($id);
        return $show_item->addComment($comment);
    }


    /**
     * remove comment.
     */
    public function deleteComment(Request $request, $tableKey, $id, $suuid)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::SHOW, $id)) instanceof Response) {
            return $response;
        }

        $show_item = $this->custom_form->show_item->id($id);
        return $show_item->deleteComment($id, $suuid);
    }


    /**
     * @param Request $request
     */
    public function import(Request $request)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::IMPORT)) instanceof Response) {
            return $response;
        }
        
        $grid = $this->custom_view->grid_item;
        return $grid->import($request);
    }
 
    /**
     * get import modal
     */
    public function importModal(Request $request, $tableKey)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::IMPORT)) instanceof Response) {
            return $response;
        }

        $grid = $this->custom_view->grid_item;
        $service = $grid->getImportExportService();
        $importlist = Plugin::pluginPreparingImport($this->custom_table);
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
        
        \Exment::setTimeLimitLong();

        $class = $plugin->getClass($request->input('plugin_type'), [
            'custom_table' => $this->custom_table,
            'id' => $id,
            'selected_custom_values' => (!is_nullorempty($request->get('select_ids')) ? $this->custom_table->getValueModel()->find($request->get('select_ids')) : collect()),
        ]);
        $response = $class->execute();
        
        if ($response === false) {
            return getAjaxResponse(false);
        } elseif ($response instanceof \Illuminate\Http\RedirectResponse) {
            return getAjaxResponse([
                'result' => true,
                'toastr' => exmtrans('common.message.success_execute'),
                'redirect' => $response->getTargetUrl(),
            ]);
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
    
    //Function handle operation button click event
    /**
     * @param Request $request
     * @return Response
     */
    public function operationClick(Request $request, $tableKey, $id = null)
    {
        $id = !is_nullorempty($id) ? $id : $request->input('id');
        if ($request->input('suuid') === null) {
            abort(404);
        }

        // get custom operation
        $operation = CustomOperation::where('suuid', $request->input('suuid'))->first();
        if (!isset($operation)) {
            abort(404);
        }
        
        \Exment::setTimeLimitLong();

        $response = $operation->execute($this->custom_table, $id);
        
        if ($response === false) {
            return getAjaxResponse(false);
        } elseif ($response instanceof Response) {
            return $response;
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
        if (($response = $this->firstFlow($request, CustomValuePageType::SHOW, $id)) instanceof Response) {
            return $response;
        }

        // execute history
        $custom_value = $this->custom_table->getValueModel($id);
        $show_item = $this->custom_form->show_item->id($id);
        $form = $show_item->getWorkflowHistory();
        
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

        $from_table_view_name = esc_html($this->custom_table->table_view_name);
        $to_table_view_name = esc_html($copy->to_custom_table->table_view_name);
        $path = admin_urls('data', $this->custom_table->table_name, $id, 'copyClick');
        
        // create form fields
        $form = new ModalForm();
        $form->action($path);
        $form->method('POST');

        $copy_input_columns = $copy->custom_copy_input_columns ?? [];

        // add form
        $form->descriptionHtml(sprintf(exmtrans('custom_copy.dialog_description'), $from_table_view_name, $to_table_view_name, $to_table_view_name));
        foreach ($copy_input_columns as $copy_input_column) {
            $field = FormHelper::getFormFieldObj($this->custom_table, $copy_input_column->to_custom_column, [
                'columnOptions' => [
                    'as_modal' => true,
                ]
            ]);
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
        $response = $copy->executeRequest($custom_value, $request);

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

        $notify = Notify::where('suuid', $targetid)->where('active_flg', 1)->first();
        if (!isset($notify)) {
            abort(404);
        }

        $service = new NotifyService($notify, $targetid, $tableKey, $id);
        $form = $service->getNotifyDialogForm();
        
        if ($form === false) {
            return getAjaxResponse([
                'result'  => false,
                'swal' => exmtrans('common.error'),
                'swaltext' => exmtrans('notify.message.no_action_target'),
            ]);
        }

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


    /**
     * Make a form builder.
     * @param string|int|null $id if edit mode, set model id
     * @return Form
     */
    protected function form($id = null)
    {
        $form_item = $this->custom_form->form_item;
        return $form_item->id($id)->form();
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

    protected function getNotifyService($tableKey, $id)
    {
        $targetid = request()->get('mail_template_id');
        if (!isset($targetid)) {
            abort(404);
        }

        $notify = Notify::where('suuid', $targetid)->where('active_flg', 1)->first();
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

        $this->setFormViewInfo($request, $formActionType, $id);
        
        // id set, checking as update.
        // check for update
        $code = null;
        $trashed = boolval($request->get('trashed')) || isMatchString($request->get('_scope_'), 'trashed');
        if ($formActionType == CustomValuePageType::CREATE) {
            $code = $this->custom_table->enableCreate(true);
        } elseif ($formActionType == CustomValuePageType::EDIT) {
            $custom_value = $this->custom_table->getValueModel($id);
            $code = $custom_value ? $custom_value->enableEdit(true) : $this->custom_table->getNoDataErrorCode($id);
        } elseif ($formActionType == CustomValuePageType::SHOW) {
            $custom_value = $this->custom_table->getValueModel($id, $trashed && $this->custom_table->enableShowTrashed() === true);
            $code = $custom_value ? $custom_value->enableAccess(true) : $this->custom_table->getNoDataErrorCode($id);
        } elseif ($formActionType == CustomValuePageType::GRID) {
            $code = $this->custom_table->enableView();
        } elseif ($formActionType == CustomValuePageType::GRIDMODAL) {
            $code = $this->custom_table->enableAccess();
        } elseif ($formActionType == CustomValuePageType::DELETE) {
            $custom_value = $this->custom_table->getValueModel($id, $trashed);
            $code = $custom_value ? $custom_value->enableDelete(true) : $this->custom_table->getNoDataErrorCode($id);
        } elseif ($formActionType == CustomValuePageType::EXPORT) {
            $code = $this->custom_table->enableExport();
        } elseif ($formActionType == CustomValuePageType::IMPORT) {
            // if import, check has create permission(but not check "create" form action)
            $code = $this->custom_table->enableImport();
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
    protected function setFormViewInfo(Request $request, $formActionType, $id = null)
    {
        // set view
        $this->custom_view = CustomView::getDefault($this->custom_table);

        // set form data type for form priority
        $form_data_type = CustomValuePageType::getFormDataType($formActionType);
        if (isset($form_data_type)) {
            System::setRequestSession(Define::SYSTEM_KEY_SESSION_FORM_DATA_TYPE, $form_data_type);
        }

        // set form
        $this->custom_form = $this->custom_table->getPriorityForm($id);
    }
}
