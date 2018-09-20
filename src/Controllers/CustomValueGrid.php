<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\ExmentExporters\ExmentExporter;
use Exceedone\Exment\ExmentImporters\ExmentImporter;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Request as Req;

trait CustomValueGrid
{
    /**
     * set grid filter
     */
    protected function setCustomGridFilters($grid, $search_enabled_columns)
    {
        $grid->filter(function ($filter) use ($search_enabled_columns) {
            // loop custom column
            foreach ($search_enabled_columns as $search_column) {
                $column_name = getColumnName($search_column);
                $column_view_name = array_get($search_column, 'column_view_name');
                $filter->equal($column_name, $column_view_name);
            }
        });
    }

    /**
     * Create Grid
     * And Manage Batch Action
     */
    protected function createGrid($grid)
    {
        $custom_table = $this->custom_table;
        // get view columns
        $custom_view_columns = $this->custom_view->custom_view_columns()->get();
        foreach ($custom_view_columns as $custom_view_column) {
            $view_column_target = array_get($custom_view_column, 'view_column_target');
            // if tagret is number, column type is column.
            if (is_numeric($view_column_target)) {
                $column = $custom_view_column->custom_column;
                $column_name = getColumnName($column);
                $column_type = array_get($column, 'column_type');
                $column_view_name = array_get($column, 'column_view_name');

                // if column is select_table, get select value for display.
                if (in_array($column_type, ['select_table', 'user', 'organization'])) {
                    $grid->column($column_name, $column_view_name)
                            ->display(function ($value) use ($column, $custom_table, $column_type) {
                                if (is_null($value)) {
                                    return '';
                                }
                                // get target column
                                // switch $column_type
                                switch($column_type){
                                    // if user, org
                                    case Define::SYSTEM_TABLE_NAME_USER:
                                    case Define::SYSTEM_TABLE_NAME_ORGANIZATION:
                                        //get table id by finding by name
                                        $table_id = CustomTable::findByName($column_type)->id ?? null;
                                        break;
                                    default:
                                        //get table id by array_get options.select_target_table
                                        $table_id = array_get($column, 'options.select_target_table');
                                        break;
                                }
                                if(!isset($table_id)){return null;}
                                $label = getLabelColumn($table_id);

                                // if not multiple, return 
                                if(!boolval(array_get($column->options, 'multiple_enabled'))){
                                    return array_get($value, 'value.'.$label->column_name);
                                }
                                // if multiple, split ","
                                $labels = [];
                                foreach($value as $v){
                                    $labels[] = array_get($v, 'value.'.$label->column_name);
                                }
                                return implode(",", $labels);
                            });
                } else {
                    $grid->column($column_name, $column_view_name);
                }
                
            }
            // system column
            else {
                // get column name
                $view_column_target = array_get($custom_view_column, 'view_column_target');
                $grid->column($view_column_target, exmtrans("custom_column.system_columns.$view_column_target"));
            }
        }
    }

