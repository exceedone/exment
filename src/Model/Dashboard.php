<?php

namespace Exceedone\Exment\Model;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request as Req;

class Dashboard extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DefaultFlgTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $guarded = ['id'];
    
    public function dashboard_boxes(){
        return $this->hasMany(DashboardBox::class, 'dashboard_id')
        ->orderBy('row_no')
        ->orderBy('column_no');
    }
    
    public function dashboard_row1_boxes(){
        return $this->hasMany(DashboardBox::class, 'dashboard_id')
        ->where('row_no', 1)
        ->orderBy('row_no')
        ->orderBy('column_no');
    }

    public function dashboard_row2_boxes(){
        return $this->hasMany(DashboardBox::class, 'dashboard_id')
        ->where('row_no', 2)
        ->orderBy('row_no')
        ->orderBy('column_no');
    }
    
    /**
     * get default dashboard
     */
    public static function getDefault(){
        $user = Admin::user();
        // get request
        $request = Req::capture();

        // get dashboard using query
        if(!is_null($request->input('dashboard'))){
            $suuid = $request->input('dashboard');
            // if query has view id, set form.
            $dashboard = static::findBySuuid($suuid);
            // set suuid
            if (isset($user)) {
                $user->setSettingValue(Define::USER_SETTING_DASHBOARD, $suuid);
            }
        }
        // if url doesn't contain dashboard query, get dashboard user setting.
        if(!isset($dashboard) && isset($user)){
            // get suuid
            $suuid = $user->getSettingValue(Define::USER_SETTING_DASHBOARD);
            $dashboard = static::findBySuuid($suuid);
        }
        // if null, get dashboard first.
        if(!isset($dashboard)){
            $dashboard = static::where('default_flg', true)->first();
        }
        if(!isset($dashboard)){
            $dashboard = static::first();
        }

        // create new dashboard
        if(!isset($dashboard)){
            $dashboard = new Dashboard;
            $dashboard->dashboard_type = 'system';
            $dashboard->dashboard_name = 'system_default_dashboard';
            $dashboard->dashboard_view_name = exmtrans('dashboard.default_dashboard_name');
            $dashboard->row1 = 1;
            $dashboard->row2 = 2;
            $dashboard->save();
        }

        return $dashboard;
    }
    
    protected static function boot() {
        parent::boot();
        
        static::creating(function($model) {
            $model->setDefaultFlg();
        });
        static::updating(function($model) {
            $model->setDefaultFlg();
        });
    }
}
