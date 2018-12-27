<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\HasResourceActions;
use Encore\Admin\Form\Field;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Enums\AuthorityValue;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Services\Plugin\PluginDocumentDefault;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Symfony\Component\HttpFoundation\Response;

class CustomValueController extends AdminControllerTableBase
{
    use HasResourceActions, AuthorityForm, CustomValueGrid, CustomValueForm, CustomValueShow, CustomValueSummary;
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
        $one_record_flg = boolval(array_get($this->custom_table->options, 'one_record_flg'));
        if ($one_record_flg) {
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
            if ($this->custom_view->view_kind_type == ViewKindType::AGGREGATE) {
                $content->body($this->gridSummary());
            } else {
                $content->body($this->grid());
            }
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
        $this->firstFlow($request);
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
        $this->firstFlow($request, $id);

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
        $this->firstFlow($request, $id);

        $modal = boolval($request->get('modal'));
        if ($modal) {
            return $this->createShowForm($id, $modal);
        }

        $this->AdminContent($content);
        $content->row($this->createShowForm($id));
        $content->row(function($row) use($id){
            $this->setOptionBoxes($row, $id, false);
        });
        return $content;
    }

    /**
     * file delete custom column.
     */
    public function filedelete(Request $request, $id)
    {
        $this->firstFlow($request, $id);

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
                \Exceedone\Exment\Model\File::deleteFileInfo($original); // delete file table
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
     * for file upload function.
     */
    public function fileupload(Request $request, $id)
    {
        $this->firstFlow($request, $id);
        
        $httpfile = $request->file('file_data');
        // file put(store)
        $filename = $httpfile->getClientOriginalName();
        $uniqueFileName = ExmentFile::getUniqueFileName($this->custom_table->table_name, $filename);
        // $file = ExmentFile::store($httpfile, config('admin.upload.disk'), $this->custom_table->table_name, $uniqueFileName);
        $file = ExmentFile::storeAs($httpfile, $this->custom_table->table_name, $filename, $uniqueFileName);

        // save document model
        $custom_value = $this->getModelNameDV()::find($id);
        $document_model = $file->saveDocumentModel($custom_value, $filename);
        
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
    public function pluginClick(Request $request, $id = null)
    {
        if ($request->input('uuid') === null) {
            abort(404);
        }
        // get plugin
        $plugin = Plugin::where('uuid', $request->input('uuid'))->first();
        if (!isset($plugin)) {
            abort(404);
        }
        
        set_time_limit(240);
        
        $classname = $plugin->getNameSpace('Plugin');
        $fuleFullPath = $plugin->getFullPath('Plugin.php');
        if (\File::exists($fuleFullPath) && class_exists($classname)) {
            switch (array_get($plugin, 'plugin_type')) {
                case PluginType::DOCUMENT:
                    $class = new $classname($plugin, $this->custom_table, $id);
                    break;
                case PluginType::TRIGGER:
                    $class = new $classname($plugin, $this->custom_table, $id);
                    break;
            }
        } else {
            // set default class
            $class = new PluginDocumentDefault($plugin, $this->custom_table, $id);
        }
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
    public function copyClick(Request $request, $id = null)
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
    protected function getRelationName($custom_form_block)
    {
        $target_table = $custom_form_block->target_table;
        // get label hasmany
        $block_label = $custom_form_block->form_block_view_name;
        if (!isset($block_label)) {
            $block_label = exmtrans("custom_form.table_".array_get($custom_form_block, 'form_block_type')."_label") . $target_table->table_view_name;
        }
        // get form columns count
        $form_block_options = array_get($custom_form_block, 'options', []);
        $relation_name = CustomRelation::getRelationNameByTables($this->custom_table, $target_table);

        return [$relation_name, $block_label];
    }

    /**
     * First flow. check authority and set form and view id etc.
     * different logic for new or update
     */
    protected function firstFlow(Request $request, $id = null)
    {
        $this->setFormViewInfo($request);
        //Validation table value
        if (!$this->validateTable($this->custom_table, AuthorityValue::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return false;
        }
            
        // id set, checking as update.
        if(isset($id)){
            // if user doesn't have authority for target id data, show deny error.
            if (!Admin::user()->hasPermissionData($id, $this->custom_table->table_name)) {
                Checker::error();
                return false;
            }
        }

        return true;
    }
}
