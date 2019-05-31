<?php
namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Grid\Linker;
use Encore\Admin\Widgets\Box;
//use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Table as WidgetTable;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SearchType;

class SearchController extends AdminControllerBase
{
    protected $custom_table;
    /**
     * Rendering search header for adminLTE header
     */
    public static function renderSearchHeader()
    {
        // create searching javascript
        $ajax_url = admin_url("search/header");
        $list_url = admin_url("search");
        $script = <<<EOT
    $(function () {
        if(!hasValue($('.search-form #query'))){
            return;
        }
        var search_suggests = [];
        $('.search-form #query').autocomplete({
            source: function (req, res) {
                $.ajax({
                    url: "$ajax_url",
                    data: {
                        _token: LA.token,
                        query: req.term
                    },
                    dataType: "json",
                    type: "GET",
                    success: function (data) {
                        search_suggests = data;
                        res(data);
                    },
                });
            },
            // Search when seleting
            select : function(e, ui)
                {
                    if(ui.item)
                    {
                        $.pjax({ container: '#pjax-container', url: '$list_url' + '?table_name=' + ui.item.table_name + '&value_id=' + ui.item.value_id });
                    }
                },
            autoFocus: false,
            delay: 500,
            minLength: 2,
        })
        .autocomplete("instance")._renderItem = function (ul, item) {
                    var p = $('<p/>', {
                        'class': 'search-item-icon',
                        'html': [
                            $('<i/>', {
                                'class': 'fa ' + item.icon
                            }),
                            $('<span/>', {
                                'text': item.table_view_name,
                                'style': 'background-color:' + item.color,
                            }),
                        ]
                    });
                    var div = $('<div/>', {
                        'tabindex' : -1,
                        'class' : 'ui-menu-item-wrapper',
                        'html' : [p, $('<span/>', {'text':item.text})]
                    });
                    return $('<li class="ui-menu-item-with-icon"></li>')
                        .data("item.autocomplete", item)
                        .append(div)
                        .appendTo(ul);
                };
    });
EOT;
        Admin::script($script);
        return view('exment::search.search-bar');
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
                array_push($results, [
                    'value' => $text
                    , 'text' => $text
                    , 'icon' =>array_get($table, 'options.icon')
                    , 'table_view_name' => array_get($table, 'table_view_name')
                    , 'table_name' => array_get($table, 'table_name')
                    , 'value_id' => array_get($d, 'id')
                    , 'color' =>array_get($d, 'options.color') ?? "#3c8dbc"
                    ]);
                if (count($results) >= 10) {
                    break;
                }
            }
        }
        return $results;
    }
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
     * @param Request $request
     * @return Content
     */
    protected function getFreeWord(Request $request, Content $content)
    {
        if (is_null($request->query('query'))) {
            return redirect(admin_url());
        }
        $this->AdminContent($content);
        $content->header(exmtrans('search.header_freeword'));
        $content->description(exmtrans('search.description_freeword'));
        // create searching javascript
        $script = <<<EOT
    function getNaviData() {
        var tables = JSON.parse($('.tables').val());
        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];
            if(!hasValue(table)){
                continue;
            }
            var url = admin_url('search/list?table_name=' + table.table_name + '&query=' + $('.base_query').val());
            getNaviDataItem(url, table.table_name);
        }
    }
