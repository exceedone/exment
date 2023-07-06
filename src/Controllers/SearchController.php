<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Grid\Linker;
//use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Table as WidgetTable;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Auth\Permission as Checker;

class SearchController extends AdminControllerBase
{
    protected $custom_table;


    // Search Header ----------------------------------------------------

    /**
     * Rendering search header for adminLTE header
     */
    public static function renderSearchHeader()
    {
        // create searching javascript
        $ajax_url = admin_url("search/header");
        $list_url = admin_url("search");
        return view('exment::search.search-bar', [
            'ajax_url' => $ajax_url,
            'list_url' => $list_url,
        ]);
    }
    /**
     * Get result list when users input search bar, by query
     * @param Request $request
     * @return array
     */
    public function header(Request $request)
    {
        $q = $request->input('query');
        if (!isset($q)) {
            return [];
        }
        $results = [];
        // Get table list
        $tables = $this->getSearchTargetTable();
        foreach ($tables as $table) {
            // search all data using index --------------------------------------------------
            $data = $table->searchValue($q, [
                'maxCount' => 10,
            ]);
            foreach ($data as $d) {
                // get label
                $text = $d->label;
                $results[] = [
                    'value' => $text
                    , 'text' => $text
                    , 'icon' =>array_get($table, 'options.icon')
                    , 'table_view_name' => array_get($table, 'table_view_name')
                    , 'table_name' => array_get($table, 'table_name')
                    , 'value_id' => array_get($d, 'id')
                    , 'color' =>array_get($d, 'options.color') ?? "#3c8dbc"
                    ];
                if (count($results) >= 10) {
                    break;
                }
            }
            if (count($results) >= 10) {
                break;
            }
        }
        return $results;
    }





    // Search Page ----------------------------------------------------

    /**
     * Show search result page.
     */
    public function index(Request $request, Content $content)
    {
        if ($request->has('table_name') && $request->has('value_id')) {
            return $this->getRelationSearch($request, $content);
        } else {
            return $this->getFreeWord($request, $content);
        }
    }

    /**
     * Get free word result page. this function is called when user input word end click enter.
     *
     * @param Request $request
     * @param Content $content
     * @return Content|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function getFreeWord(Request $request, Content $content)
    {
        if (is_null($request->query('query'))) {
            return redirect(admin_url());
        }
        $this->AdminContent($content);
        $content->header(exmtrans('search.header_freeword'));
        $content->description(exmtrans('search.description_freeword'));
        $this->setCommonScript(true);

        // add header and description
        $title = sprintf(exmtrans("search.result_label"), $request->input('query'));
        $this->setPageInfo($title, $title, exmtrans("plugin.description"));

        $tableArrays = $this->getSearchTargetTable()->map(function ($table) {
            return $this->getTableArray($table);
        });
        $content->body(view('exment::search.index', ['query' => $request->input('query'), 'tables' => $tableArrays]));
        return $content;
    }
    /**
     * Get Search enabled table list
     */
    protected function getSearchTargetTable($value_table = null)
    {
        $results = [];
        $tables = CustomTable::with('custom_columns')->searchEnabled()->get();
        foreach ($tables as $table) {
            // if not role, continue
            if (!$table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                continue;
            }
            // search using column
            $result = array_get($table, 'custom_columns')->first(function ($custom_column) {
                // this column is search_enabled, add array.
                if (boolval($custom_column->index_enabled) && boolval($custom_column->getOption('freeword_search'))) {
                    return true;
                }
                return false;
            });
            if (!is_null($result)) {
                $results[] = $table;
            }
        }
        return collect($results);
    }




    // Search list ----------------------------------------------------
    /**
     * Get search results using query
     */
    public function getLists(Request $request)
    {
        $q = $request->input('query');
        //search each tables
        $table_names = stringToArray($request->input('table_names', []));

        $results = [];
        foreach ($table_names as $table_name) {
            $results[$table_name] = $this->getListItem($request, $q, $table_name);
        }

        return $results;
    }

    /**
     * Get search results using query
     */
    public function getList(Request $request)
    {
        $q = $request->input('query');
        $table_name = $request->input('table_name', []);

        return $this->getListItem($request, $q, $table_name);
    }

