<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Row;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Grid;
use Exceedone\Exment\Enums\ColumnType;
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
use Exceedone\Exment\Enums\PluginEventType;
use Exceedone\Exment\Enums\PluginPageType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Services\PartialCrudService;
use Exceedone\Exment\Services\FormHelper;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Services\TableService;
use Carbon\Carbon;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Elibyy\TCPDF\Facades\TCPDF;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Enums\DataQrRedirect;
use Exceedone\Exment\Model\CustomColumn;

class CustomValueController extends AdminControllerTableBase
{
    use HasResourceTableActions{
        HasResourceTableActions::update as updateTrait;
        HasResourceTableActions::store as storeTrait;
        HasResourceTableActions::destroy as destroyTrait;
    }

    public const CLASSNAME_CUSTOM_VALUE_SHOW = 'block_custom_value_show';
    public const CLASSNAME_CUSTOM_VALUE_GRID = 'block_custom_value_grid';
    public const CLASSNAME_CUSTOM_VALUE_FORM = 'block_custom_value_form';
    public const CLASSNAME_CUSTOM_VALUE_PREFIX = 'custom_value_';
    public const DATANAME_CUSTOM_VIEW_ID = 'data-custom_view_id';
    public const DATANAME_CUSTOM_VIEW_SUUID = 'data-custom_view_suuid';
    public const DATANAME_CUSTOM_VIEW_NAME = 'data-view_view_name';

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
     * @param $tableKey
     * @param int $id
     * @return bool|Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|\Illuminate\Routing\Redirector
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
     * @param $tableKey
     * @param int $id
     * @return bool|Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|\Illuminate\Routing\Redirector
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
     * @param Request $request
     * @param Content $content
     * @return bool|Content|Response|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
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

        if (!$modalframe || $modal) {
            Plugin::pluginExecuteEvent(PluginEventType::LOADING, $this->custom_table, [
                'is_modal' => $modal,
                'page_type' => PluginPageType::LIST
            ]);
        }

        $this->AdminContent($content);