    /**
     * Manage Grid Tool Button
     * And Manage Batch Action
     */
    protected function manageMenuToolButton($grid, $listButton)
    {
        $table_id = $this->custom_table->id;
        $table_name = $this->custom_table->table_name;
        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->tools(function (Grid\Tools $tools) use ($table_id, $table_name, $listButton, $grid) {
            if ($listButton !== null && count($listButton) > 0) {
                $index = 0;
                foreach ($listButton as $buttonItem) {
                    $index++;
                    $button = '<a class="btn btn-sm btn-info" onclick="onPluginClick'.$index.'()"><i class="fa fa-archive"></i>&nbsp;'.$buttonItem->plugin_view_name.'</a>';
                    $tools->append($button);
                    $ajaxContainer = '<script>
                        function onPluginClick'.$index.'() {
                            $.ajax({
                                type: "POST",
                                url: admin_base_path("data/'.$table_name.'/onPluginClick"),
                                data:{_token: LA.token,plugin_name:"'.$buttonItem->plugin_name.'"},
                                success:function(reponse) {
                                    toastr.success(reponse);
                                }
                            });
                        }
                    </script>';
                    $tools->append($ajaxContainer);
                }
            }
            
            // have edit flg
            $edit_flg = Admin::user()->hasPermissionTable($table_name, Define::AUTHORITY_VALUES_AVAILABLE_EDIT_CUSTOM_VALUE);
            // if user have edit permission, add button
            if ($edit_flg) {
                $tools->append(new Tools\ExportImportButton($table_name, $grid));
                $tools->append(view('exment::custom-value.new-button', ['table_name' => $table_name]));
                $tools->append($this->ImportSettingModal($table_name));
            }
            
            // add page change button(contains view seting)
            $tools->append(new Tools\GridChangePageMenu('data', $this->custom_table, false));
            $tools->append(new Tools\GridChangeView($this->custom_table, $this->custom_view));

            // TODO:hard coding
            // when estimate, add pdf button
            $table_name = $this->custom_table->table_name;
            if (in_array($table_name, ['estimate', 'invoice'])) {
                $error = exmtrans('common.error');
                $error_message = exmtrans('change_page_menu.error_select');
                
                $script = <<<EOT
                $('#estimate_button').off('click').on('click',function(ev){
                    // get select row
                    var rows = selectedRows();
                    if(rows.length !== 1){
                        swal("$error", "$error_message", "error");
                        return;
                    }
                    else{
                        var id = rows[0];
                        var url = admin_base_path(URLJoin('data/$table_name', id, 'doc'));
                        window.open(url);
                    }
                });

EOT;
                Admin::script($script);
                $button = '<a id="estimate_button" class=" pull-right btn btn-sm btn-info" href="javascript:void(0);" style="margin-right:5px;"><i class="fa fa-file-text-o"></i>&nbsp;'. ($table_name == 'estimate' ?  '見積書' : '請求書'). '出力</a>';
                $tools->append($button);
            }

            // manage batch --------------------------------------------------
            // if cannot edit, disable delete
            if (!$edit_flg) {
                $tools->batch(function ($batch) {
                    $batch->disableDelete();
                });
            }
        });
    }

    /**
     * Management row action
     */
    protected function manageRowAction($grid)
    {
        if (isset($this->custom_table)) {
            // name
            $table_name = $this->custom_table->table_name;
            $table_id = $this->custom_table->id;
            $grid->actions(function (Grid\Displayers\Actions $actions) use ($table_name) {
                $form_id = Req::get('form');
                // if has $form_id, remove default edit link, and add new link added form query
                if (isset($form_id)) {
                    $actions->disableEdit();
                    $actions->prepend('<a href="'.admin_base_path('data/'.$table_name.'/'.$actions->getKey().'/edit').'?form='.$form_id.'"><i class="fa fa-edit"></i></a>');
                }
            });
        }
    }
    
    // /**
    //  * @param $exporter
    //  * @param $grid
    //  * @param $search_enabled_columns
    //  */
    // public function exporter($exporter, $grid)
    // {
    //     $this->exporter = $exporter;

    //     return $this->setupExporter($grid);
    // }

    // /**
    //  * @param $grid
    //  * @param $search_enabled_columns
    //  */
    // protected function setupExporter($grid)
    // {
    //     //$get_temp = strpos($_SERVER["REQUEST_URI"], 'temp') !== false ? true : false;
    //     $get_temp = \Illuminate\Support\Facades\Request::query('_export_') == 'temp';
    //     if ($scope = Input::get(ExmentExporter::$queryName)) {
    //         $grid->exporter();
    //         (new ExmentExporter($grid))->resolve($this->exporter)->withScope($scope)->export($this->custom_table, $search_enabled_columns, $get_temp);
    //     }
    // }

    /**
     * @param Request $request
     */
    public function import(Request $request)
    {
        $result = (new ExmentImporter())->import($request);

        if ($result) {
            admin_toastr(exmtrans('common.message.import_success'));
            return back();
        }
        admin_toastr(exmtrans('common.message.import_error'), 'error');
    }


