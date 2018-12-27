<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\UserSetting;
use Illuminate\Http\Request as Req;

class Dashboard extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\DefaultFlgTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $guarded = ['id'];
    protected $casts = ['options' => 'json'];

    public function dashboard_boxes()
    {
        return $this->hasMany(DashboardBox::class, 'dashboard_id')
        ->orderBy('row_no')
        ->orderBy('column_no');
    }
    
    public function dashboard_row1_boxes()
    {
        return $this->dashboard_row_boxes(1);
    }

    public function dashboard_row2_boxes()
    {
        return $this->dashboard_row_boxes(2);
    }
    
    public function dashboard_row3_boxes()
    {
        return $this->dashboard_row_boxes(3);
    }

    public function dashboard_row4_boxes()
    {
        return $this->dashboard_row_boxes(4);
    }

    protected function dashboard_row_boxes($row_no)
    {
        return $this->hasMany(DashboardBox::class, 'dashboard_id')
        ->where('row_no', $row_no)
        ->orderBy('row_no')
        ->orderBy('column_no');
    }
    
    /**
     * get default dashboard
     */
    public static function getDefault()
    {
        $user = Admin::user();
        // get request
        $request = Req::capture();

        // get dashboard using query
        if (!is_null($request->input('dashboard'))) {
            $suuid = $request->input('dashboard');
            // if query has view id, set form.
            $dashboard = static::findBySuuid($suuid);
            // set suuid
            if (isset($user)) {
                $user->setSettingValue(UserSetting::DASHBOARD, $suuid);
            }
        }
        // if url doesn't contain dashboard query, get dashboard user setting.
        if (!isset($dashboard) && isset($user)) {
            // get suuid
            $suuid = $user->getSettingValue(UserSetting::DASHBOARD);
            $dashboard = static::findBySuuid($suuid);
        }
        // if null, get dashboard first.
        if (!isset($dashboard)) {
            $dashboard = static::where('default_flg', true)->first();
        }
        if (!isset($dashboard)) {
            $dashboard = static::first();
        }

        // create new dashboard
        if (!isset($dashboard)) {
            $dashboard = new Dashboard;
            $dashboard->dashboard_type = DashboardType::SYSTEM;
            $dashboard->dashboard_name = 'system_default_dashboard';
            $dashboard->dashboard_view_name = exmtrans('dashboard.default_dashboard_name');
            $dashboard->options = ['row1' => 1, 'row2' => 2, 'row3' => 0, 'row4' => 0];
            $dashboard->save();
        }

        return $dashboard;
    }
    
    public function getOption($key, $default = null)
    {
        return $this->getJson('options', $key, $default);
    }
    public function setOption($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('options', $key, $val, $forgetIfNull);
    }
    public function forgetOption($key)
    {
        return $this->forgetJson('options', $key);
    }
    public function clearOption()
    {
        return $this->clearJson('options');
    }
    
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            $model->setDefaultFlg();
        });
        static::updating(function ($model) {
            $model->setDefaultFlg();
        });
    }
}