        // if table setting is "one_record_flg" (can save only one record)
        if ($this->custom_table->isOneRecord()) {
            // get record list
            $record = $this->custom_table->getValueModel()->first();
            $id = isset($record) ? $record->id : null;

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
            $row->attribute([
                static::DATANAME_CUSTOM_VIEW_ID => $this->custom_view->id,
                static::DATANAME_CUSTOM_VIEW_SUUID => $this->custom_view->suuid,
                static::DATANAME_CUSTOM_VIEW_NAME => $this->custom_view->view_view_name,
            ]);
        } else {
            $callback = null;
            if ($request->has('query')) {
                if (!boolval(config('exment.search_keep_default_view', false)) ||
                    !($this->custom_view->view_kind_type == ViewKindType::DEFAULT || $this->custom_view->view_kind_type == ViewKindType::ALLDATA)) {
                    $this->custom_view = CustomView::getAllData($this->custom_table);
                }
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
            if ($grid instanceof Grid) {
                $grid->tools(function ($tools) {
                    TableService::appendCreateAndDownloadButtonQRCode($tools, $this->custom_table);
                });
            }
            if ($modal) {
                $content = $grid_item->renderModal($grid);
                Plugin::pluginExecuteEvent(PluginEventType::LOADED, $this->custom_table, [
                    'is_modal' => true,
                    'page_type' => PluginPageType::LIST
                ]);
                return $content;
            } elseif ($modalframe) {
                return $grid_item->renderModalFrame();
            }

            $row = new Row($grid);
            $row->class([static::CLASSNAME_CUSTOM_VALUE_GRID, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
            $row->attribute([
                static::DATANAME_CUSTOM_VIEW_ID => $this->custom_view->id,
                static::DATANAME_CUSTOM_VIEW_SUUID => $this->custom_view->suuid,
                static::DATANAME_CUSTOM_VIEW_NAME => $this->custom_view->view_view_name,
            ]);
        }

        $content->row($row);

        if (!$modal) {
            PartialCrudService::setGridContent($this->custom_table, $content);
        }

        if (!$modalframe || $modal) {
            Plugin::pluginExecuteEvent(PluginEventType::LOADED, $this->custom_table, [
                'is_modal' => $modal,
                'page_type' => PluginPageType::LIST
            ]);
        }

        return $content;
    }

    /**
     * Create interface.
     *
     * @param Request $request
     * @param Content $content
     * @return bool|Content|Response|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function create(Request $request, Content $content)
    {
        if (($response = $this->firstFlow($request, CustomValuePageType::CREATE)) instanceof Response) {
            return $response;
        }

        $this->AdminContent($content);

        Plugin::pluginExecuteEvent(PluginEventType::LOADING, $this->custom_table, [
            'page_type' => PluginPageType::CREATE
        ]);

        if (!is_null($copy_id = $request->get('copy_id'))) {
            // ignore file and autonumber from target model
            $form = $this->form(null)->editing(function($form) {
                $model = $form->model();
                $this->filterCopyColumn($model);
                foreach ($model->getRelations() as $relations) {
                    foreach ($relations as $relation) {
                        $this->filterCopyColumn($relation);
                    }
                }
            })->replicate($copy_id);
        } else {
            $form = $this->form(null);
        }


        $row = new Row($form);
        $row->class([static::CLASSNAME_CUSTOM_VALUE_FORM, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
        $row->attribute([
            static::DATANAME_CUSTOM_VIEW_ID => $this->custom_view->id,
            static::DATANAME_CUSTOM_VIEW_SUUID => $this->custom_view->suuid,
            static::DATANAME_CUSTOM_VIEW_NAME => $this->custom_view->view_view_name,
        ]);
        $content->row($row);

        Plugin::pluginExecuteEvent(PluginEventType::LOADED, $this->custom_table, [
            'page_type' => PluginPageType::CREATE
        ]);
        return $content;
    }

    /**
     * edit
     *
     * @param Request $request
     * @param Content $content
     * @param $tableKey
     * @param $id
     * @return bool|Content|Response|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
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

        $custom_value = $this->custom_table->getValueModel($id);

        $this->AdminContent($content);

        Plugin::pluginExecuteEvent(PluginEventType::LOADING, $this->custom_table, [
            'page_type' => PluginPageType::EDIT,
            'custom_value' => $custom_value
        ]);

        $row = new Row($this->form($id)->edit($id));
        $row->class([static::CLASSNAME_CUSTOM_VALUE_FORM, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
        $row->attribute([
            static::DATANAME_CUSTOM_VIEW_ID => $this->custom_view->id,
            static::DATANAME_CUSTOM_VIEW_SUUID => $this->custom_view->suuid,
            static::DATANAME_CUSTOM_VIEW_NAME => $this->custom_view->view_view_name,
        ]);
        $content->row($row);

        Plugin::pluginExecuteEvent(PluginEventType::LOADED, $this->custom_table, [
            'page_type' => PluginPageType::EDIT,
            'custom_value' => $custom_value
        ]);
        return $content;
    }

    /**
     * Show interface.
     *
     * @param Request $request
     * @param Content $content
     * @param $tableKey
     * @param $id
     * @return bool|Content|Response|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function show(Request $request, Content $content, $tableKey, $id)
    {
        $modal = boolval($request->get('modal'));

        if (($response = $this->firstFlow($request, CustomValuePageType::SHOW, $id)) instanceof Response) {
            return $response;
        }

        $custom_value = $this->custom_table->getValueModel($id);

        Plugin::pluginExecuteEvent(PluginEventType::LOADING, $this->custom_table, [
            'is_modal' => $modal,
            'page_type' => PluginPageType::SHOW,
            'custom_value' => $custom_value
        ]);

        $show_item = $this->custom_form->show_item->id($id)->modal($modal);
        if ($modal) {
            $content = $show_item->createShowForm();
        } else {
            $this->AdminContent($content);
            $content->row($show_item->createShowForm());
            $content->row(function ($row) use ($show_item) {
                $row->class(['row-eq-height', static::CLASSNAME_CUSTOM_VALUE_SHOW, static::CLASSNAME_CUSTOM_VALUE_PREFIX . $this->custom_table->table_name]);
                $show_item->setOptionBoxes($row);
            });
        }

        Plugin::pluginExecuteEvent(PluginEventType::LOADED, $this->custom_table, [
            'is_modal' => $modal,
            'page_type' => PluginPageType::SHOW,
            'custom_value' => $custom_value
        ]);

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

    /**
     * Function handle operation button click event
     *
     * @param Request $request
     * @param $tableKey
     * @param $id
     * @return array|Response
     */
    public function operationClick(Request $request, $tableKey, $id = null)
    {
        $ids = !is_nullorempty($id) ? $id : $request->input('id');
        if ($request->input('suuid') === null) {
            abort(404);
        }

        // get custom operation
        $operation = CustomOperation::where('suuid', $request->input('suuid'))->first();
        if (!isset($operation)) {
            abort(404);
        }

        \Exment::setTimeLimitLong();

        $response = $operation->execute($this->custom_table, $ids, $request->all());

        if ($response === true) {
            return getAjaxResponse([
                'result' => true,
                'toastr' => exmtrans('common.message.success_execute'),
            ]);
        } else {
            if ($request->has('id')) {
                return [
                    'result' => false,
                    'message' => $response,
                ];
            } else {
                return getAjaxResponse([
                    'result'  => false,
                    'swal' => exmtrans('common.error'),
                    'swaltext' => $response,
                ]);
            }
        }
    }


    /**
     * Function handle workflow history click event
     *
     * @param Request $request
     * @param $tableKey
     * @param $id
     * @return bool|Response|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|Response
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

    /**
     * Function handle workflow click event
     *
     * @param Request $request
     * @param $tableKey
     * @param $id
     * @return array
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
            'get_by_userinfo_action' => $request->get('get_by_userinfo_action'),
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

    /**
     * get operation modal
     */
    public function operationModal(Request $request, $tableKey, $id = null)
    {
        if ($request->input('suuid') === null) {
            abort(404);
        }
        // get copy eloquent
        $suuid = $request->input('suuid');
        $operation = CustomOperation::findBySuuid($suuid);
        if (!isset($operation)) {
            abort(404);
        }

        $table_view_name = esc_html($this->custom_table->table_view_name);
        $path = admin_urls('data', $this->custom_table->table_name, $id, 'operationClick');

        if (is_null($id)) {
            if ($request->input('id') === null) {
                abort(404);
            }
            $id = $request->input('id');
        }

        // create form fields
        $form = new ModalForm();
        $form->action($path);
        $form->method('POST');

        $operation_input_columns = $operation->custom_operation_input_columns ?? [];

        // add form
        $form->descriptionHtml(sprintf(exmtrans('custom_operation.dialog_description'), $table_view_name));
        foreach ($operation_input_columns as $operation_input_column) {
            $field = FormHelper::getFormFieldObj($this->custom_table, $operation_input_column->custom_column, [
                'columnOptions' => [
                    'as_modal' => true,
                    'is_operation' => true,
                ]
            ]);
            $form->pushField($field);
        }
        $form->hidden('suuid')->default($suuid);
        $form->hidden('id')->default($id);

        $form->setWidth(10, 2);

        // get label
        if (!is_null(array_get($operation, 'options.label'))) {
            $label = array_get($operation, 'options.label');
        } else {
            $label = exmtrans('common.updated');
        }

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => $label
        ]);
    }

