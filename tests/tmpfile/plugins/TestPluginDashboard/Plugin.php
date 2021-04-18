<?php

namespace App\Plugins\TestPluginDashboard;

use Exceedone\Exment\Services\Plugin\PluginDashboardBase;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;

class Plugin extends PluginDashboardBase
{
    /**
     *
     * @return \Encore\Admin\Layout\Content|\Illuminate\Http\Response
     */
    public function body()
    {
        $data = $this->getData();

        return view('exment_test_plugin_dashboard::sample', [
            'id' => $data->id,
            'params' => $this->getParams($data),
            'action' => admin_url($this->getDashboardUri('post')),
        ]);
    }

    /**
     * 送信
     *
     * @return void
     */
    public function post()
    {
        $id = request()->get('id');
        $data = $this->getData($id);
        
        $now = \Carbon\Carbon::now();

        $integer = $data->getValue('integer');
        switch (request()->get('action')) {
            case 'add':
                $data->setValue('integer', $integer + 1);
                break;
            case 'minus':
                $data->setValue('integer', $integer - 1);
                break;
        }
        $data->save();

        admin_toastr(trans('admin.save_succeeded'));
        return back();
    }

    /**
     * 現在のデータを取得
     *
     * @return CustomValue|null
     */
    protected function getData($id = null)
    {
        if (isset($id)) {
            return CustomTable::getEloquent('custom_value_edit_all')
                ->getValueModel($id);
        } else {
            return CustomTable::getEloquent('custom_value_edit_all')
            ->getValueModel()->where('value->user', \Exment::user()->base_user->id)->first();
        }
    }

    protected function getParams($data)
    {
        return
        [
            'integer' => $data->getValue('integer'),
            'buttons' => [
                [
                    'button_text' => '加算',
                    'action_name' => 'add',
                ],
                [
                    'button_text' => '減算',
                    'action_name' => 'minus',
                ],
            ]
        ];
    }
}
