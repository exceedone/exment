<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Illuminate\Database\Eloquent\Builder;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Grid\Linker;
use Exceedone\Exment\Services\Search\SearchService;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\UserSetting;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\SearchType;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @phpstan-consistent-constructor
 * @property mixed $suuid
 * @property mixed $view_type
 * @property mixed $view_kind_type
 * @property mixed $view_view_name
 * @property mixed $default_flg
 * @property mixed $custom_table_id
 * @property mixed $created_user_id
 * @method static int count($columns = '*')
 * @method static ExtendedBuilder orderBy($column, $direction = 'asc')
 * @method static ExtendedBuilder create(array $attributes = [])
 */
class CustomView extends ModelBase implements Interfaces\TemplateImporterInterface
{
    use Traits\UseRequestSessionTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DefaultFlgTrait;
    use Traits\TemplateTrait;
    use Traits\DatabaseJsonOptionTrait;

    //protected $appends = ['view_calendar_target', 'pager_count'];
    //protected $appends = ['pager_count', 'condition_join'];
    protected $guarded = ['id', 'suuid'];
    protected $casts = ['options' => 'json', 'custom_options' => 'json'];
    //protected $with = ['custom_table', 'custom_view_columns'];

    private $_grid_item;

    /**
     * Custom Value search service.
     *
     * @var SearchService|null
     */
    private $_search_service;

    public static $templateItems = [
        'excepts' => ['custom_table', 'target_view_name', 'view_calendar_target', 'pager_count'],
        'uniqueKeys' => ['suuid'],
        'langs' => [
            'keys' => ['suuid'],
            'values' => ['view_view_name'],
        ],
        'uniqueKeyReplaces' => [
            [
                'replaceNames' => [
                    [
                        'replacingName' => 'custom_table_id',
                        'replacedName' => [
                            'table_name' => 'table_name',
                        ]
                    ]
                ],
                'uniqueKeyClassName' => CustomTable::class,
            ],
        ],
        'defaults' => [
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
        ],
        'enums' => [
            'view_type' => ViewType::class,
            'view_kind_type' => ViewKindType::class,
        ],
        'children' =>[
            'custom_view_columns' => CustomViewColumn::class,
            'custom_view_filters' => CustomViewFilter::class,
            'custom_view_sorts' => CustomViewSort::class,
            'custom_view_summaries' => CustomViewSummary::class,
        ],
    ];


    //public function custom_table()
    public function getCustomTableAttribute()
    {
        return CustomTable::getEloquent($this->custom_table_id);
        //return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function custom_view_columns(): HasMany
    {
        return $this->hasMany(CustomViewColumn::class, 'custom_view_id');
    }

    public function custom_view_filters(): HasMany
    {
        return $this->hasMany(CustomViewFilter::class, 'custom_view_id');
    }

    public function custom_view_sorts(): HasMany
    {
        return $this->hasMany(CustomViewSort::class, 'custom_view_id');
    }

    public function custom_view_summaries(): HasMany
    {
        return $this->hasMany(CustomViewSummary::class, 'custom_view_id');
    }

    public function custom_view_grid_filters(): HasMany
    {
        return $this->hasMany(CustomViewGridFilter::class, 'custom_view_id');
    }

    public function data_share_authoritables(): HasMany
    {
        return $this->hasMany(DataShareAuthoritable::class, 'parent_id')
            ->where('parent_type', '_custom_view');
    }

    /**
     * get Custom columns using cache
     */
    public function getCustomViewColumnsCacheAttribute()
    {
        return $this->hasManyCache(CustomViewColumn::class, 'custom_view_id');
    }

    /**
     * get Custom filters using cache
     */
    public function getCustomViewFiltersCacheAttribute()
    {
        return $this->hasManyCache(CustomViewFilter::class, 'custom_view_id');
    }

    /**
     * get Custom Sorts using cache
     */
    public function getCustomViewSortsCacheAttribute()
    {
        return $this->hasManyCache(CustomViewSort::class, 'custom_view_id');
    }

    /**
     * get Custom summaries using cache
     */
    public function getCustomViewSummariesCacheAttribute()
    {
        return $this->hasManyCache(CustomViewSummary::class, 'custom_view_id');
    }

    public function getTableNameAttribute()
    {
        return $this->custom_table->table_name;
    }

    public function getFilterIsOrAttribute()
    {
        return $this->condition_join == 'or';
    }

    public function getGridItemAttribute()
    {
        if (isset($this->_grid_item)) {
            return $this->_grid_item;
        }

        $classname = ViewKindType::getGridItemClassName($this->view_kind_type);
        $this->_grid_item = $classname::getItem($this->custom_table, $this);

        return $this->_grid_item;
    }

    public function getCustomOption($key, $default = null)
    {
        return $this->getJson('custom_options', $key, $default);
    }
    public function setCustomOption($key, $val = null)
    {
        return $this->setJson('custom_options', $key, $val);
    }

    public function deletingChildren()
    {
        $this->custom_view_columns()->delete();
        $this->custom_view_filters()->delete();
        $this->custom_view_sorts()->delete();
        $this->custom_view_summaries()->delete();
        $this->custom_view_grid_filters()->delete();
        // delete data_share_authoritables
        DataShareAuthoritable::deleteDataAuthoritable($this);
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->prepareJson('options');
        });

