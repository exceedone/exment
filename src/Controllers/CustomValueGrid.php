<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Exceedone\Exment\Enums\AuthorityValue;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as Req;

trait CustomValueGrid
{
    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $classname = $this->getModelNameDV();
        $grid = new Grid(new $classname);
        PluginInstaller::pluginPreparing($this->plugins, 'loading');
        
        // get search_enabled_columns and loop
        $search_enabled_columns = $this->custom_table->getSearchEnabledColumns();
    
        // create grid
        $this->custom_view->setGrid($grid);

        // manage row action
        $this->manageRowAction($grid);

        // filter
        Admin::user()->filterModel($grid->model(), $this->custom_table->table_name, $this->custom_view);
        $this->setCustomGridFilters($grid, $search_enabled_columns);

        // manage tool button
        $listButton = PluginInstaller::pluginPreparingButton($this->plugins, 'grid_menubutton');
        $this->manageMenuToolButton($grid, $listButton);

        // create exporter
        $grid->exporter(DataImportExport\DataExporterBase::getModel($grid, $this->custom_table, $search_enabled_columns));
        
        PluginInstaller::pluginPreparing($this->plugins, 'loaded');
        return $grid;
    }

    /**
     * set grid filter
     */
    protected function setCustomGridFilters($grid, $search_enabled_columns)
    {
        $grid->filter(function ($filter) use ($search_enabled_columns) {
            $filter->column(1/2, function ($filter) {
                $filter->between('created_at', exmtrans('common.created_at'))->date();
                $filter->between('updated_at', exmtrans('common.updated_at'))->date();
            });

            // loop custom column
            $filter->column(1/2, function ($filter) use ($search_enabled_columns) {
                // check 1:n relation
                $relation = CustomRelation
                    ::with('parent_custom_table')
                    ->where('child_custom_table_id', $this->custom_table->id)
                    ->first();
                // if set, create select
                if (isset($relation)) {
                    // get options and ajax url
                    $options = $relation->parent_custom_table->getOptions();
                    $ajax = $relation->parent_custom_table->getOptionAjaxUrl();
                    if (isset($ajax)) {
                        $filter->equal('parent_id', $relation->parent_custom_table->table_view_name)->select([])->ajax($ajax, 'id', 'label');
                    } else {
                        $filter->equal('parent_id', $relation->parent_custom_table->table_view_name)->select($options);
                    }
                }

                foreach ($search_enabled_columns as $search_column) {
                    $column_name = $search_column->getIndexColumnName();
                    $column_view_name = array_get($search_column, 'column_view_name');
                    // filter type
                    $column_type = array_get($search_column, 'column_type');
                    switch ($column_type) {
                        case 'select':
                        case 'select_valtext':
                            $filter->equal($column_name, $column_view_name)->select($search_column->createSelectOptions());
                            break;
                        case 'select_table':
                        case 'user':
                        case 'organization':
                            // get select_target_table
                            if ($column_type == 'select_table') {
                                $select_target_table_id = array_get($search_column, 'options.select_target_table');
                                if (isset($select_target_table_id)) {
                                    $select_target_table = CustomTable::find($select_target_table_id);
                                } else {
                                    $select_target_table = null;
                                }
                            } elseif ($column_type == SystemTableName::USER) {
                                $select_target_table = CustomTable::findByName(SystemTableName::USER);
                            } elseif ($column_type == SystemTableName::ORGANIZATION) {
                                $select_target_table = CustomTable::findByName(SystemTableName::ORGANIZATION);
                            }

                            // get options and ajax url
                            $options = $select_target_table->getOptions();
                            $ajax = $select_target_table->getOptionAjaxUrl();
                            if (isset($ajax)) {
                                $filter->equal($column_name, $column_view_name)->select([])->ajax($ajax, 'id', 'label');
                            } else {
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
                        
                        case 'date':
                        case 'datetime':
                            $filter->between($column_name, $column_view_name)->date();
                            break;
                        default:
                            $filter->like($column_name, $column_view_name);
                            break;
                    }
                }
            });
        });
    }

    /**
     * Manage Grid Tool Button
     * And Manage Batch Action
     */
    protected function manageMenuToolButton($grid, $listButton)
    {
        $custom_table = $this->custom_table;
        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->tools(function (Grid\Tools $tools) use ($listButton, $grid) {
            // have edit flg
            $edit_flg = Admin::user()->hasPermissionTable($this->custom_table->table_name, AuthorityValue::AVAILABLE_EDIT_CUSTOM_VALUE);
            // if user have edit permission, add button
            if ($edit_flg) {
                $tools->append(new Tools\ExportImportButton($this->custom_table->table_name, $grid));
                $tools->append(view('exment::custom-value.new-button', ['table_name' => $this->custom_table->table_name]));
                $tools->append($this->ImportSettingModal($this->custom_table->table_name));
            }
            
            // add page change button(contains view seting)
            $tools->append(new Tools\GridChangePageMenu('data', $this->custom_table, false));
            $tools->append(new Tools\GridChangeView($this->custom_table, $this->custom_view));
            
            // add plugin button
            if ($listButton !== null && count($listButton) > 0) {
                foreach ($listButton as $plugin) {
                    $tools->append(new Tools\PluginMenuButton($plugin, $this->custom_table));
                }
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

        return getAjaxResponse($result);
    }


    public function ImportSettingModal()
    {
        $exmenImporter = DataImportExport\DataImporterBase::getModel($this->custom_table);
        return $exmenImporter->importModal();
    }
}
