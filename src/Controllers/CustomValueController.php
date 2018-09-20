<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\ModelForm;
use Encore\Admin\Middleware\Pjax;
use Encore\Admin\Form\Field;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\PluginInstaller;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\ExmentExporters\ExmentExporter;

class CustomValueController extends AdminControllerTableBase
{
    use ModelForm, AuthorityForm, DocumentForm, CustomValueGrid, CustomValueForm;
    protected $plugins = [];
    //use ModelForm, AuthorityForm;

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
            $this->plugins = PluginInstaller::getPluginByTableId($this->custom_table->id);
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
        $listButton = PluginInstaller::pluginPreparingButton($this->plugins, 'grid_menubutton');
        $this->AdminContent($content);

        // if table setting is "one_record_flg" (can save only one record)
        if (boolval($this->custom_table->one_record_flg)) {
            // get record list
            $record = $this->getModelNameDV()::first();
            // has record, execute
            if (isset($record)) {
                $id = $record->id;
                $listButton = PluginInstaller::pluginPreparingButton($this->plugins, 'form_menubutton_create');
                $form = $this->form($id)->edit($id);
                $form->setAction(admin_base_path("data/{$this->custom_table->table_name}/$id"));
                $content->body($form);
            }
            // no record
            else {
                $listButton = PluginInstaller::pluginPreparingButton($this->plugins, 'form_menubutton_edit');
                $form = $this->form(null);
                $form->setAction(admin_base_path("data/{$this->custom_table->table_name}"));
                $content->body($form);
            }
        } else {
            $content->body($this->grid($listButton));
        }
        return $content;
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
        // if user doesn't have authority for target id data, show deny error.
        if (!Admin::user()->hasPermissionData($id, $this->custom_table->table_name)) {
            $response = response($this->AdminContent()->withError(trans('admin.deny')));
            Pjax::respond($response);
        }
        $this->AdminContent($content);
        $content->body($this->createShowForm($id));
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
        // if user doesn't have authority for target id data, show deny error.
        if (!Admin::user()->hasPermissionData($id, $this->custom_table->table_name)) {
            $response = response($this->AdminContent()->withError(trans('admin.deny')));
            Pjax::respond($response);
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
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request, Content $content)
    {
        $this->setFormViewInfo($request);
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
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid($listButton = null)
    {
        $classname = $this->getModelNameDV();
        $grid = new Grid(new $classname);
        PluginInstaller::pluginPreparing($this->plugins, 'loading');
        
        // get search_enabled_columns and loop
        $search_enabled_columns = getSearchEnabledColumns($this->custom_table->table_name);
    
        // create grid
        $this->createGrid($grid);

        // manage row action
        $this->manageRowAction($grid);

        // filter
        Admin::user()->filterModel($grid->model(), $this->custom_table->table_name, $this->custom_view);
        $this->setCustomGridFilters($grid, $search_enabled_columns);

        // manage tool button
        $this->manageMenuToolButton($grid, $listButton);

        // create exporter
        $grid->exporter(new ExmentExporter($grid, $this->custom_table, $search_enabled_columns, boolval(\Request::capture()->query('temp'))));
        
        PluginInstaller::pluginPreparing($this->plugins, 'loaded');
        return $grid;
    }

    /**
     * Make a form builder.
     * @param $id if edit mode, set model id
     * @return Form
     */
    protected function form($id = null)
    {
        $this->setFormViewInfo(\Request::capture());

        $classname = $this->getModelNameDV();
        $form = new Form(new $classname);

        //PluginInstaller::pluginPreparing($this->plugins, 'loading');
        // create
        if (!isset($id)) {
            $isButtonCreate = true;
            $listButton = PluginInstaller::pluginPreparingButton($this->plugins, 'form_menubutton_create');
        }
        // edit
        else {
            $isButtonCreate = false;
            $listButton = PluginInstaller::pluginPreparingButton($this->plugins, 'form_menubutton_edit');
        }

        //TODO: escape laravel-admin bug.
        //https://github.com/z-song/laravel-admin/issues/1998
        $form->hidden('laravel_admin_escape');

        // loop for custom form blocks
        foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
            // if available is false, continue
            if (!$custom_form_block->available) {
                continue;
            }
            // when default block, set as normal form columns.
            if ($custom_form_block->form_block_type == Define::CUSTOM_FORM_BLOCK_TYPE_DEFAULT) {
                //$form->embeds('value', $this->custom_form->form_view_name, function (Form\EmbeddedForm $form) use($custom_form_block) {
                $form->embeds('value', exmtrans("common.input"), function (Form\EmbeddedForm $form) use ($custom_form_block) {
                    $this->setCustomFormColumns($form, $custom_form_block);
                });
            } elseif ($custom_form_block->form_block_type == Define::CUSTOM_FORM_BLOCK_TYPE_RELATION_ONE_TO_MANY) {
                $target_table = $custom_form_block->target_table;
                // get label hasmany
                $block_label = $custom_form_block->form_block_view_name;
                if (!isset($block_label)) {
                    $block_label = exmtrans('custom_form.table_one_to_many_label') . $target_table->table_view_name;
                }
                $form->hasManyTable(
                    getRelationNamebyObjs($this->custom_table, $target_table),
                    $block_label,
                    function ($form) use ($custom_form_block) {
                        $form->nestedEmbeds('value', $this->custom_form->form_view_name, function (Form\EmbeddedForm $form) use ($custom_form_block) {
                            $this->setCustomFormColumns($form, $custom_form_block);
                        });
                    }
                )->setTableWidth(12, 0);
            // when many to many
            } else {
                $target_table = $custom_form_block->target_table;
                // get label hasmany
                $block_label = $custom_form_block->form_block_view_name;
                if (!isset($block_label)) {
                    $block_label = exmtrans('custom_form.table_many_to_many_label') . $target_table->table_view_name;
                }

                $field = new Field\MultipleSelect(
                    getRelationNamebyObjs($this->custom_table, $target_table),
                    [$block_label]
                );
                $field->options(function ($select) use ($target_table) {
                    return getOptions($target_table, $select);
                });
                if (getModelName($target_table)::count() > 100) {
                    $field->ajax(getOptionAjaxUrl($target_table));
                }
                $form->pushField($field);
            }
        }

        $calc_formula_array = [];
        $changedata_array = [];
        $this->setCustomForEvents($calc_formula_array, $changedata_array);

        // add calc_formula_array and changedata_array info
        if (count($calc_formula_array) > 0) {
            $json = json_encode($calc_formula_array);
            $script = <<<EOT
            var json = $json;
            Exment.CommonEvent.setCalcEvent(json);
EOT;
            Admin::script($script);
        }
        if (count($changedata_array) > 0) {
            $json = json_encode($changedata_array);
            $script = <<<EOT
            var json = $json;
            Exment.CommonEvent.setChangedataEvent(json);
EOT;
            Admin::script($script);
        }

        // add authority form --------------------------------------------------
        $this->addAuthorityForm($form, Define::AUTHORITY_TYPE_VALUE);

        // add form saving and saved event
        $this->manageFormSaving($form);
        $this->manageFormSaved($form);

        $form->disableReset();

        $isNew = $this->isNew();
        $custom_table = $this->custom_table;
        $custom_form = $this->custom_form;

        $this->manageFormToolButton($form, $id, $isNew, $custom_table, $custom_form, $isButtonCreate, $listButton);
        return $form;
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
}
