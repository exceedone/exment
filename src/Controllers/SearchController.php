<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
//use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Table as WidgetTable;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Enums\RoleValue;
use Exceedone\Exment\Enums\RelationType;
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
        $ajax_url = admin_base_path("search/header");
        $list_url = admin_base_path("search");

        $script = <<<EOT
    $(function () {
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
                    type: "POST",
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
                        window.location.href = '$list_url' + '?table_name=' + ui.item.table_name + '&value_id=' + ui.item.value_id;
                    }
                },
            autoFocus: true,
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
                        'html' : [p, $('<span/>', {'text':item.label})]
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
        $tables = CustomTable::where('search_enabled', true)->get();
        foreach ($tables as $table) {
            if (count($results) >= 10) {
                break;
            }

            // Get search enabled columns.
            $search_columns = $table->getSearchEnabledColumns();
            if (count($search_columns) == 0) {
                continue;
            }

            // search all data using index --------------------------------------------------
            $data = $this->searchValue($q, $table, $search_columns, 10);

            foreach ($data as $d) {
                // get label
                $label = $d->label;
                array_push($results, [
                    'label' => $label
                    , 'value' => $label
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
        $this->AdminContent($content);
        $content->header(exmtrans('search.header_freeword'));
        $content->description(exmtrans('search.description_freeword'));

        // create searching javascript
        $list_url = admin_base_path("search/list");
        $script = <<<EOT
    var searchIndex = 0;
    $(function () {
        getNaviData();
    });

    function getNaviData() {
        var tables = JSON.parse($('.tables').val());
        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];

            // Get Data
            $.ajax({
                url: '$list_url',
                type: 'POST',
                data: {
                    table_name: table.table_name
                    , query: $('.base_query').val()
                    , _token: LA.token
                },
                dataType: "json"
            })
                // Execute when success Ajax Request
                .done((data) => {
                    console.log(data);
                    var box = $('.table_' + data.table_name);
                    box.find('.box-body').html(data.html);
                    box.find('.overlay').remove();
                    Exment.CommonEvent.tableHoverLink();
                })
                .always((data) => {
                });
        }
    }

EOT;
        Admin::script($script);

        // add header and description
        $title = sprintf(exmtrans("search.result_label"), $request->input('query'));
        $this->setPageInfo($title, $title, exmtrans("plugin.description"));

        $content->body(view('exment::search.index', ['query' => $request->input('query'), 'tables' => $this->getSearchTargetTable()]));
        return $content;
    }

    /**
     * Get Search enabled table list
     */
    protected function getSearchTargetTable($value_table = null)
    {
        $results = [];
        $tables = CustomTable::with('custom_columns')->where('search_enabled', true)->get();
        foreach ($tables as $table) {
            // if not role, continue
            if (!$table->hasPermission(RoleValue::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                continue;
            }

            // search using column
            $result = array_get($table, 'custom_columns')->first(function ($custom_column) {
                // this column is search_enabled, add array.
                if (!boolval(array_get($custom_column['options'], 'search_enabled'))) {
                    return false;
                }

                // only set $value_table (for relation search)
                if (isset($value_table)) {
                    // get only table as below
                    // - $table equal self target table
                    // - $table is child table for $value_table
                    // $custom_column->column_type is "select_target_table" and $custom_column->options->select_target_table is target_table
                    $result = false;
                    if (array_get($table, 'id') == $value_table->id) {
                        $result = true;
                    }
                    
                    if (!$result) {
                        
                        // get custom relation.
                        if (!is_null(CustomRelation
                            ::where('parent_custom_table_id', $value_table->id)
                            ->where('child_custom_table_id', $table['id'])
                            ->first())) {
                            $result = true;
                        }
                    }

                    if (!$result) {
                        return false;
                    }
                }

                return true;
            });
            if (!is_null($result)) {
                array_push($results, $this->getTableArray($table));
            }
        }

        return $results;
    }

    /**
     * Get search results using query
     */
    public function getList(Request $request)
    {
        $q = $request->input('query');
        $table = CustomTable::findByName($request->input('table_name'), true);
        // Get search enabled columns.
        $search_columns = $table->getSearchEnabledColumns();

        if (count($search_columns) == 0) {
            return ['table_name' => array_get($table, 'table_name'), "html" => exmtrans('search.no_result')];
        }

        // search all data using index --------------------------------------------------
        $datalist = $this->searchValue($q, $table, $search_columns, 5);
        
        // Get result HTML.
        if (count($datalist) == 0) {
            return ['table_name' => array_get($table, 'table_name'), "html" => exmtrans('search.no_result')];
        }

        // get headers and bodies
        $view = CustomView::getDefault($table);
        list($headers, $bodies) = $view->getDataTable($datalist, [
            'action_callback' => function(&$link, $custom_table, $data){
                $link .= '<a href="'.admin_base_path('search?table_name='.array_get($custom_table, 'table_name').'&value_id='.array_get($data, 'id')).'"><i class="fa fa-compress"></i></a>';
            }
        ]);

        return ['table_name' => array_get($table, 'table_name'), "html" => (new WidgetTable($headers, $bodies))->class('table table-hover')->render()];
    }
    
    // For relation search  --------------------------------------------------
    /**
     * Get relation search result page. this function is called when user select suggest.
     * @param Request $request
     * @return Content
     */
    protected function getRelationSearch(Request $request, Content $content)
    {
        $this->AdminContent($content);

        $content->header(exmtrans('search.header_relation'));
        $content->description(exmtrans('search.description_relation'));

        // get seleted name
        $table = CustomTable::findByName($request->input('table_name'));
        $model = getModelName($table)::find($request->input('value_id'));
        $value = $model->label;
        $content->body(view('exment::search.index', [
            'table_name' => $request->input('table_name')
            , 'value_id' => $request->input('value_id')
            , 'query' => $value
            , 'tables' => $this->getSearchTargetRelationTable($table)]));

        // create searching javascript
        $list_url = admin_base_path("search/relation");
        $script = <<<EOT
var searchIndex = 0;
$(function () {
    getNaviData();
});

function getNaviData() {
    var tables = JSON.parse($('.tables').val());
    for (var i = 0; i < tables.length; i++) {
        var table = tables[i];

        // Get data
        $.ajax({
            url: '$list_url',
            type: 'POST',
            data: {
                search_table_name: table.table_name
                , value_table_name: $('.table_name').val()
                , value_id: $('.value_id').val()
                , search_type: table.search_type
                , _token: LA.token
            },
            dataType: "json"
        })
            // Execute when success Ajax Request
            .done((data) => {
                console.log(data);
                var box = $('.table_' + data.table_name);
                box.find('.box-body').html(data.html);
                box.find('.overlay').remove();
                Exment.CommonEvent.tableHoverLink();
            })
            .always((data) => {
            });
    }
}

EOT;
        Admin::script($script);

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
        $value_table = CustomTable::findByName($request->input('value_table_name'), true);
        $value_table_id = $value_table->id;
        /// $search_table is the table for search. it's ex. select_table, relation, ...
        $search_table = CustomTable::findByName($request->input('search_table_name'), true);
        $search_type = $request->input('search_type');

        // Get search enabled columns.
        $search_columns = $search_table->getSearchEnabledColumns();

        switch ($search_type) {
            // self table
            case SearchType::SELF:
                $data = [getModelName($search_table)::find($value_id)];
                break;
            // select_table(select box)
            case SearchType::SELECT_TABLE:
                // Retrieve the record list whose value is "value_id" in the column "options.select_target_table" of the table "custom column"
                $selecttable_columns = $search_table->custom_columns()
                    ->where('column_type', 'select_table')
                    ->whereIn('options->select_target_table', [$value_table_id, strval($value_table_id)])
                    ->get();

                if (count($search_columns) == 0) {
                    return ['table_name' => array_get($search_table, 'table_name'), "html" => exmtrans('search.no_result')];
                }

                $data = $this->searchValue($value_id, $search_table, $selecttable_columns, 5, false);
                break;
            
            // one_to_many
            case SearchType::ONE_TO_MANY:
                $data = getModelName($search_table)::where('parent_id', $value_id)->take(5)->get();
                break;
            // many_to_many
            case SearchType::MANY_TO_MANY:
                $relation_name = CustomRelation::getRelationNameByTables($value_table, $search_table);

                // get search_table value
                // where: parent_id is value_id
                $data = getModelName($search_table)
                    ::join($relation_name, "$relation_name.child_id", getDBTableName($search_table).".id")
                    ->where("$relation_name.parent_id", $value_id)
                    ->take(5)
                    ->get();
                break;
        }

        // Get search result HTML.
        if (!$data || count($data) == 0) {
            return ['table_name' => array_get($search_table, 'table_name'), "html" => exmtrans('search.no_result')];
        }
        
        // get headers and bodies
        $view = CustomView::getDefault($search_table);
        list($headers, $bodies) = $view->getDataTable($data);

        return ['table_name' => array_get($search_table, 'table_name'), "html" => (new WidgetTable($headers, $bodies))->class('table table-hover')->render()];
    }

    /**
     * Get Search enabled relation table list.
     * It contains search_type(self, select_table, one_to_many, many_to_many)
     */
    protected function getSearchTargetRelationTable($value_table)
    {
        $results = [];

        // 1. For self-table
        array_push($results, $this->getTableArray($value_table, SearchType::SELF));

        // 2. Get tables as "select_table". They contains these columns matching them.
        // * table_column > options > search_enabled is true.
        // * table_column > options > select_target_table is table id user selected.
        $tables = CustomTable
        ::whereHas('custom_columns', function ($query) use ($value_table) {
            $query->whereIn('options->search_enabled', [1, "1"])
            ->where('options->select_target_table', $value_table->id);
        })
        ->where('search_enabled', true)
        ->get();

        foreach ($tables as $table) {
            // if not role, continue
            if (!$table->hasPermission(RoleValue::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                continue;
            }
            array_push($results, $this->getTableArray($table, SearchType::SELECT_TABLE));
        }

        // 3. Get relation tables.
        // * table "custom_relations" and column "parent_custom_table_id" is $value_table->id.
        $tables = CustomTable
        ::join('custom_relations', 'custom_tables.id', 'custom_relations.parent_custom_table_id')
        ->join('custom_tables AS child_custom_tables', 'child_custom_tables.id', 'custom_relations.child_custom_table_id')
            ->whereHas('custom_relations', function ($query) use ($value_table) {
                $query->where('parent_custom_table_id', $value_table->id);
            })->get(['child_custom_tables.*', 'custom_relations.relation_type'])->toArray();
        foreach ($tables as $table) {
            // if not role, continue
            $table_obj = CustomTable::getEloquent(array_get($table, 'id'));
            if (!$table_obj->hasPermission(RoleValue::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                continue;
            }
            array_push($results, $this->getTableArray($table, array_get($table, 'relation_type') == RelationType::ONE_TO_MANY ? SearchType::ONE_TO_MANY : SearchType::MANY_TO_MANY));
        }

        return $results;
    }

    /**
     * search value using search-enabld column
     */
    protected function searchValue($q, $table, $search_columns, $max_count, $isLike = true)
    {
        $data = [];
        $query = ($isLike ? '%' : '') . $q . ($isLike ? '%' : '');
        $mark = ($isLike ? 'LIKE' : '=');
        foreach ($search_columns as $search_column) {
            // get data
            $foodata = getModelName($table)
                ::where($search_column->getIndexColumnName(), $mark, $query)
                ->take($max_count - count($data))
                ->get();
            
            foreach ($foodata as $foo) {
                if (count($data) >= $max_count) {
                    break;
                }

                // if exists id, continue
                if (!is_null(collect($data)->first(function ($value, $key) use ($foo) {
                    return array_get($value, 'id') == array_get($foo, 'id');
                }))) {
                    continue;
                }

                $data[] = $foo;
            }
        }

        return $data;
    }

    protected function getTableArray($table, $search_type = null){
        $array = [
            'id' => array_get($table, 'id'),
            'table_name' => array_get($table, 'table_name'),
            'table_view_name' => array_get($table, 'table_view_name'),
            'icon' => array_get($table, 'options.icon'),
            'color' => array_get($table, 'options.color'),
            'box_sytle' => array_has($table, 'options.color') ? 'border-top-color:'.esc_html(array_get($table, 'options.color')).';' : null,
        ];
        if(isset($search_type)){
            $array['search_type'] = $search_type;
        }
        return $array;
    }   
}