    public function ImportSettingModal($table_name)
    {
        $exmenImporter = new ExmentImporter();
        $selectCustomColumn = $exmenImporter->getCustomColumn($this->custom_table->id);

        // create form fields
        $form = new \Encore\Admin\Widgets\Form();
        $form->action(admin_base_path('data/'.$this->custom_table->table_name.'/import'))
            ->file('custom_table_file', exmtrans('custom_value.import.import_file'))
            ->rules('mimes:csv')->setWidth(8, 3)->addElementClass('exment_import_file')
            ;
        $form->disablePjax();
            
        $form->select('select_primary_key', exmtrans('custom_value.import.primary_key'))
            ->options(getTransArray(Define::CUSTOM_VALUE_IMPORT_KEY, "custom_value.import.key_options"))
            ->setWidth(8, 3)
            ->addElementClass('select_primary_key')
            ->help(exmtrans('custom_value.import.help.primary_key'));


        $form->select('select_action', exmtrans('custom_value.import.error_flow'))
            ->options(getTransArray(Define::CUSTOM_VALUE_IMPORT_ERROR, "custom_value.import.error_options"))
            ->setWidth(8, 3)
            ->addElementClass('select_action')
            ->help(exmtrans('custom_value.import.help.error_flow'));
    
        $form->hidden('custom_table_name')->default($table_name);
        $form->hidden('custom_table_suuid')->default($this->custom_table->suuid);
        $form->hidden('custom_table_id')->default($this->custom_table->id);

        $modal = view('exment::custom-value.import-modal', ['form' => $form]);

        // Add script
//         $script = <<<EOT
//         $(document).ready(function(){
//                             $("#data_import_modal [submit]").click(function () {
//                                 var file_name = $('.file-caption-name').attr("title");
//                                 var primary_key = $('#import-form').find('span[id^="select2-select_primary_key"]').attr( "title" );
//                                 var primary_key_placeholder = $('#import-form').find('span[id^="select2-select_primary_key"]').find('span[class^="select2-selection__placeholder"]').text();
//                                 var action = $('#import-form').find('span[id^="select2-select_action"]').attr( "title" );
//                                 var action_placeholder = $('#import-form').find('span[id^="select2-select_action"]').find('span[class^="select2-selection__placeholder"]').text();
//                                 if(file_name === undefined || file_name === "" ){
//                                     $('.file-caption-name').parent().css( "border-color", "red" );
//                                 }
//                                 else {
//                                     $('.file-caption-name').parent().css( "border-color", "#d2d6de" );
//                                 }
//                                 if( primary_key === undefined || (primary_key.indexOf(primary_key_placeholder) === -1 && primary_key_placeholder !== "")){
//                                     $('#import-form').find('span[id^="select2-select_primary_key"]').parent().css( "border-color", "red" );
//                                 }
//                                 else {
//                                     $('#import-form').find('span[id^="select2-select_primary_key"]').parent().css( "border-color", "#d2d6de" );
//                                 }
//                                 if(action === undefined || (action.indexOf(action_placeholder) === -1 && action_placeholder !== "")){
//                                     $('#import-form').find('span[id^="select2-select_action"]').parent().css( "border-color", "red" );
//                                 }
//                                 else {
//                                     $('#import-form').find('span[id^="select2-select_action"]').parent().css( "border-color", "#d2d6de" );
//                                 }
//                                 if(file_name === undefined || primary_key === undefined || (primary_key.indexOf(primary_key_placeholder) === -1 && primary_key_placeholder !== "")
//                                 || action === undefined || (action.indexOf(action_placeholder) === -1 && action_placeholder !== "" || file_name === "")){
//                                     toastr.error("Please fill all red input");
//                                     return false;
//                                 }
//                                 $('.modal-backdrop').remove();
//                             });
//                         });
        // EOT;
//             Admin::script($script);
    
        return $modal;
    }
}