    /**
     * Get search results item using query
     */
    protected function getListItem(Request $request, $q, $table_name)
    {
        $custom_table = CustomTable::getEloquent($table_name);
        if (empty($custom_table)) {
            return [];
        }

        $boxHeader = $this->getBoxHeaderHtml($custom_table, ['query' => $q]);
        // search all data using index --------------------------------------------------
        $paginate = $custom_table->searchValue($q, [
            'paginate' => true,
            'maxCount' => System::datalist_pager_count() ?? 5,
            'searchDocument' => true,
        ]);
        $paginate->setPath(admin_urls('search', 'list') . "?query=$q&table_name=$table_name");
        $datalist = $paginate->items();

        // Get result HTML.
        if (count($datalist) == 0) {
            return [
                'table_name' => array_get($custom_table, 'table_name'),
                'header' => $boxHeader,
                'body' => exmtrans('search.no_result')
            ];
        }
        $links = $paginate->links('exment::search.links')->toHtml();
        // get headers and bodies
        $view = CustomView::getAllData($custom_table);

        list($headers, $bodies, $columnStyles, $columnClasses) = $view->convertDataTable($datalist, [
            'action_callback' => function (&$link, $custom_table, $data) {
                if (count($custom_table->getRelationTables()) > 0) {
                    $link .= (new Linker())
                    ->url($data->getRelationSearchUrl(true))
                    ->icon('fa-compress')
                    ->tooltip(exmtrans('search.header_relation'));
                }
            }
        ]);
        $table = (new WidgetTable($headers, $bodies))->class('table table-hover')
            ->setColumnStyle($columnStyles)
            ->setColumnClasses($columnClasses);


        return [
            'table_name' => array_get($custom_table, 'table_name'),
            'header' => $boxHeader,
            'body' => $table->render(),
            'footer' => $links
        ];
    }





    // For relation search  --------------------------------------------------
    /**
     * Get relation search result page. this function is called when user select suggest.
     *
     * @param Request $request
     * @param Content $content
     * @return Content|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|void
     */
    protected function getRelationSearch(Request $request, Content $content)
    {
        // get seleted name
        $table = CustomTable::getEloquent($request->input('table_name'));
        $model = getModelName($table)::find($request->input('value_id'));
        if (!$model) {
            Checker::notFoundOrDeny();
            return;
        }

        // get target tables
        $targetTables = $this->getSearchTargetRelationTable($table);
        // if if only self table, and query "relation"(force showing relation), then redirect show page
        if (count($targetTables) == 1 && $request->input('relation') != "1") {
            return redirect($model->getUrl());
        }
        $this->AdminContent($content);
        $content->header(exmtrans('search.header_relation'));
        $content->description(exmtrans('search.description_relation'));
        $value = $model->label;
        $content->body(
            view('exment::search.index', [
            'table_name' => $request->input('table_name'),
            'value_id' => $request->input('value_id'),
            'query' => $value,
            'tables' => $this->getSearchTargetRelationTable($table)])
        );

        $this->setCommonScript(false);

        // add header and description
        $title = sprintf(exmtrans("search.result_label"), $value);
        $this->setPageInfo($title);
        return $content;
    }


