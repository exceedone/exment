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


    // @phpstan-ignore-next-line
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


    // @phpstan-ignore-next-line
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

    // @phpstan-ignore-next-line
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

    // Form virtual attributes ----------------------------------------------------
    // The dashboard setting form binds these instead of the raw `options` attribute:
    // each mutator MERGES into options via setOption, so options keys no form field
    // manages (parent_dashboard_suuid, ...) survive a form save. Binding embeds
    // directly to `options` would replace the whole JSON and wipe those keys.

    // @phpstan-ignore-next-line
    public function getRowSettingAttribute()
    {
        return $this->options;
    }

    // @phpstan-ignore-next-line
    public function setRowSettingAttribute(?array $options)
    {
        $this->setOption($options);
        return $this;
    }

    /**
     * Dashboard filter bar (options.filter_bar) — form virtual attributes, so an admin can
     * add / edit / delete filter items from the dashboard setting screen (schema:
     * Services\Dashboard\FilterBarConfig; runtime: FilterBarContextBuilder / FilterState).
     *
     * Same merge discipline as row_setting: each mutator rewrites ONE key inside
     * options.filter_bar, so other options keys (parent_dashboard_suuid, ai_summary, ...) and
     * dim keys this form does not expose (`disables`, ...) survive a save.
     *
     * Contract for dim keys the current form does NOT render (parent / style / note /
     * from_master / advanced): a row that CARRIES the key (import, seed, an older or
     * extended form) may set or clear it; a row without it keeps whatever the stored dim had,
     * so a form save can never silently drop a stored value.
     */

    // @phpstan-ignore-next-line
    public function getFilterBarTableAttribute()
    {
        return $this->getOption('filter_bar.source_table');
    }

    // @phpstan-ignore-next-line
    public function setFilterBarTableAttribute($value)
    {
        return $this->mergeFilterBarOption('source_table', is_nullorempty($value) ? null : strval($value));
    }


    /**
     * dims <-> form rows. Only the keys the form exposes are read/written; a stored dim keeps
     * every other key it already had (matched by column name).
     *
     * @return array<int, array<string, mixed>>
     */
    // @phpstan-ignore-next-line
    public function getFilterBarDimsAttribute()
    {
        $dims = $this->getOption('filter_bar.dims');
        if (!is_array($dims)) {
            return [];
        }

        return collect($dims)->map(function ($dim) {
            return [
                'column'      => array_get($dim, 'column'),
                'label'       => array_get($dim, 'label'),
                'parent'      => array_get($dim, 'parent'),
                // control style: 'select' | 'range'; '' = auto by column type (key absent)
                'style'       => in_array(array_get($dim, 'style'), ['select', 'range'], true) ? array_get($dim, 'style') : '',
                'from_master' => boolval(array_get($dim, 'from_master')) ? 1 : 0,
                'advanced'    => boolval(array_get($dim, 'advanced')) ? 1 : 0,
                'note'        => array_get($dim, 'note'),
                // slicer targeting: box suuids this dim narrows; [] = every box (legacy)
                'targets'     => array_values(array_filter((array) array_get($dim, 'targets', []), 'is_string')),
            ];
        })->values()->toArray();
    }

    // @phpstan-ignore-next-line
    public function setFilterBarDimsAttribute($value)
    {
        if (!is_array($value)) {
            $value = [];
        }

        // stored dims keyed by column, so keys this form does not manage are carried over
        $stored = collect((array) $this->getOption('filter_bar.dims'))
            ->filter(function ($dim) {
                return !is_nullorempty(array_get($dim, 'column'));
            })
            ->keyBy(function ($dim) {
                return array_get($dim, 'column');
            });

        $dims = [];
        foreach ($value as $row) {
            $column = trim(strval(array_get($row, 'column', '')));
            if ($column === '') {
                continue; // an empty row is a row the admin left blank — not a filter item
            }

            $dim = (array) $stored->get($column, []);
            $dim['column'] = $column;

            $label = trim(strval(array_get($row, 'label', '')));
            $dim['label'] = ($label !== '') ? $label : $column;

            // parent: a column = forced parent, '-' = forced independent, absent = inferred
            // from metadata (FilterBarConfig::parentOf)
            if (array_key_exists('parent', $row)) {
                $this->setOrForget($dim, 'parent', trim(strval(array_get($row, 'parent', ''))));
            }

            // control style: 'select' | 'range' stored, anything else (auto by column type,
            // FilterState::style) = key absent so an untouched config stays byte-identical
            if (array_key_exists('style', $row)) {
                $style = trim(strval(array_get($row, 'style', '')));
                $this->setOrForget($dim, 'style', in_array($style, ['select', 'range'], true) ? $style : '');
            }

            // caution note rendered under the dim while it is selected
            if (array_key_exists('note', $row)) {
                $this->setOrForget($dim, 'note', trim(strval(array_get($row, 'note', ''))));
            }

            // from_master: list the master table's records instead of the values in the data
            if (array_key_exists('from_master', $row)) {
                if (boolval(array_get($row, 'from_master'))) {
                    $dim['from_master'] = true;
                } else {
                    unset($dim['from_master']); // default (choices from the data) = key absent
                }
            }

            // advanced: render the dim inside the collapsible Detailed Filter area
            if (array_key_exists('advanced', $row)) {
                if (boolval(array_get($row, 'advanced'))) {
                    $dim['advanced'] = true;
                } else {
                    unset($dim['advanced']); // explicit OFF = key absent, not `false`
                }
            }

            // slicer targeting: keep only non-empty string suuids; an empty selection means
            // "narrow every box" and is stored as key-absent (the legacy shape), so existing
            // configs and new untargeted dims stay byte-identical.
            //
            // A multi-select with nothing picked posts NO key at all — indistinguishable from
            // a submitter that never had the field (a stale form tab, an older code path). The
            // row therefore carries a hidden sentinel `targets_submitted` (set by the current
            // form): only when the row demonstrably knew about targets is an absent/empty
            // value an intentional CLEAR; otherwise the stored targets are carried over.
            $knowsTargets = array_key_exists('targets', $row)
                || !is_nullorempty(array_get($row, 'targets_submitted'));
            if ($knowsTargets) {
                $targets = array_values(array_filter((array) array_get($row, 'targets', []), function ($v) {
                    return is_string($v) && $v !== '';
                }));
                if (count($targets)) {
                    $dim['targets'] = $targets;
                } else {
                    unset($dim['targets']);
                }
            }

            $dims[] = $dim;
        }

        return $this->mergeFilterBarOption('dims', count($dims) ? $dims : null);
    }

    /**
     * Write one key inside options.filter_bar (null = remove the key). setOption/setJson has no
     * dot-path setter, so the sub-array is rebuilt here.
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    protected function mergeFilterBarOption($key, $value)
    {
        $bar = $this->getOption('filter_bar');
        if (!is_array($bar)) {
            $bar = [];
        }

        if (is_null($value)) {
            unset($bar[$key]);
        } else {
            $bar[$key] = $value;
        }

        return $this->setOption('filter_bar', $bar);
    }

    /**
     * @param array<string, mixed> $dim
     * @param string $key
     * @param string $value
     * @return void
     */
    protected function setOrForget(&$dim, $key, $value)
    {
        if ($value === '') {
            unset($dim[$key]);
        } else {
            $dim[$key] = $value;
        }
    }

    /**
     * Drop a half-configured filter bar before it reaches the DB, so the form cannot leave
     * options.filter_bar in a state buildDashboardFilterContext would silently ignore
     * (source table cleared but dims kept, or vice versa), and so a `parent` pointing at a
     * deleted dim does not break the cascade.
     *
     * @return void
     */
    protected function normalizeFilterBarOption()
    {
        $bar = $this->getOption('filter_bar');
        if (!is_array($bar)) {
            return;
        }

        $dims = array_get($bar, 'dims');
        if (is_nullorempty(array_get($bar, 'source_table')) || !is_array($dims) || !count($dims)) {
            $this->forgetOption('filter_bar');
            return;
        }

        // Two items on the same column can never both work — the bar drives one df_{column}
        // query param, so the second select would silently move the first one's value. Keep the
        // first occurrence only.
        $byColumn = [];
        foreach ($dims as $dim) {
            $column = array_get($dim, 'column');
            if (!is_nullorempty($column) && !array_key_exists($column, $byColumn)) {
                $byColumn[$column] = $dim;
            }
        }
        $dims = array_values($byColumn);

        $none = \Exceedone\Exment\Services\Dashboard\FilterBarConfig::PARENT_NONE;
        $columns = array_filter(array_column($dims, 'column'));
        foreach ($dims as &$dim) {
            $parent = array_get($dim, 'parent');
            if (is_nullorempty($parent) || $parent === $none) {
                continue; // absent = AUTO (metadata inference), '-' = forced none — both valid as-is
            }
            // a parent that is not itself a configured dim (deleted row), or a self-reference,
            // would make the cascade unreachable — drop the link, keep the item.
            if (!in_array($parent, $columns, true) || $parent === array_get($dim, 'column')) {
                unset($dim['parent']);
            }
        }
        unset($dim);

        // A parent chain that loops back on itself (A under B, B under A) has no root: the
        // cascade can never resolve it and the bar's navigation JS would recurse forever. Cut
        // every link that closes a loop — the items involved stay, as independent filters.
        $parentOf = [];
        foreach ($dims as $dim) {
            $p = array_get($dim, 'parent');
            $parentOf[array_get($dim, 'column')] = ($p === $none) ? null : $p;
        }
        foreach ($dims as &$dim) {
            $seen = [array_get($dim, 'column') => true];
            $parent = $parentOf[array_get($dim, 'column')] ?? null;
            while (!is_nullorempty($parent)) {
                if (isset($seen[$parent])) {
                    unset($dim['parent']);
                    break;
                }
                $seen[$parent] = true;
                $parent = $parentOf[$parent] ?? null;
            }
        }
        unset($dim);

        $bar['dims'] = array_values($dims);
        $this->setOption('filter_bar', $bar);
    }

    /**
     * Per-dashboard "AI summary" switch (options.ai_summary) — OPT-IN, default OFF:
     * only when an admin turns it ON do this dashboard's charts show the AI summary
     * strip and may their data reach the AI provider for it.
     * OFF (the default) = feature absent, so confidential dashboards are safe unless
     * someone deliberately enables it. Enforced server-side via
     * AiChatService::summaryEnabledForBox, not just hidden in the UI.
     */
    public function getAiSummaryAttribute()
    {
        return boolval($this->getOption('ai_summary'));
    }

    // @phpstan-ignore-next-line
    public function setAiSummaryAttribute($value)
    {
        // merge via setOption (never rebind the whole options JSON — see note above);
        // store only when ON so the default-off state keeps a clean options payload.
        if (boolval($value)) {
            $this->setOption('ai_summary', true);
        } else {
            $this->forgetOption('ai_summary');
        }
        return $this;
    }


    // @phpstan-ignore-next-line
    public function data_share_authoritables(): HasMany
    {
        return $this->hasMany(DataShareAuthoritable::class, 'parent_id')
            ->where('parent_type', '_dashboard');
    }

    /**
     * get default dashboard
     */

    // @phpstan-ignore-next-line
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

    // @phpstan-ignore-next-line
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

        // keep options.filter_bar consistent whatever wrote it (setting form, seed, template)
        static::saving(function ($model) {
            $model->normalizeFilterBarOption();
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


    // @phpstan-ignore-next-line
    protected function setDefaultFlgFilter($query)
    {
        $query->where('dashboard_type', $this->dashboard_type);

        if ($this->dashboard_type == DashboardType::USER) {
            $login_user = \Exment::user();
            $query->where('created_user_id', isset($login_user) ? $login_user->getUserId() : null);
        }
    }


    // @phpstan-ignore-next-line
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

    // @phpstan-ignore-next-line
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


    // @phpstan-ignore-next-line
    public static function hasSystemPermission()
    {

        return \Admin::user()->hasPermission(Permission::SYSTEM);
    }


    // @phpstan-ignore-next-line
    public static function hasPermission()
    {
        return System::userdashboard_available() || static::hasSystemPermission();
    }


    // @phpstan-ignore-next-line
    public function deletingChildren()
    {
        $this->dashboard_boxes()->delete();
        // delete data_share_authoritables
        DataShareAuthoritable::deleteDataAuthoritable($this);
    }
}
