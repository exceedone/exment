<?php

namespace Exceedone\Exment\Model;

use Encore\Admin\Facades\Admin;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @phpstan-consistent-constructor
 * @property mixed $suuid
 * @property mixed $default_flg
 * @property mixed $dashboard_type
 * @property mixed $dashboard_name
 * @property mixed $dashboard_view_name
 * @property mixed $created_user_id
 * @property mixed $options
 * @method static int count($columns = '*')
 * @method static \Illuminate\Database\Query\Builder orderBy($column, $direction = 'asc')
 */
class Dashboard extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonOptionTrait;
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

    public function dashboard_boxes(): HasMany
    {
        return $this->hasMany(DashboardBox::class, 'dashboard_id')
        ->orderBy('row_no')
        ->orderBy('column_no');
    }

    /**
     * Get dashboard items selecting row
     *
     * @param int $row_no
     * @return \Illuminate\Support\Collection
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

    public function data_share_authoritables(): HasMany
    {
        return $this->hasMany(DataShareAuthoritable::class, 'parent_id')
            ->where('parent_type', '_dashboard');
    }

    /**
     * get default dashboard
     */
    public static function getDefault()
    {
        $user = Admin::user();
        // get request
        $request = request();

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
            $dashboard = new Dashboard();
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
            static::showableDashboards($builder);
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
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    protected static function showableDashboards($query)
    {
        $query->where('dashboard_type', DashboardType::SYSTEM);

        $user = \Exment::user();
        if (!isset($user)) {
            return;
        }

        if (!hasTable(getDBTableName(SystemTableName::USER, false)) || !hasTable(getDBTableName(SystemTableName::ORGANIZATION, false))) {
            return;
        }

        $query->orWhere(function ($query) use ($user) {
            $query->where('dashboard_type', DashboardType::USER);

            // filtered created_user, and shared others.
            $query->where(function ($query) use ($user) {
                $query->where('created_user_id', $user->getUserId())
                    ->orWhereHas('data_share_authoritables', function ($query) use ($user) {
                        $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
                        $query->whereInMultiple(
                            ['authoritable_user_org_type', 'authoritable_target_id'],
                            $user->getUserAndOrganizationIds($enum),
                            true
                        );
                    });
            });
        });
    }

    /**
     * Check this login user has edit permission this dashboard
     *
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
        $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
        $hasEdit = $this->data_share_authoritables()
            ->where('authoritable_type', 'data_share_edit')
            ->whereInMultiple(['authoritable_user_org_type', 'authoritable_target_id'], $login_user->getUserAndOrganizationIds($enum), true)
            ->exists();

        return $hasEdit;
    }

    public static function hasSystemPermission()
    {
        return \Admin::user()->hasPermission(Permission::SYSTEM);
    }

    public static function hasPermission()
    {
        return System::userdashboard_available() || static::hasSystemPermission();
    }

    public function deletingChildren()
    {
        $this->dashboard_boxes()->delete();
        // delete data_share_authoritables
        DataShareAuthoritable::deleteDataAuthoritable($this);
    }
}