        static::creating(function ($model) {
            $model->setDefaultFlgInTable('setDefaultFlgFilter', 'setDefaultFlgSet');
        });
        static::updating(function ($model) {
            $model->setDefaultFlgInTable('setDefaultFlgFilter', 'setDefaultFlgSet');
        });

        // delete event
        static::deleting(function ($model) {
            // Delete items
            $model->deletingChildren();
        });

        static::created(function ($model) {
            if ($model->view_type == ViewType::USER) {
                // save Authoritable
                DataShareAuthoritable::setDataAuthoritable($model);
            }
        });

        // add global scope
        static::addGlobalScope('showableViews', function (Builder $builder) {
            return static::showableViews($builder);
        });
    }

    protected function setDefaultFlgFilter($query)
    {
        $query->where('view_type', $this->view_type);

        if ($this->view_type == ViewType::USER) {
            $query->where('created_user_id', \Exment::getUserId());
        }
    }

    protected function setDefaultFlgSet()
    {
        // set if only this flg is system
        if ($this->view_type == ViewType::SYSTEM) {
            $this->default_flg = true;
        }
    }

    // custom function --------------------------------------------------

    /**
     * get search service.
     */
    public function getSearchService(): SearchService
    {
        if (!$this->_search_service) {
            $this->_search_service = new SearchService($this->custom_table);
        }
        return $this->_search_service;
    }

    /**
     * reset search service.
     */
    public function resetSearchService()
    {
        $this->_search_service = null;
    }


    /**
     * get eloquent using request settion.
     * now only support only id.
     */
    public static function getEloquent($id, $withs = [])
    {
        if (strlen_ex($id) == 20) {
            return static::getEloquentDefault($id, $withs, 'suuid');
        }

        return static::getEloquentDefault($id, $withs, 'id');
    }


    /**
     * Get database query
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @param array $options
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public function getQuery($query, array $options = [])
    {
        return $this->grid_item->getQuery($query, $options);
    }


    /**
     * set laravel-admin grid using custom_view
     */
    public function setGrid($grid)
    {
        return $this->grid_item->setGrid($grid);
    }


    /**
     * Get data paginate. default or summary
     */
    public function getDataPaginate($options = [])
    {
        $options = array_merge([
            'paginate' => true,
            'maxCount' => System::datalist_pager_count() ?? 5,
            'target_view' => $this,
            'query' => null,
            'grid' => null,
            'isApi' => false,
        ], $options);

        if ($this->view_kind_type == ViewKindType::AGGREGATE) {
            $query = $options['query'] ?? $this->custom_table->getValueQuery();
            return $this->getQuery($query, $options)->paginate($options['maxCount']);
        }

        // search all data using index --------------------------------------------------
        $paginate = $this->custom_table->searchValue(null, $options);
        return $paginate;
    }


    /**
     * set DataTable using custom_view
     * @return array $headers : header items, $bodies : body items.
     */
    public function convertDataTable($datalist, $options = [])
    {
        $options = array_merge(
            [
                'action_callback' => null,
                'appendLink' => true,
                'valueType' => ValueType::HTML,
            ],
            $options
        );
        $custom_table = $this->custom_table;
        // get custom view columns and custom view summaries
        $view_column_items = $this->getSummaryIndexAndViewColumns();

        // create headers and column_styles
        $headers = [];
        $columnStyles = [];
        $columnClasses = [];
        $columnItems = [];

        foreach ($view_column_items as $view_column_item) {
            $item = array_get($view_column_item, 'item');
            $headers[] = $item
                ->column_item
                ->label(array_get($item, 'view_column_name'))
                ->label();

            $columnStyles[] = $item->column_item->gridStyle();
            $columnClasses[] = 'column-' . esc_html($item->column_item->name()) . ($item->column_item->indexEnabled() ? ' column-' . $item->column_item->index() : '');
            $columnItems[] = $item->column_item;
        }
        if (boolval($options['appendLink']) && $this->view_kind_type != ViewKindType::AGGREGATE) {
            $headers[] = trans('admin.action');
        }

        // get table bodies
        $bodies = [];
        if (isset($datalist)) {
            foreach ($datalist as $data) {
                $body_items = [];
                foreach ($view_column_items as $view_column_item) {
                    $column = array_get($view_column_item, 'item');
                    $item = $column->column_item;
                    if ($this->view_kind_type == ViewKindType::AGGREGATE) {
                        $index = array_get($view_column_item, 'index');
                        $summary_condition = array_get($column, 'view_summary_condition');
                        $item->options([
                            'summary' => true,
                            'summary_index' => $index,
                            'summary_condition' => $summary_condition,
                            'group_condition' => array_get($column, 'view_group_condition'),
                            'disable_currency_symbol' => ($summary_condition == SummaryCondition::COUNT),
                        ]);
                    }

                    $item->options([
                        'view_pivot_column' => $column->view_pivot_column_id ?? null,
                        'view_pivot_table' => $column->view_pivot_table_id ?? null,
                        'grid_column' => true,
                    ]);

                    $valueType = ValueType::getEnum($options['valueType']);
                    $body_items[] = $valueType->getCustomValue($item, $data);
                }

                $link = '';
                if (isset($options['action_callback'])) {
                    $options['action_callback']($link, $custom_table, $data);
                }

                ///// add show and edit link
                if (boolval($options['appendLink']) && $this->view_kind_type != ViewKindType::AGGREGATE) {
                    // using role
                    $link .= (new Linker())
                        ->url(admin_urls('data', array_get($custom_table, 'table_name'), array_get($data, 'id')))
                        //->linkattributes(['style' => "margin:0 3px;"])
                        ->icon('fa-eye')
                        ->tooltip(trans('admin.show'))
                        ->render();
                    if ($data->enableEdit(true) === true) {
                        $link .= (new Linker())
                            ->url(admin_urls('data', array_get($custom_table, 'table_name'), array_get($data, 'id'), 'edit'))
                            ->icon('fa-edit')
                            ->tooltip(trans('admin.edit'))
                            ->render();
                    }
                    // add hidden item about data id
                    $link .= '<input type="hidden" data-id="'.array_get($data, 'id').'" />';
                    $body_items[] = $link;
                }

                // add items to body
                $bodies[] = $body_items;
            }
        }

        //return headers, bodies
        return [$headers, $bodies, $columnStyles, $columnClasses, $columnItems];
    }

    /**
     * get alldata view using table
     *
     * @param mixed $tableObj table_name, object or id eic
     * @return CustomView
     */
    public static function getAllData($tableObj)
    {
        $tableObj = CustomTable::getEloquent($tableObj);

        // get all data view
        $view = $tableObj->custom_views()->where('view_kind_type', ViewKindType::ALLDATA)->first();

        // if all data view is not exists, create view
        if (!isset($view)) {
            $view = static::createDefaultView($tableObj);
        }

        // if target form doesn't have columns, add columns for has_index_columns columns.
        if (is_null($view->custom_view_columns_cache) || count($view->custom_view_columns_cache) == 0) {
            // copy default view
            $fromview = $tableObj->custom_views()
                ->where('view_kind_type', ViewKindType::DEFAULT)
                ->where('default_flg', true)
                ->first();

            // get view id for after
            if (isset($fromview)) {
                $view->copyFromDefaultViewColumns($fromview);
            }
            // not fromview, create index columns
            else {
                $view->createDefaultViewColumns(true);
            }

            // re-get view (reload view_columns)
            $view = static::find($view->id);
        }

        return $view;
    }

    /**
     * get default view using table
     *
     * @param mixed $tableObj table_name, object or id eic
     * @param boolean $getSettingValue if true, getting from UserSetting table
     * @param boolean $is_dashboard call by dashboard
     * @return CustomView|null
     */
    public static function getDefault($tableObj, $getSettingValue = true, $is_dashboard = false)
    {
        $user = Admin::user();
        $tableObj = CustomTable::getEloquent($tableObj);

        // get request
        $request = request();

        // get view using query
        if (!is_null($request->input('view'))) {
            $suuid = $request->input('view');
            // if query has view id, set view.
            $view = static::findBySuuid($suuid);

            // not match table id, reset view
            if (isset($view) && !isMatchString($view->custom_table_id, $tableObj->id)) {
                $view = null;
            }

            // set user_setting
            if (isset($view) && !is_null($user) && !$is_dashboard) {
                $user->setSettingValue(implode(".", [UserSetting::VIEW, $tableObj->table_name]), $suuid);
            }
        }
        // if url doesn't contain view query, get view user setting.
        if (!isset($view) && !is_null($user) && $getSettingValue) {
            // get suuid
            $suuid = $user->getSettingValue(implode(".", [UserSetting::VIEW, $tableObj->table_name]));
            $view = CustomView::findBySuuid($suuid);

            // not match table id, reset view
            if (isset($view) && !isMatchString($view->custom_table_id, $tableObj->id)) {
                $view = null;
            }
        }
        // if url doesn't contain view query, get custom view. first
        if (!isset($view)) {
            $view = static::allRecordsCache(function ($record) use ($tableObj) {
                return array_get($record, 'custom_table_id') == $tableObj->id
                    && array_get($record, 'default_flg') == true
                    && array_get($record, 'view_type') == ViewType::SYSTEM
                    && array_get($record, 'view_kind_type') != ViewKindType::FILTER;
            })->first();
        }

        // if default view is not setting, show all data view
        if (!isset($view)) {
            // get all data view
            $alldata = static::allRecordsCache(function ($record) use ($tableObj) {
                return array_get($record, 'custom_table_id') == $tableObj->id
                    && array_get($record, 'view_kind_type') == ViewKindType::ALLDATA;
            })->first();
            // if all data view is not exists, create view and column
            if (!isset($alldata)) {
                $alldata = static::createDefaultView($tableObj);
                $alldata->createDefaultViewColumns();
            }
            $view = $alldata;
        }

        // if target form doesn't have columns, add columns for has_index_columns columns.
        if (is_null($view->custom_view_columns_cache) || count($view->custom_view_columns_cache) == 0) {
            // get view id for after
            $view->createDefaultViewColumns();

            // re-get view (reload view_columns)
            $view = static::find($view->id);
        }

        return $view;
    }

    protected static function showableViews($query)
    {
        $query->where('view_type', ViewType::SYSTEM);

        $user = \Exment::user();
        if (!isset($user)) {
            return;
        }

        if (!hasTable(getDBTableName(SystemTableName::USER, false)) || !hasTable(getDBTableName(SystemTableName::ORGANIZATION, false))) {
            return;
        }

        $query->orWhere(function ($query) use ($user) {
            $query->where('view_type', ViewType::USER);

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

    public static function createDefaultView($tableObj)
    {
        $tableObj = CustomTable::getEloquent($tableObj);

        $view = new CustomView();
        $view->custom_table_id = $tableObj->id;
        $view->view_type = ViewType::SYSTEM;
        $view->view_kind_type = ViewKindType::ALLDATA;
        $view->view_view_name = exmtrans('custom_view.alldata_view_name');
        $view->saveOrFail();

        return $view;
    }

    /**
     * filter target model
     */
    public function filterModel($model, $options = [])
    {
        $options = array_merge([
            'sort' => false, // v4.0.0, default is false
            'callback' => null,
        ], $options);

        // if simple eloquent, throw
        if ($model instanceof \Illuminate\Database\Eloquent\Model) {
            throw new \Exception();
        }

        // view filter setting --------------------------------------------------
        // has $custom_view, filter
        if ($options['callback'] instanceof \Closure) {
            call_user_func($options['callback'], $model);
        } else {
            $this->setValueFilters($model);
        }

        if (boolval($options['sort'])) {
            $this->setValueSort($model);
        }

        // Append query
        $this->custom_table->appendSubQuery($model, $this);

        ///// We don't need filter using role here because filter auto using global scope.

        return $model;
    }


    /**
     * filter and sort target model
     */
    public function filterSortModel($query, $options = [])
    {
        $options = array_merge([
            'sort' => true,
        ], $options);
        return $this->filterModel($query, $options);
    }


    /**
     * Create default columns
     *
     * @param boolean $appendIndexColumn if true, append custom column has index
     */
    public function createDefaultViewColumns($appendIndexColumn = false)
    {
        $view_columns = [];

        // append system column function
        $systemColumnFunc = function ($isHeader, &$view_columns) {
            $filter = ['default' => true, ($isHeader ? 'header' : 'footer') => true];
            // set default view_column
            foreach (SystemColumn::getOptions($filter) as $view_column_system) {
                $view_column = new CustomViewColumn();
                $view_column->custom_view_id = $this->id;
                $view_column->view_column_target = array_get($view_column_system, 'name');
                $view_column->order = array_get($view_column_system, 'order');
                $view_columns[] = $view_column;
            }
        };

        // append system header
        $systemColumnFunc(true, $view_columns);

        // if $appendIndexColumn is true, append index column
        if ($appendIndexColumn) {
            $custom_columns = $this->custom_table->getSearchEnabledColumns();
            $order = 20;
            foreach ($custom_columns as $custom_column) {
                $view_column = new CustomViewColumn();
                $view_column->custom_view_id = $this->id;
                $view_column->view_column_type = ConditionType::COLUMN;
                $view_column->view_column_table_id = $custom_column->custom_table_id;
                $view_column->view_column_target_id = array_get($custom_column, 'id');
                $view_column->order = $order++;
                $view_columns[] = $view_column;
            }
        }

        // append system footer
        $systemColumnFunc(false, $view_columns);

        $this->custom_view_columns()->saveMany($view_columns);
        return $view_columns;
    }

    /**
     * copy from default view columns
     *
     * @param CustomView|null $fromView copied target view
     */
    public function copyFromDefaultViewColumns(?CustomView $fromView)
    {
        $view_columns = [];

        if (!isset($fromView)) {
            return [];
        }

        // set from view column
        foreach ($fromView->custom_view_columns_cache as $from_view_column) {
            $view_column = new CustomViewColumn();
            $view_column->custom_view_id = $this->id;
            $view_column->view_column_target = array_get($from_view_column, 'view_column_target');
            $view_column->order = array_get($from_view_column, 'order');
            $view_column->options = array_get($from_view_column, 'options');
            $view_columns[] = $view_column;
        }

        $this->custom_view_columns()->saveMany($view_columns);
        return $view_columns;
    }

    /**
     * set value filters
     */
    public function setValueFilters($query)
    {
        // If summary, call setSummaryValueFilters.
        if ($this->view_kind_type == ViewKindType::AGGREGATE) {
            return $this->setSummaryValueFilters($query);
        }

        // Cannot use $custom_view_filters_cache because summary to grid, use custom_view_filters directly.
        $custom_view_filters = $this->custom_view_filters;

        if ($custom_view_filters->count() > 0) {
            $service = $this->getSearchService()->setQuery($query);
            foreach ($custom_view_filters as $filter) {
                $service->setRelationJoin($filter);
            }

            $func = boolval($this->condition_reverse)? 'whereNot': 'where';
            $query->{$func}(function ($query) use ($custom_view_filters, $service) {
                foreach ($custom_view_filters as $filter) {
                    $service->whereCustomViewFilter($filter, $this->filter_is_or, $query);
                }
            });
        }

        return $query;
    }


    /**
     * set summary value filters
     */
    protected function setSummaryValueFilters($query)
    {
        // Cannot use $custom_view_filters_cache because summary to grid, use custom_view_filters directly.
        $custom_view_filters = $this->custom_view_filters;

        if ($custom_view_filters->count() > 0) {
            $service = $this->getSearchService()->setQuery($query);

            // Get $relationTables.
            // If summary sub query, set filter to sub query.
            $relationTables = [];
            foreach ($custom_view_filters as $filter) {
                $relationTable = $service->setRelationJoin($filter, [
                    'asSummary' => true,
                ]);
                $relationTables[] = $relationTable;

                // if has sub query(for child relation), set filter to sub query
                if ($relationTable && SearchType::isSummarySearchType($relationTable->searchType)) {
                    $relationTable->subQueryCallbacks[] = function ($subquery, $relationTable) use ($service, $filter) {
                        $service->whereCustomViewFilter($filter, $this->filter_is_or, $subquery);
                    };
                }
            }

            $query->where(function ($query) use ($relationTables, $custom_view_filters, $service) {
                foreach ($custom_view_filters as $index => $filter) {
                    $relationTable = $relationTables[$index];
                    // If filter is not already setted, call.
                    if (!$relationTable || !SearchType::isSummarySearchType($relationTable->searchType)) {
                        $service->whereCustomViewFilter($filter, $this->filter_is_or, $query);
                    }
                }
            });
        }

        return $query;
    }

    /**
     * set value sort
     *
     * @deprecated Please use sortModel func.
     */
    public function setValueSort($model)
    {
        return $this->sortModel($model);
    }

    /**
     * set value sort
     */
    public function sortModel($query)
    {
        // if request has "_sort", not executing
        if (request()->has('_sort')) {
            return $query;
        }

        $service = $this->getSearchService()->setQuery($query);
        $service->addSelect();

        foreach ($this->custom_view_sorts_cache as $custom_view_sort) {
            $service->orderByCustomViewSort($custom_view_sort);
        }

        return $query;
    }


    /**
     * Get arrays about Summary Column and custom_view_columns and custom_view_summaries
     *
     * @return array
     */
    public function getSummaryIndexAndViewColumns()
    {
        $results = [];
        // set grouping columns
        foreach ($this->custom_view_columns_cache as $custom_view_column) {
            $results[] = [
                'index' => ViewKindType::DEFAULT . '_' . $custom_view_column->id,
                'item' => $custom_view_column,
            ];
        }
        // set summary columns
        foreach ($this->custom_view_summaries_cache as $custom_view_summary) {
            $results[] = [
                'index' => ViewKindType::AGGREGATE . '_' . $custom_view_summary->id,
                'item' => $custom_view_summary,
            ];
            $item = $custom_view_summary->column_item;
        }

        return $results;
    }

    /**
     * get view columns select options. It contains system column(ex. id, suuid, created_at, updated_at), and table columns.
     * @param bool $is_y
     */
    public function getViewColumnsSelectOptions(bool $is_y): array
    {
        $options = [];

        // is summary view
        if ($this->view_kind_type == ViewKindType::AGGREGATE) {
            // if x column, set x as chart column
            if (!$is_y) {
                $options[] = ['id' => Define::CHARTITEM_LABEL, 'text' => exmtrans('chart.chartitem_label')];
            }
            // set as y
            else {
                foreach ($this->custom_view_columns_cache as $custom_view_column) {
                    $this->setViewColumnsOptions($options, ViewKindType::DEFAULT, $custom_view_column, true);
                }

                foreach ($this->custom_view_summaries_cache as $custom_view_summary) {
                    $this->setViewColumnsOptions($options, ViewKindType::AGGREGATE, $custom_view_summary, true);
                }
            }
        } else {
            // set as default view
            if (!$is_y) {
                $options[] = ['id' => Define::CHARTITEM_LABEL, 'text' => exmtrans('chart.chartitem_label')];
            }

            foreach ($this->custom_view_columns_cache as $custom_view_column) {
                $this->setViewColumnsOptions($options, ViewKindType::DEFAULT, $custom_view_column, $is_y ? true : null);
            }
        }

        return $options;
    }

    protected function setViewColumnsOptions(&$options, $view_kind_type, $custom_view_column, ?bool $is_number)
    {
        $option = $this->getSelectColumn($view_kind_type, $custom_view_column);
        if (is_null($is_number) || array_get($option, 'is_number') === $is_number) {
            $options[] = $option;
        }
    }

    protected function getSelectColumn($column_type, $custom_view_column)
    {
        $condition_item = $custom_view_column->condition_item;
        $view_column_id = $column_type . '_' . array_get($custom_view_column, 'id');

        $column_view_name = $condition_item ? $condition_item->getSelectColumnText($custom_view_column, $this->custom_table) : null;
        $is_number = $condition_item ? $condition_item->isSelectColumnNumber($custom_view_column) : false;

        if (array_get($custom_view_column, 'view_summary_condition') == SummaryCondition::COUNT) {
            $is_number = true;
        }
        return ['id' => $view_column_id, 'text' => $column_view_name, 'is_number' => $is_number];
    }

    public function getViewCalendarTargetAttribute()
    {
        $custom_view_columns = $this->custom_view_columns_cache;
        if (count($custom_view_columns) > 0) {
            return $custom_view_columns[0]->view_column_target;
        }
        return null;
    }

    public function setViewCalendarTargetAttribute($view_calendar_target)
    {
        $custom_view_columns = $this->custom_view_columns_cache;
        if (count($custom_view_columns) == 0) {
            $this->custom_view_columns[] = new CustomViewColumn();
        }
        $custom_view_columns[0]->view_column_target = $view_calendar_target;
    }

    public function getPagerCountAttribute()
    {
        return $this->getOption('pager_count');
    }

    public function setPagerCountAttribute($val)
    {
        $this->setOption('pager_count', $val);

        return $this;
    }

    public function getConditionJoinAttribute()
    {
        return $this->getOption('condition_join');
    }

    public function setConditionJoinAttribute($val)
    {
        $this->setOption('condition_join', $val);

        return $this;
    }

    public function getConditionReverseAttribute()
    {
        return $this->getOption('condition_reverse');
    }

    public function setConditionReverseAttribute($val)
    {
        $this->setOption('condition_reverse', $val);

        return $this;
    }

    public function getUseViewInfoboxAttribute()
    {
        return $this->getOption('use_view_infobox');
    }

    public function setUseViewInfoboxAttribute($val)
    {
        $this->setOption('use_view_infobox', $val);

        return $this;
    }

    public function getViewInfoboxTitleAttribute()
    {
        return $this->getOption('view_infobox_title');
    }
    public function setViewInfoboxTitleAttribute($val)
    {
        $this->setOption('view_infobox_title', $val);

        return $this;
    }

    public function getViewInfoboxAttribute()
    {
        return $this->getOption('view_infobox');
    }
    public function setViewInfoboxAttribute($val)
    {
        $this->setOption('view_infobox', $val);

        return $this;
    }

    public function getHeaderAlignAttribute()
    {
        return $this->getOption('header_align');
    }

    public function setHeaderAlignAttribute($val)
    {
        $this->setOption('header_align', $val);

        return $this;
    }

    public function getHeaderOptions()
    {
        $attributes = [];
        switch ($this->header_align) {
            case 'center':
            case 'right':
                $attributes['class'] = 'header-' . $this->header_align;
                break;
        }
        return $attributes;
    }

    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function getDisabledDeleteAttribute()
    {
        return boolval($this->view_kind_type == ViewKindType::ALLDATA);
    }

    /**
     * Whether login user has edit permission about this view.
     */
    public function hasEditPermission()
    {
        $login_user = \Exment::user();
        if ($this->view_type == ViewType::SYSTEM) {
            return $this->custom_table->hasSystemViewPermission();
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

    /**
     * Get custom view data
     *
     * @return array
     */
    public function getQueryData()
    {
        $query = $this->custom_table->getValueQuery();
        $this->filterSortModel($query);
        return $query->get();
    }

    /**
     * check if target id view can be deleted
     * @param int|string $id
     * @return array [boolean, string] status, error message.
     */
    public static function validateDestroy($id)
    {
        // check notify target view
        $notify_count = Notify::where('custom_view_id', $id)
            ->count();

        if ($notify_count > 0) {
            return [
                'status'  => false,
                'message' => exmtrans('custom_view.message.used_notify_error'),
            ];
        }
        // check select_table
        $column_count = CustomColumn::whereIn('options->select_target_view', [strval($id), intval($id)])
            ->count();

        if ($column_count > 0) {
            return [
                'status'  => false,
                'message' => exmtrans('custom_view.message.used_column_error'),
            ];
        }
        return [];
    }
}