    /**
     * Function handle copy click event
     *
     * @param Request $request
     * @param $tableKey
     * @param $id
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
     * validate before delete.
     */
    protected function validateDestroy($id)
    {
        return $this->custom_table->validateValueDestroy($id);
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

    /**
     * delete file, image, autonumber column from customvalue
     *
     * @param CustomValue $custom_value
     * @return void
     */
    protected function filterCopyColumn(CustomValue $custom_value)
    {
        $custom_value->custom_table->custom_columns->filter(function($column) {
            $column_type = $column->column_type;
            return ColumnType::isAttachment($column_type) || $column_type == ColumnType::AUTO_NUMBER;
        })->each(function($column) use($custom_value) {
            $custom_value->setValue($column->column_name, null, true);
        });
    }

    public function formCreateQrcode(Request $request, $table_id)
    {
        $form = new ModalForm();
        $form->action(route('exment.create_qrcode', ['tableKey' => $table_id]));

        // add form
        $form->number('qr_number', exmtrans("custom_table.qr_code.number_qr"))->default(1)->min(1);
        $form->setWidth(10, 2);
        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans("custom_table.qr_code.form_title"),
            'submitlabel' => exmtrans("common.save")
        ]);
    }

    public function createQrCode(Request $request, $table_id)
    {
        $qr_number = $request->get('qr_number');
        if ($qr_number < 1) {
            return response()->json([
                'result'  => false,
                'message' => exmtrans("custom_table.qr_code.validate_qr_number"),
            ]);
        }

        $selected_custom_value_id = [];
        $table = CustomTable::getEloquent($table_id);
        DB::beginTransaction();
        try {
            for ($i = 0; $i < $qr_number; $i++) {
                $target_data = $table->getValueModel();
                $target_data->save();
                $selected_custom_value_id[] = $target_data->id;
            }
            [$tmpPath, $fileName] = $this->createPdf($selected_custom_value_id, $table_id);
            DB::commit();
        } catch (\Exception $exception) {
            //TODO:error handling
            DB::rollback();
            return response()->json([
                'result'  => false,
                'message' => exmtrans("common.message.error_execute"),
            ]);
        }
        $this->qrCreateOrDownloadResponse($tmpPath, $fileName, true);
    }

    public function qrcodeDownload(Request $request, $table_id)
    {
        $selected_custom_value_id = $request->get('select_ids');
        if (is_null($selected_custom_value_id)) {
            return getAjaxResponse([
                'result'  => false,
                'message' => exmtrans("custom_table.no_selected"),
            ]);
        }
        [$tmpPath, $fileName] = $this->createPdf($selected_custom_value_id, $table_id);
        $this->qrCreateOrDownloadResponse($tmpPath, $fileName);
    }

    protected function qrCreateOrDownloadResponse($tmpPath, $fileName, $isCreate = false)
    {
        if (isset($tmpPath)) {
            $response = getAjaxResponse([
                'fileBase64' => base64_encode(\File::get($tmpPath)),
                'fileContentType' => \File::mimeType($tmpPath),
                'fileName' => $fileName,
                'toastr' => $isCreate ? exmtrans("custom_table.qr_code.created") : exmtrans("custom_table.qr_code.download_complete"),
            ]);

            $response->send();

            $this->deleteTmpFile($tmpPath);
            exit;
        } else {
            return [
                'result' => false,
                'swaltext' => exmtrans("common.no_file_download"),
            ];
        }
    }

    /**
     * Create and download pdf file.
     *
     * @return array
     */
    protected function createPdf($selected_custom_value_id, $table_id)
    {
        $selected_custom_values = CustomTable::getEloquent($table_id)->getValueModel()->whereIn('id', $selected_custom_value_id)->get();

        $_img_width = $this->custom_table->getOption('cell_width') != null ? (float)$this->custom_table->getOption('cell_width') : 62;
        $_img_height = $this->custom_table->getOption('cell_height') != null ? (float)$this->custom_table->getOption('cell_height') : 31;
        $margin_left = $this->custom_table->getOption('margin_left') != null ? (float)$this->custom_table->getOption('margin_left') : 9;
        $margin_top =  $this->custom_table->getOption('margin_top') != null ? (float)$this->custom_table->getOption('margin_top') : 9;
        $col_spacing = $this->custom_table->getOption('col_spacing') != null ? (float)$this->custom_table->getOption('col_spacing') : 3;
        $col_per_page = $this->custom_table->getOption('col_per_page') != null ? (float)$this->custom_table->getOption('col_per_page') : 3;
        $row_spacing = $this->custom_table->getOption('row_spacing') != null ? (float)$this->custom_table->getOption('row_spacing') : 0;
        $row_per_page = $this->custom_table->getOption('row_per_page') != null ? (float)$this->custom_table->getOption('row_per_page') : 9;

        $img_width = $this->mmToPixel($_img_width);
        $img_height = $this->mmToPixel($_img_height);

        DB::beginTransaction();
        try {
            $img_arr = [];
            $refer_column = $this->custom_table->getOption('refer_column');
            $target_column = $refer_column ? CustomColumn::getEloquent($refer_column) : null;
            $refer_column_name = $target_column ? $target_column->column_name : null;
            $selected_custom_values->each(function ($selected_custom_value)
            use (&$img_arr, $img_width, $img_height, $refer_column_name, $table_id, $refer_column) {
                $selected_id = strval($selected_custom_value->id);
                $refer_column_value = $refer_column_name ? $selected_custom_value->getValue($refer_column_name)
                    : ($refer_column === 'id' ? $selected_id : '');
                if (!$refer_column_value && $refer_column_name) {
                    $target_data = CustomTable::getEloquent($table_id)->getValueModel()->where('id', $selected_id)->first();
                    $target_data->updated_at = now();
                    $target_data->save();
                }
                [$qr_file_name, $qr_file_path] = $this->createStickerImg(
                    $selected_id,
                    $img_width,
                    $img_height,
                    $selected_custom_value,
                    $refer_column_value
                );

                array_push($img_arr, $qr_file_path);

            });
            // 一時ファイルの名前を生成する
            $fileName = '2D-barcode_' . Carbon::now()->format('YmdHis') . '.pdf';
            $tmpPath = getFullpath($fileName, Define::DISKNAME_ADMIN_TMP);
            /** @phpstan-ignore-next-line Instantiated class Elibyy\TCPDF\Facades\TCPDF not found. */            
            $pdf = new TCPDF;
            /** @phpstan-ignore-next-line Call to static method setAutoPageBreak() on an unknown class Elibyy\TCPDF\Facades\TCPDF. */
            $pdf::setAutoPageBreak(true, 0);
            /** @phpstan-ignore-next-line Call to static method AddPage() on an unknown class Elibyy\TCPDF\Facades\TCPDF. */
            $pdf::AddPage('P', 'mm', array(210, 297), true, 'UTF-8', false);

            $count = 0;
            $checkWidth = 0;
            foreach ($img_arr as $img) {
                if (($checkWidth + 1) * $_img_width <= (210 - $margin_left * 2 - ($col_per_page - 1) * $col_spacing)) {
                    $pos_x = ($margin_left + ($_img_width + $col_spacing) * $checkWidth);
                    $pos_y = ($margin_top + ($_img_height  + $row_spacing) * $count);
                    /** @phpstan-ignore-next-line Call to static method Image() on an unknown class Elibyy\TCPDF\Facades\TCPDF. */
                    $pdf::Image($img, $pos_x, $pos_y, $_img_width, $_img_height);
                    $checkWidth++;
                } else {
                    $checkWidth = 1;
                    $count++;
                    if (($count + 1) * $_img_height > 297 - $margin_top * 2 - ($row_per_page - 1) * $row_spacing) {
                        $count = 0;
                        /** @phpstan-ignore-next-line Call to static method AddPage() on an unknown class Elibyy\TCPDF\Facades\TCPDF. */
                        $pdf::AddPage('P', 'mm', array(210, 297), true, 'UTF-8', false);
                    }
                    $pos_x = $margin_left;
                    $pos_y = ($margin_top + ($_img_height  + $row_spacing) * $count);
                    /** @phpstan-ignore-next-line Call to static method Image() on an unknown class Elibyy\TCPDF\Facades\TCPDF. */
                    $pdf::Image($img, $pos_x, $pos_y, $_img_width, $_img_height);
                }
            }
            /** @phpstan-ignore-next-line Call to static method Output() on an unknown class Elibyy\TCPDF\Facades\TCPDF. */
            $pdf::Output($tmpPath, 'F');

            foreach ($img_arr as $value) {
                $this->deleteTmpFile($value);
            }
            DB::commit();
        } catch (\Exception $exception) {
            //TODO:error handling
            DB::rollback();
        }

        return [$tmpPath, $fileName];
    }

        /**
     * Create image of sticker/label for adding to exported excel file.
     *
     * @return array
     */
    public function createStickerImg($selected_id, $sticker_img_width, $sticker_img_height, $selected_custom_value, $refer_column_value = null)
    {
        $qr_file_name = 'qrcode_id-' . $selected_id . '_' . Carbon::now()->format('YmdHis') . '.png';
        $qr_file_path = getFullpath($qr_file_name, Define::DISKNAME_ADMIN_TMP);
        $img_margin_top_right = ceil(0.092 * $sticker_img_height);
        $qr_img_height = $qr_img_width = $sticker_img_height - $img_margin_top_right * 2;
        QrCode::format('png')
            ->size(200)
            ->margin(0)
            ->generate(
                $this->createQRUrl($selected_id),
                $qr_file_path
            );
        $qr_img = imagecreatefrompng($qr_file_path);

        $sticker_file_name = 'qrsticker_id-' . $selected_id . '_' . Carbon::now()->format('YmdHis') . '.png';
        $sticker_file_path = getFullpath($sticker_file_name, Define::DISKNAME_ADMIN_TMP);
        $sticker_img = imagecreatetruecolor($sticker_img_width, $sticker_img_height);

        $white  = imagecolorallocate($sticker_img, 255, 255, 255);
        $black = imagecolorallocate($sticker_img, 0, 0, 0);
        $font = base_path('public/font/MS_Gothic.ttf');
        imagefilledrectangle(
            $sticker_img,
            0,
            0,
            $sticker_img_width,
            $sticker_img_height,
            $white
        );
        $text_center_x = $sticker_img_width - ($sticker_img_width - $qr_img_width - $img_margin_top_right) / 2;
        $space_ww = ($sticker_img_width - $qr_img_width - $img_margin_top_right);
        if ($space_ww >= 100) {
            $size_ww = 18;
        } else {
            $size_ww = floor($space_ww / 5.5);
        }
        $width_ww = ceil($size_ww * 4.8);
        $height_ww = ceil($size_ww * 0.6);
        $text_qr = $this->custom_table->getOption('text_qr');
        $x_cordinate = $text_center_x - $width_ww / 2;
        $font_size = ($sticker_img_width > 280) ? (floor($size_ww * 0.6)) : (floor($size_ww * 0.5));
        imagettftext(
            $sticker_img,
            $font_size,
            0,
            $x_cordinate,
            ($sticker_img_height + $height_ww) / 3,
            $black,
            $font,
            $text_qr
        );
        if ($refer_column_value) {
            $y_cordinate = ($sticker_img_height - $img_margin_top_right) / 3 * 2;
            $bbox = imagettfbbox($font_size, 0, $font, $refer_column_value);
            $text_width = floor(strlen($refer_column_value) / ($bbox[2] / ($sticker_img_width / 2)));
            $wrapped_text = wordwrap($refer_column_value, $text_width > 24 ? 24 : 15, "\n", true);     
            $lines = explode("\n", $wrapped_text);
            foreach ($lines as $key => $line) {
                if ($key < 2) {
                    imagettftext(
                        $sticker_img,
                        $font_size,
                        0,
                        $x_cordinate,
                        $y_cordinate,
                        $black,
                        $font,
                        $line
                    );
                    $y_cordinate += 20;
                }
            }
        }
        imagecopyresized(
            $sticker_img,
            $qr_img,
            $img_margin_top_right,
            $img_margin_top_right,
            0,
            0,
            $qr_img_width,
            $qr_img_height,
            200,
            200
        );
        imagepng($sticker_img, $sticker_file_path);

        imagedestroy($sticker_img);
        imagedestroy($qr_img);

        $this->deleteTmpFile($qr_file_path);

        return [$sticker_file_name, $sticker_file_path];
    }

    /**
     * Delete temporary file in admin_tmp folder.
     *
     * @return void
     */
    public function deleteTmpFile($file_path)
    {
        if (\File::exists($file_path)) {
            try {
                \File::delete($file_path);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * Create URL to transit to qr page.
     *
     * @return string
     */
    public function createQRUrl($selected_id)
    {
        $url = admin_urls('qr-code', $this->custom_table->table_name, $selected_id);
        return $url;
    }

    /**
     * Convert Millimeter to Pixel
     *
     * @return float
     */
    public function mmToPixel($mmVal)
    {
        $one_mm_to_pixel = 3.7795275591;
        return $mmVal * $one_mm_to_pixel;
    }
}
