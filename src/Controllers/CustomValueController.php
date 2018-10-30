<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\ModelForm;
use Encore\Admin\Middleware\Pjax;
use Encore\Admin\Form\Field;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Symfony\Component\HttpFoundation\Response;

class CustomValueController extends AdminControllerTableBase
{
    use ModelForm, AuthorityForm, CustomValueGrid, CustomValueForm, CustomValueShow;
    protected $plugins = [];

    /**
     * CustomValueController constructor.
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);

        $this->setPageInfo($this->custom_table->table_view_name, $this->custom_table->table_view_name, $this->custom_table->description);

        if (!is_null($this->custom_table)) {
            //Get all plugin satisfied
            $this->plugins = PluginInstaller::getPluginByTable($this->custom_table->table_name);
        }
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->setFormViewInfo($request);
        $this->AdminContent($content);

        // if table setting is "one_record_flg" (can save only one record)
        if (boolval($this->custom_table->one_record_flg)) {
            // get record list
            $record = $this->getModelNameDV()::first();
            // has record, execute
            if (isset($record)) {
                $id = $record->id;
                $form = $this->form($id)->edit($id);
                $form->setAction(admin_base_path("data/{$this->custom_table->table_name}/$id"));
                disableFormFooter($form);
                $content->body($form);
            }
            // no record
            else {
                $form = $this->form(null);
                disableFormFooter($form);
                $form->setAction(admin_base_path("data/{$this->custom_table->table_name}"));
                $content->body($form);
            }
        } else {
            $content->body($this->grid());
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
        $this->setFormViewInfo($request);
        //Validation table value
        if(!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUES_AVAILABLE_EDIT_CUSTOM_VALUE)){
            return;
        }
        // if user doesn't have permission creating data, throw admin.dany error.
        if (!Admin::user()->hasPermissionTable($this->custom_table->table_name, Define::AUTHORITY_VALUES_AVAILABLE_EDIT_CUSTOM_VALUE)) {
            $response = response($this->AdminContent()->withError(trans('admin.deny')));
            Pjax::respond($response);
        }

        $this->AdminContent($content);
        PluginInstaller::pluginPreparing($this->plugins, 'loading');
        $content->body($this->form(null));
        PluginInstaller::pluginPreparing($this->plugins, 'loaded');
        return $content;
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit(Request $request, $id, Content $content)
    {
        $this->setFormViewInfo($request);
        //Validation table value
        if(!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUES_AVAILABLE_EDIT_CUSTOM_VALUE)){
            return;
        }
        // if user doesn't have authority for target id data, show deny error.
        if (!Admin::user()->hasPermissionData($id, $this->custom_table->table_name)) {
            Checker::error();
            return false;
        }
        // if user doesn't have edit permission, redirect to show
        $redirect = $this->redirectShow($id);
        if (isset($redirect)) {
            return $redirect;
        }

        $this->AdminContent($content);
        PluginInstaller::pluginPreparing($this->plugins, 'loading');
        $content->body($this->form($id)->edit($id));
        PluginInstaller::pluginPreparing($this->plugins, 'loaded');
        return $content;
    }
    
    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        // call form using id
        $response = $this->form($id)->update($id);
        return $response;
    }

    /**
     * Show interface.
     *
     * @param $id
     * @return Content
     */
    public function show(Request $request, $id, Content $content)
    {
        $this->setFormViewInfo($request);
        //Validation table value
        if(!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUES_AVAILABLE_ACCESS_CUSTOM_VALUE)){
            return;
        }
        // if user doesn't have authority for target id data, show deny error.
        if (!Admin::user()->hasPermissionData($id, $this->custom_table->table_name)) {
            Checker::error();
            return false;
        }
        $this->AdminContent($content);
        $content->body($this->createShowForm($id));
        return $content;
    }
    /**
     * for file delete function.
     */
    public function filedelete(Request $request, $id){
        //Validation table value
        if(!$this->validateTable($this->custom_table, Define::AUTHORITY_VALUES_AVAILABLE_EDIT_CUSTOM_VALUE)){
            return;
        }
        // if user doesn't have authority for target id data, show deny error.
        if (!Admin::user()->hasPermissionData($id, $this->custom_table->table_name)) {
            Checker::error();
            return false;
        }
        // if user doesn't have edit permission, redirect to show
        $redirect = $this->redirectShow($id);
        if (isset($redirect)) {
            return $redirect;
        }

        // get file delete flg column name
        $del_column_name = $request->input(Field::FILE_DELETE_FLAG);
        /// file remove
        $form = $this->form($id);
        $fields = $form->builder()->fields();
        // filter file
        $fields->filter(function ($field) use($del_column_name) {
            return $field instanceof Field\Embeds;
        })->each(function ($field) use($del_column_name, $id) {
            // get fields
            $embedFields = $field->fields();
            $embedFields->filter(function ($field) use($del_column_name) {
                return $field->column() == $del_column_name;
            })->each(function ($field) use($del_column_name, $id) {
                // get file path
                $obj = getModelName($this->custom_table)::find($id);
                $original = $obj->getValue($del_column_name, true);
                $field->setOriginal($obj->value);

                $field->destroy(); // delete file
                \Exceedone\Exment\Model\File::deleteFileInfo($original); // delete file table
                $obj->setValue($del_column_name, null)
                    ->remove_file_columns($del_column_name)
                    ->save();
            });
        });

        return response([
            'status'  => true,
            'message' => trans('admin.update_succeeded'),
        ]);
    }

    //Function handle plugin click event
    /**
     * @param Request $request
     * @return Response
     */
    public function pluginClick(Request $request, $id = null)
    {
        if ($request->input('uuid') === null) {
            abort(404);
        }
        // get plugin
        $plugin = Plugin::where('uuid', $request->input('uuid'))->first();
        if(!isset($plugin)){
            abort(404);
        }
        
        $classname = getPluginNamespace(array_get($plugin, 'plugin_name'), 'Plugin');
        if (class_exists($classname)) {
            switch(array_get($plugin, 'plugin_type')){
                case 'document':
                    $class = new $classname($this->custom_table, $id);
                    break;
            }
            $response = $class->execute();
        }
        if (isset($response) && $response instanceof HttpResponse) {
            return $response;
        }
        //TODO:error
        return response([
            'status'  => false,
            'message' => null,
        ]);
    }

    //Function handle copy click event
    /**
     * @param Request $request
     * @return Response
     */
    public function copyClick(Request $request, $id = null)
    {
        if ($request->input('uuid') === null) {
            abort(404);
        }
        // get copy eloquent
        $copy = CustomCopy::findBySuuid($request->input('uuid'));
        if(!isset($copy)){
            abort(404);
        }
        
        // execute copy
        $custom_value = getModelName($this->custom_table)::find($id);
        $copy->execute($custom_value);

        $classname = getPluginNamespace(array_get($plugin, 'plugin_name'), 'Plugin');
        if (class_exists($classname)) {
            switch(array_get($plugin, 'plugin_type')){
                case 'document':
                    $class = new $classname($this->custom_table, $id);
                    break;
            }
            $response = $class->execute();
        }
        if (isset($response) && $response instanceof HttpResponse) {
            return $response;
        }
        //TODO:error
        return response([
            'status'  => false,
            'message' => null,
        ]);
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
        if (!Admin::user()->hasPermissionEditData($id, $this->custom_table->table_name)) {
            return redirect(admin_base_path("data/{$this->custom_table->table_name}/$id"));
        }
        return null;
    }

    /**
     * get relation name etc for form block
     */
    protected function getRelationName($custom_form_block){
        $target_table = $custom_form_block->target_table;
        // get label hasmany
        $block_label = $custom_form_block->form_block_view_name;
        if (!isset($block_label)) {
            $block_label = exmtrans("custom_form.table_".array_get($custom_form_block, 'form_block_type')."_label") . $target_table->table_view_name;
        }
        // get form columns count
        $form_block_options = array_get($custom_form_block, 'options', []);
        $relation_name = getRelationNamebyObjs($this->custom_table, $target_table);

        return [$relation_name, $block_label];
    }
}
