<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Form\Tools;

trait CustomValueCalendar
{
    protected function gridCalendar()
    {
        $table_name = $this->custom_table->table_name;
        $model = $this->getModelNameDV()::query();
        \Exment::user()->filterModel($model, $this->custom_view);

        $tools = [];
        $tools[] = new Tools\GridChangePageMenu('data', $this->custom_table, false);
        $tools[] = new Tools\GridChangeView($this->custom_table, $this->custom_view);

        return view('exment::widgets.calendar', [
            'view_id' => $this->custom_view->suuid,
            'data_url' => admin_url('webapi/data', [$this->custom_table->table_name, 'calendar']),
            'createUrl' => admin_url("data/$table_name/create"),
            'new' => trans('admin.new'),
            'tools' => $tools,
            'locale' => \App::getLocale(),
        ]);
    }
}