EOT;
        Admin::script($script);
        $this->setCommonScript();
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
                if (!$custom_column->indexEnabled()) {
                    return false;
                }
                return true;
            });
            if (!is_null($result)) {
                array_push($results, $table);
            }
        }
        return collect($results);
    }
    /**
     * Get search results using query
     */
    public function getList(Request $request)
    {
        $q = $request->input('query');
        $table = CustomTable::getEloquent($request->input('table_name'), true);
        $boxHeader = $this->getBoxHeaderHtml($table);
        // search all data using index --------------------------------------------------
        $paginate = $table->searchValue($q, [
            'paginate' => true
        ]);
        $paginate->setPath(admin_urls('search', 'list') . "?query=$q&table_name={$request->input('table_name')}");
        $datalist = $paginate->items();
        
        // Get result HTML.
        if (count($datalist) == 0) {
            return [
                'table_name' => array_get($table, 'table_name'),
                'header' => $boxHeader,
                'body' => exmtrans('search.no_result')
            ];
        }
        $links = $paginate->links('exment::search.links')->toHtml();
        // get headers and bodies
        $view = CustomView::getDefault($table);
        list($headers, $bodies) = $view->getDataTable($datalist, [
            'action_callback' => function (&$link, $custom_table, $data) {
                if (count($custom_table->getRelationTables()) > 0) {
                    $link .= (new Linker)
                    ->url($data->getRelationSearchUrl(true))
                    ->icon('fa-compress')
                    ->tooltip(exmtrans('search.header_relation'));
                }
            }
        ]);
        return [
            'table_name' => array_get($table, 'table_name'),
            'header' => $boxHeader,
            'body' => (new WidgetTable($headers, $bodies))->class('table table-hover')->render(),
            'footer' => $links
        ];
    }
    
    // For relation search  --------------------------------------------------
    /**
     * Get relation search result page. this function is called when user select suggest.
     * @param Request $request
     * @return Content
     */
    protected function getRelationSearch(Request $request, Content $content)
    {
        // get seleted name
        $table = CustomTable::getEloquent($request->input('table_name'));
        $model = getModelName($table)::find($request->input('value_id'));
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
        // create searching javascript
        $list_url = admin_url("search/relation");
        $script = <<<EOT
function getNaviData() {
    var tables = JSON.parse($('.tables').val());
    for (var i = 0; i < tables.length; i++) {
        var table = tables[i];
        if(!hasValue(table)){
            continue;
        }
        var url = admin_url('search/relation?search_table_name=' + table.table_name 
            + '&value_table_name=' + $('.table_name').val() 
            + '&value_id=' + $('.value_id').val()
            + '&search_type=' + table.search_type
        );
        getNaviDataItem(url, table.table_name);
    }
}
EOT;
        Admin::script($script);
        $this->setCommonScript();
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
        $value_table = CustomTable::getEloquent($request->input('value_table_name'), true);

        /// $search_table is the table for search. it's ex. select_table, relation, ...
        $search_table = CustomTable::getEloquent($request->input('search_table_name'), true);
        $search_type = $request->input('search_type');

        $data = $value_table->searchRelationValue($search_type, $value_id, $search_table, [
            'paginate' => true,
            'maxCount' => 10,
        ]);

        $boxHeader = $this->getBoxHeaderHtml($search_table);
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
        $view = CustomView::getDefault($search_table);
        // definition action_callback is not $search_type is SELF
        if ($search_type != SearchType::SELF) {
            $option = [
                'action_callback' => function (&$link, $custom_table, $data) {
                    if (count($custom_table->getRelationTables()) > 0) {
                        $link .= (new Linker)
                        ->url($data->getRelationSearchUrl(true))
                        ->icon('fa-compress')
                        ->tooltip(exmtrans('search.header_relation'));
                    }
                }
            ];
        } else {
            $option = [];
        }
        list($headers, $bodies) = $view->getDataTable($data, $option);
        return [
            'table_name' => array_get($search_table, 'table_name'),
            'header' => $boxHeader,
            'body' => (new WidgetTable($headers, $bodies))->class('table table-hover')->render(),
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
        array_push($results, $this->getTableArray($value_table, SearchType::SELF));
        // loop and add $results
        foreach ($relationTables as $relationTable) {
            array_push($results, $this->getTableArray($relationTable['table'], $relationTable['searchType']));
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
        return $array;
    }
    protected function getBoxHeaderHtml($custom_table)
    {
        // boxheader
        $boxHeader = [];
        // check edit permission
        if ($custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            $new_url = admin_url("data/{$custom_table->table_name}/create");
            $list_url = admin_url("data/{$custom_table->table_name}");
        }
        return view('exment::dashboard.list.header', [
            'new_url' => $new_url ?? null,
            'list_url' => $list_url ?? null,
        ])->render();
    }
    /**
     * set common script for list or relation search
     */
    protected function setCommonScript()
    {
        // create searching javascript
        $script = <<<EOT
    $(function () {
        getNaviData();
    });
    function getNaviDataItem(url, table_name){
        var box = $('.table_' + table_name);
        box.find('.overlay').show();
        // Get Data
        $.ajax({
            url: url,
            type: 'GET',
        })
        // Execute when success Ajax Request
        .done((data) => {
            var box = $('.table_' + data.table_name);
            box.find('.box-body .box-body-inner-header').html(data.header);
            box.find('.box-body .box-body-inner-body').html(data.body);
            box.find('.box-body .box-body-inner-footer').html(data.footer);
            box.find('.overlay').hide();
            Exment.CommonEvent.tableHoverLink();
        })
        .always((data) => {
        });
    }
    ///// click dashboard link event
    $(document).off('click', '[data-ajax-link]').on('click', '[data-ajax-link]', [], function(ev){
        // get link
        var url = $(ev.target).closest('[data-ajax-link]').data('ajax-link');
        var table_name = $(ev.target).closest('[data-table_name]').data('table_name');
        getNaviDataItem(url, table_name);
    });
EOT;
        Admin::script($script);
    }
}
