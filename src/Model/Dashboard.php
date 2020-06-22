<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Http\Request as Req;
use Illuminate\Database\Eloquent\Builder;

class Dashboard extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\DefaultFlgTrait;
    use Traits\TemplateTrait;
    use Traits\UseRequestSessionTrait;
    
    protected $guarded = ['id'];
    protected $casts = ['options' => 'json'];

    public static $templateItems = [
        'excepts' => ['suuid'],
        'uniqueKeys' => ['dashboard_name'],
        'langs' => [
            'keys' => ['dashboard_name'],
            'values' => ['dashboard_view_name'],
        ],
        'enums' => [
            'dashboard_type' => DashboardType::class,
        ],
        'defaults' => [
            'options.row1' => 1,
            'options.row2' => 2,
            'options.row3' => 0,
            'options.row4' => 0,
        ],
        'children' =>[
            'dashboard_boxes' => DashboardBox::class,
        ],
    ];

    public function dashboard_boxes()
    {
        return $this->hasMany(DashboardBox::class, 'dashboard_id')
        ->orderBy('row_no')
        ->orderBy('column_no');
    }
    
    /**
     * Get dashboard items selecting row
     *
     * @param [type] $row_no
     * @return void
     */
    public function dashboard_row_boxes($row_no)
    {
        return DashboardBox::allRecords(function ($record) use ($row_no) {
            if ($record->dashboard_id != $this->id) {
                return false;
            }
            if ($record->row_no != $row_no) {
                return false;
            }
            return true;
        }, false)->sortBy('column_no');
    }

    // user data_authoritable. it's all role data. only filter morph_type
    public function data_authoritable_users()
    {
        return $this->morphToMany(getModelName(SystemTableName::USER), 'parent', 'data_share_authoritables', 'parent_id', 'authoritable_target_id')
            ->withPivot('authoritable_target_id', 'authoritable_user_org_type', 'authoritable_type')
            ->wherePivot('authoritable_user_org_type', SystemTableName::USER)
            ;
    }

    // user data_authoritable. it's all role data. only filter morph_type
    public function data_authoritable_organizations()
    {
        return $this->morphToMany(getModelName(SystemTableName::ORGANIZATION), 'parent', 'data_share_authoritables', 'parent_id', 'authoritable_target_id')
            ->withPivot('authoritable_target_id', 'authoritable_user_org_type', 'authoritable_type')
            ->wherePivot('authoritable_user_org_type', SystemTableName::ORGANIZATION)
            ;
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
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        return static::getEloquentDefault($id, $withs);
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
            $model->setDefaultFlg(null, 'setDefaultFlgFilter', 'setDefaultFlgSet');
        });
        static::updating(function ($model) {
            $model->setDefaultFlg(null, 'setDefaultFlgFilter', 'setDefaultFlgSet');
        });
        
        static::created(function ($model) {
            if ($model->dashboard_type == DashboardType::USER) {
                // save Authoritable
                DataShareAuthoritable::setDataAuthoritable($model);
            }
        });

        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
        });
        
        // add global scope
        static::addGlobalScope('showableDashboards', function (Builder $builder) {
            return static::showableDashboards($builder);
        });
    }

    protected function setDefaultFlgFilter($query)
    {
        $query->where('dashboard_type', $this->dashboard_type);

        if ($this->dashboard_type == DashboardType::USER) {
            $login_user = \Exment::user();
            $query->where('created_user_id', isset($login_user) ? $login_user->getUserId() : null);
        }
    }

    protected function setDefaultFlgSet()
    {
        // set if only this flg is system
        if ($this->dashboard_type == DashboardType::SYSTEM) {
            $this->default_flg = true;
        }
    }

    /**
     * scope user showable Dashboards
     *
     * @param [type] $query
     * @return void
     */
    protected static function showableDashboards($query)
    {
        $query = $query->where(function ($qry) {
            $qry->where('dashboard_type', DashboardType::SYSTEM);
        });

        $user = \Exment::user();
        if (!isset($user)) {
            return;
        }

        if (hasTable(getDBTableName(SystemTableName::USER))) {
            $query->orWhere(function ($qry) use ($user) {
                $qry->where('dashboard_type', DashboardType::USER)
                    ->where('created_user_id', $user->getUserId());
            })->orWhereHas('data_authoritable_users', function ($qry) use ($user) {
                $qry->where('authoritable_target_id', $user->getUserId());
            });
        }
        if (hasTable(getDBTableName(SystemTableName::ORGANIZATION))) {
            $query->orWhereHas('data_authoritable_organizations', function ($qry) use ($user) {
                $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
                $qry->whereIn('authoritable_target_id', $user->getOrganizationIds($enum));
            });
        }
    }

    /**
     * Check this login user has edit permission this dashboard
     *
     * @param [type] $id
     * @return boolean
     */
    public function hasEditPermission()
    {
        $login_user = \Exment::user();
        if ($this->dashboard_type == DashboardType::SYSTEM) {
            return static::hasSystemPermission();
        } elseif ($this->created_user_id == $login_user->getUserId()) {
            return true;
        };

        // check if editable user exists
        $hasEdit = $this->data_authoritable_users()
            ->where('authoritable_type', 'data_share_edit')
            ->where('authoritable_target_id', $login_user->getUserId())
            ->exists();

        if (!$hasEdit && System::organization_available()) {
            $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
            // check if editable organization exists
            $hasEdit = $this->data_authoritable_organizations()
                ->where('authoritable_type', 'data_share_edit')
                ->whereIn('authoritable_target_id', $login_user->getOrganizationIds($enum))
                ->exists();
        }

        return $hasEdit;
    }
    
    public static function hasSystemPermission()
    {
        return \Admin::user()->hasPermission(Permission::SYSTEM);
    }
    
    public static function hasPermission()
    {
        return !boolval(config('exment.userdashboard_disabled', false)) || static::hasSystemPermission();
    }

    public function deletingChildren()
    {
        $this->dashboard_boxes()->delete();
        // delete data_share_authoritables
        DataShareAuthoritable::deleteDataAuthoritable($this);
    }
}
