<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Services\DataImportExport;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Request as Req;
use Exceedone\Exment\Form\Widgets\ModalForm;

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
                // filter type
                $column_type = array_get($search_column, 'column_type');
                switch($column_type){
                    case 'select':
                    case 'select_valtext':
                        $filter->equal($column_name, $column_view_name)->select(createSelectOptions($search_column));
                        break;
                    case 'select_table':
                    case 'user':
                    case 'organization':
                        // get select_target_table
                        if ($column_type == 'select_table') {
                            $select_target_table_id = array_get($search_column, 'options.select_target_table');
                            if (isset($select_target_table_id)) {
                                $select_target_table = CustomTable::find($select_target_table_id)->table_name;
                            } else {
                                $select_target_table = null;
                            }
                        } elseif ($column_type == Define::SYSTEM_TABLE_NAME_USER) {
                            $select_target_table = CustomTable::findByName(Define::SYSTEM_TABLE_NAME_USER)->table_name;
                        } elseif ($column_type == Define::SYSTEM_TABLE_NAME_ORGANIZATION) {
                            $select_target_table = CustomTable::findByName(Define::SYSTEM_TABLE_NAME_ORGANIZATION)->table_name;
                        }

                        // get options and ajax url
                        $options = getOptions($select_target_table);
                        $ajax = getOptionAjaxUrl($select_target_table);
                        if (isset($ajax)) {
                            $filter->equal($column_name, $column_view_name)->select([])->ajax($ajax);
                        }else{
                            $filter->equal($column_name, $column_view_name)->select($options);
                        }
                        break;
                    case 'yesno':
                        $filter->equal($column_name, $column_view_name)->radio([
                            ''   => 'All',
                            0    => 'NO',
                            1    => 'YES',
                        ]);
                        break;
                    case 'boolean':
                        $filter->equal($column_name, $column_view_name)->radio([
                            ''   => 'All',
                            array_get($search_column, 'options.false_value')    => array_get($search_column, 'options.false_label'),
                            array_get($search_column, 'options.true_value')    => array_get($search_column, 'options.true_label'),
                        ]);
                        break;
                    default:
                        $filter->like($column_name, $column_view_name);
                        break;
                }
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
                $column_name = getColumnName($column, true);
                $column_type = array_get($column, 'column_type');
                $column_view_name = array_get($column, 'column_view_name');

                //$grid->column($column_name, $column_view_name)->sortable();
                $grid->column(array_get($column, 'column_name'), $column_view_name)->sortable()->display(function($v) use($column){
                    if(is_null($this)){return '';}
                    return $this->getValue($column, true);
                });
            }
            // parent_id
            elseif($view_column_target == 'parent_id'){
                // get parent data
                $relation = CustomRelation
                    ::with('parent_custom_table')
                    ->where('child_custom_table_id', $this->custom_table->id)
                    ->first();
                if(isset($relation)){
                    $grid->column('parent_id', $relation->parent_custom_table->table_view_name)
                        ->sortable()
                        ->display(function($value){
                            // get parent_type
                            $parent_type = $this->parent_type;
                            if(is_null($parent_type)){return null;}
                            return getModelName($parent_type)::find($value)->getValue();
                    });
                }
            }
            // system column
            else {
                // get column name
                $grid->column($view_column_target, exmtrans("custom_column.system_columns.$view_column_target"))->sortable();
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
                    $actions->prepend('<a href="'.admin_base_path(url_join('data', $table_name, $actions->getKey(), 'edit')).'?form='.$form_id.'"><i class="fa fa-edit"></i></a>');
                }

                // if user does't edit permission disable edit row.
                if (!Admin::user()->hasPermissionEditData($actions->getKey(), $table_name)) {
                    $actions->disableEdit();      
                    $actions->disableDelete();
                }
            });
        }
    }
    
    /**
     * @param Request $request
     */
    public function import(Request $request)
    {
        // get file extenstion
        $format = DataImportExport\DataImporterBase::getFileExtension($request);
        $result = DataImportExport\DataImporterBase::getModel(CustomTable::find($request->custom_table_id), $format)
            ->import($request);

        return ModalForm::getAjaxResponse($result);
        // if ($result) {
        //     admin_toastr(exmtrans('common.message.import_success'));
        //     return back();
        // }
        // admin_toastr(exmtrans('common.message.import_error'), 'error');
    }


    public function ImportSettingModal()
    {
        $exmenImporter = DataImportExport\DataImporterBase::getModel($this->custom_table);
        return $exmenImporter->importModal();
        return $modal;
    }
}
