<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Form\Field;
use Illuminate\Http\Request;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueAuthoritable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Services\PartialCrudService;
use Symfony\Component\HttpFoundation\Response;

class CustomValueController extends AdminControllerTableBase
{
    use HasResourceTableActions, CustomValueGrid, CustomValueForm;
    use CustomValueShow, CustomValueSummary, CustomValueCalendar;
    protected $plugins = [];

    /**
     * CustomValueController constructor.
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);

        $this->setPageInfo($this->custom_table->table_view_name, $this->custom_table->table_view_name, $this->custom_table->description, $this->custom_table->getOption('icon'));

        if (!is_null($this->custom_table)) {
            //Get all plugin satisfied
            $this->plugins = Plugin::getPluginsByTable($this->custom_table->table_name);
        }
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        if (($response = $this->firstFlow($request, null, true)) instanceof Response) {
            return $response;
        }
        $this->AdminContent($content);

        // if table setting is "one_record_flg" (can save only one record)
        $one_record_flg = boolval(array_get($this->custom_table->options, 'one_record_flg'));
        if ($one_record_flg) {
            // get record list
            $record = $this->getModelNameDV()::first();
            $id = isset($record)? $record->id: null;

            // if no edit permission show readonly form
            if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
                return $this->show($request, $content, $this->custom_table->table_name, $id);
            }

            // has record, execute
            if (isset($record)) {
                $form = $this->form($id)->edit($id);
                $form->setAction(admin_url("data/{$this->custom_table->table_name}/$id"));
                $content->body($form);
            }
            // no record
            else {
                $form = $this->form(null);
                $form->setAction(admin_url("data/{$this->custom_table->table_name}"));
                $content->body($form);
            }

            $form->disableViewCheck();
            $form->disableEditingCheck();
            $form->disableCreatingCheck();
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
                    $content->body($this->gridSummary());
                    break;
                case ViewKindType::CALENDAR:
                    $content->body($this->gridCalendar());
                    break;
                default:
                    $content->body($this->grid($callback));
                    $this->custom_table->saveGridParameter($request->path());
            }

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
        if (($response = $this->firstFlow($request)) instanceof Response) {
            return $response;
        }
        $this->AdminContent($content);
        Plugin::pluginPreparing($this->plugins, 'loading');
        $content->body($this->form(null));
        Plugin::pluginPreparing($this->plugins, 'loaded');
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
        if (($response = $this->firstFlow($request, $id)) instanceof Response) {
            return $response;
        }

        // if user doesn't have edit permission, redirect to show
        $redirect = $this->redirectShow($id);
        if (isset($redirect)) {
            return $redirect;
        }
        $this->AdminContent($content);
        Plugin::pluginPreparing($this->plugins, 'loading');
        $content->body($this->form($id)->edit($id));
        Plugin::pluginPreparing($this->plugins, 'loaded');
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
        if ($modal) {
            return $this->createShowForm($id, $modal);
        }

        if (($response = $this->firstFlow($request, $id, true)) instanceof Response) {
            return $response;
        }

        $this->AdminContent($content);
        $content->row($this->createShowForm($id));
        $content->row(function ($row) use ($id) {
            $row->class('row-eq-height');
            $this->setOptionBoxes($row, $id, false);
        });
        return $content;
    }

    /**
     * file delete custom column.
     */
    public function filedelete(Request $request, $tableKey, $id)
    {
        if (($response = $this->firstFlow($request, $id)) instanceof Response) {
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
        if (($response = $this->firstFlow($request, $id, true)) instanceof Response) {
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
     * for file upload function.
     */
    public function fileupload(Request $request, $tableKey, $id)
    {
        if (($response = $this->firstFlow($request, $id)) instanceof Response) {
            return $response;
        }

        $httpfile = $request->file('file_data');
        // file put(store)
        $filename = $httpfile->getClientOriginalName();
        // $uniqueFileName = ExmentFile::getUniqueFileName($this->custom_table->table_name, $filename);
        // $file = ExmentFile::store($httpfile, config('admin.upload.disk'), $this->custom_table->table_name, $uniqueFileName);
        $custom_value = $this->getModelNameDV()::find($id);
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
        
        set_time_limit(240);
        $class = $plugin->getClass([
            'custom_table' => $this->custom_table,
            'id' => $id
        ]);
        $response = $class->execute();
        if (isset($response)) {
            return getAjaxResponse($response);
        }
        return getAjaxResponse(false);
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
     * @return string
     */
    protected function getModelNameDV()
    {
        return getModelName($this->custom_table->table_name);
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
    protected function firstFlow(Request $request, $id = null, $show = false)
    {
        // if this custom_table doesn't have custom_columns, redirect custom_column's page(admin) or back
        if (!isset($this->custom_table->custom_columns) || count($this->custom_table->custom_columns) == 0) {
            if ($this->custom_table->hasPermission(Permission::CUSTOM_TABLE)) {
                admin_toastr(exmtrans('custom_value.help.no_columns_admin'), 'error');
                return redirect(admin_urls('column', $this->custom_table->table_name));
            }

            admin_toastr(exmtrans('custom_value.help.no_columns_user'), 'error');
            return back();
        }

        $this->setFormViewInfo($request);

        //Validation table value
        $roleValue = $show ? Permission::AVAILABLE_VIEW_CUSTOM_VALUE : Permission::AVAILABLE_EDIT_CUSTOM_VALUE;
        if (!$this->validateTable($this->custom_table, $roleValue)) {
            Checker::error();
            return false;
        }
            
        // id set, checking as update.
        if (isset($id)) {
            // if user doesn't have role for target id data, show deny error.
            if (!$this->custom_table->hasPermissionData($id)) {
                Checker::error();
                return false;
            }
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
}