    /**
     * get query relation value
     */
    public function getRelationList(Request $request)
    {
        // value_id is the id user selected.
        $value_id = $request->input('value_id');
        // value_table is the table user selected.
        $value_table = CustomTable::getEloquent($request->input('value_table_name'));

        /// $search_table is the table for search. it's ex. select_table, relation, ...
        $search_table = CustomTable::getEloquent($request->input('search_table_name'));
        $search_type = $request->input('search_type');

        $options = [
            'paginate' => true,
            'maxCount' => System::datalist_pager_count() ?? 5,
        ];
        $data = $value_table->searchRelationValue($search_type, $value_id, $search_table, $options);

        $boxHeader = $this->getBoxHeaderHtml($search_table, array_get($options, 'listQuery', []));
        if (isset($data) && $data instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $paginate = $data;
            $data = $paginate->items();
            $paginate->setPath(
                admin_urls('search', 'relation')
                . "?value_table_name={$request->input('value_table_name')}"
                . "&search_table_name={$request->input('search_table_name')}"
                . "&value_id={$request->input('value_id')}"
                . "&search_type={$request->input('search_type')}"
            );
        }
        // Get search result HTML.
        if (!$data || count($data) == 0) {
            return [
                'table_name' => array_get($search_table, 'table_name'),
                'header' => $boxHeader,
                'body' => exmtrans('search.no_result'),
            ];
        }
        // set links
        $links = isset($paginate) ? $paginate->links('exment::search.links')->toHtml() : "";

        // get headers and bodies
        $view = CustomView::getAllData($search_table);
        // definition action_callback is not $search_type is SELF
        if ($search_type != SearchType::SELF) {
            $option = [
                'action_callback' => function (&$link, $custom_table, $data) {
                    if (count($custom_table->getRelationTables()) > 0) {
                        $link .= (new Linker())
                        ->url($data->getRelationSearchUrl(true))
                        ->icon('fa-compress')
                        ->tooltip(exmtrans('search.header_relation'));
                    }
                }
            ];
        } else {
            $option = [];
        }

        list($headers, $bodies, $columnStyles, $columnClasses) = $view->convertDataTable($data, $option);
        $table = (new WidgetTable($headers, $bodies))
            ->class('table table-hover')
            ->setColumnStyle($columnStyles)
            ->setColumnClasses($columnClasses);

        return [
            'table_name' => array_get($search_table, 'table_name'),
            'header' => $boxHeader,
            'body' => $table->render(),
            'footer' => $links
        ];
    }
    /**
     * Get Search enabled relation table list.
     * It contains search_type(self, select_table, one_to_many, many_to_many)
     */
    protected function getSearchTargetRelationTable($value_table)
    {
        $results = [];
        // get relation tables
        $relationTables = $value_table->getRelationTables();
        // 1. For self-table
        $results[] = $this->getTableArray($value_table, SearchType::SELF);
        ///// loop and add $results
        $searchTypes = [];
        foreach ($relationTables as $relationTable) {
            // check already setted search type
            $key = "{$relationTable->table->id}-$relationTable->searchType";
            if (in_array($key, $searchTypes)) {
                continue;
            }
            $searchTypes[] = $key;

            $results[] = $this->getTableArray($relationTable->table, $relationTable->searchType);
        }
        return $results;
    }
    protected function getTableArray($table, $search_type = null)
    {
        $array = [
            'id' => array_get($table, 'id'),
            'table_name' => array_get($table, 'table_name'),
            'table_view_name' => array_get($table, 'table_view_name'),
            'icon' => array_get($table, 'options.icon'),
            'color' => array_get($table, 'options.color'),
            'box_sytle' => array_has($table, 'options.color') ? 'border-top-color:'.esc_html(array_get($table, 'options.color')).';' : null,
        ];
        if (isset($search_type)) {
            $array['search_type'] = $search_type;
        }
        if (CustomTable::getEloquent($table)->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            $array['show_list'] = true;
        }

        // add table box key
        $array['box_key'] = short_uuid();
        return $array;
    }
    protected function getBoxHeaderHtml($custom_table, $query = [])
    {
        // boxheader
        $boxHeader = [];
        // check edit permission
        if ($custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            $new_url = admin_url("data/{$custom_table->table_name}/create");
            $list_url = admin_url("data/{$custom_table->table_name}");

            if (boolval(config('exment.search_list_link_filter', true)) && isset($query)) {
                $query['view'] = CustomView::getAllData($custom_table)->suuid;
                $query['execute_filter'] = '1';

                $list_url .= '?' . http_build_query($query);
            }
        }
        return view('exment::dashboard.list.header', [
            'new_url' => $new_url ?? null,
            'list_url' => $list_url ?? null,
        ])->render();
    }
    /**
     * set common script for list or relation search
     */
    protected function setCommonScript(bool $isList)
    {
        // create searching javascript
        $script = <<<EOT
    $(function () {
        Exment.SearchEvent.getNaviData($isList);
    });
EOT;
        Admin::script($script);
    }
}
