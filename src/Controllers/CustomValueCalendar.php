<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid\Tools\RefreshButton;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Services\Plugin\PluginInstaller;

trait CustomValueCalendar
{
    protected function gridCalendar()
    {
        $table_name = $this->custom_table->table_name;
        $model = $this->getModelNameDV()::query();
        Admin::user()->filterModel($model, $table_name, $this->custom_view);

        $custom_view_columns = $this->custom_view->custom_view_columns->map(function ($column) {
            if ($column->view_column_type == ViewColumnType::COLUMN) {
                $target_column = $column->custom_column->getIndexColumnName();
            } else {
                $target_column = SystemColumn::getOption(['id' => $column->view_column_target_id])['name'];
            }
            return array('target_column' => $target_column, 'color' => $column->view_column_color);
        });

        $tasks = [];
        foreach($model->get() as $row) {
            $title = $row->getLabel();
            $url = $row->getUrl();

            foreach($custom_view_columns as $custom_view_column) {
                $target_column = array_get($custom_view_column, 'target_column');
                $tasks[] = array(
                    'title' => $title,
                    'start' => $row->{$target_column},
                    'url' => $url,
                    'color' => array_get($custom_view_column, 'color'),
                );
            }
        }

        $tools = [];
        $tools[] = new Tools\GridChangePageMenu('data', $this->custom_table, false);
        $tools[] = new Tools\GridChangeView($this->custom_table, $this->custom_view);
        $tools[] = new RefreshButton();

        return view('exment::widgets.calendar', [
            'tasks' => $tasks,
            'createUrl' => admin_url("data/$table_name/create"),
            'new' => trans('admin.new'),
            'tools' => $tools,
            'locale' => \App::getLocale(),
        ]);
    }
}
