<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\UserSetting;
use Illuminate\Http\Request as Req;

class Dashboard extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\DefaultFlgTrait;
    use Traits\UseRequestSessionTrait;
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
    
    /**
     * import template
     */
    public static function importTemplate($dashboard, $options = []){
        // Create dashboard --------------------------------------------------
        $obj_dashboard = Dashboard::firstOrNew([
            'dashboard_name' => array_get($dashboard, "dashboard_name")
        ]);

        $dashboard_type = DashboardType::getEnumValue(array_get($dashboard, 'dashboard_type'), DashboardType::SYSTEM());
        $obj_dashboard->dashboard_type = $dashboard_type;
        $obj_dashboard->dashboard_view_name = array_get($dashboard, 'dashboard_view_name');
        $obj_dashboard->setOption('row1', array_get($dashboard, 'options.row1'), 1);
        $obj_dashboard->setOption('row2', array_get($dashboard, 'options.row2'), 2);
        $obj_dashboard->setOption('row3', array_get($dashboard, 'options.row3'), 0);
        $obj_dashboard->setOption('row4', array_get($dashboard, 'options.row4'), 0);
        $obj_dashboard->default_flg = boolval(array_get($dashboard, 'default_flg'));
        // if set suuid in json, set suuid(for dashbrord list)
        if (array_key_value_exists('suuid', $dashboard)) {
            $obj_dashboard->suuid = array_get($dashboard, 'dashboard_suuid');
        }
        $obj_dashboard->saveOrFail();
        
        // create dashboard boxes --------------------------------------------------
        if (array_key_exists('dashboard_boxes', $dashboard)) {
            foreach (array_get($dashboard, "dashboard_boxes") as $dashboard_box) {
                DashboardBox::importTemplate($dashboard_box, [
                    'obj_dashboard' => $obj_dashboard,
                ]);
            }
        }
        return $obj_dashboard;
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
