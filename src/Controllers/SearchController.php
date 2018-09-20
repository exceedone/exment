<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
//use Encore\Admin\Widgets\Form;
use Encore\Admin\Widgets\Table;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;

class SearchController extends AdminControllerBase
{
    protected $custom_table;

    /**
     * Rendering search header for adminLTE header
     */
    public static function renderSearchHeader(){
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
    public function header(Request $request){
        $q = $request->input('query');
        if(!isset($q)){return [];}

        $results = [];
        // Get table list
        $tables = CustomTable::all();
        foreach ($tables as $table)
        {
            if(count($results) >= 10){break;}

            // Get search enabled columns.
            $search_columns = getSearchEnabledColumns(array_get($table, 'table_name'));
            if(count($search_columns) == 0){
                continue;
            }

            // search all data using index --------------------------------------------------
            $data = $this->searchValue($q, $table, $search_columns, 10);
            // get label
            $label_column = getLabelColumn($table);

            foreach ($data as $d)
            {
                array_push($results, [
                    'label' => array_get($d['value'], $label_column->column_name)
                    , 'value' => array_get($d['value'], $label_column->column_name)
                    , 'icon' =>array_get($table, 'icon')
                    , 'table_view_name' => array_get($table, 'table_view_name')
                    , 'table_name' => array_get($table, 'table_name')
                    , 'value_id' => array_get($d, 'id')
                    , 'color' =>array_get($d, 'color') ?? "#3c8dbc"
                    ]);
                if(count($results) >= 10){break;}
            }
        }

        return $results;
    }

    /**
     * Show search result page.
     */
    public function index(Request $request, Content $content){
        if($request->has('table_name') && $request->has('value_id')){
            return $this->getRelationSearch($request);
        }else{
            return $this->getFreeWord($request);
        }
    }

    /**
     * Get free word result page. this function is called when user input word end click enter.
     * @param Request $request
     * @return Content
     */
    protected function getFreeWord(Request $request, Content $content){
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
    protected function getSearchTargetTable($value_table = null){
        $results = [];
        $tables = CustomTable::with('custom_columns')->where('search_enabled', true)->get()->toArray();
        foreach ($tables as $table)
        {
            // if not authority, continue
            if(System::authority_available() && !Admin::user()->hasPermissionTable(array_get($table, 'table_name'), Define::AUTHORITY_VALUES_AVAILABLE_ACCESS_CUSTOM_VALUE)){
                continue;
            }

            // search using column
            $result = collect($table['custom_columns'])->first(function($custom_column){
                // this column is search_enabled, add array.
                if(!boolval(array_get($custom_column['options'], 'search_enabled'))){
                    return false;
                }

                // only set $value_table (for relation search)
                if(isset($value_table)){
                    // get only table as below
                    // - $table equal self target table
                    // - $table is child table for $value_table
                    // $custom_column->column_type is "select_target_table" and $custom_column->options->select_target_table is target_table

                    $result = false;
                    if($table['id'] == $value_table->id){
                        $result = true;
                    }
                    
                    if(!$result){
                        // get custom relation.
                        if(!is_null(CustomRelation
                            ::where('parent_custom_table_id', $value_table->id)
                            ->where('child_custom_table_id', $table['id'])
                            ->first())){
                                $result = true;
                            }
                    }

                    if(!$result){
                        return false;
                    }
                }

                return true;
            });
            if(!is_null($result)){
                array_push($results, [
                    'id' => array_get($table, 'id'),
                    'table_name' => array_get($table, 'table_name'),
                    'table_view_name' => array_get($table, 'table_view_name'),
                    'icon' => array_get($table, 'icon')
                ]);
            }
        }

        return $results;
    }

    /**
     * Get search results using query
     */
    public function getList(Request $request){
        $q = $request->input('query');
        $table = CustomTable::findByName($request->input('table_name'), true)->toArray();
        // Get search enabled columns.
        $search_columns = getSearchEnabledColumns(array_get($table, 'table_name'));

        if(count($search_columns) == 0){
            return ['table_name' => array_get($table, 'table_name'), "html" => exmtrans('search.no_result')];
        }

        // search all data using index --------------------------------------------------
        $data = $this->searchValue($q, $table, $search_columns, 5);
        
        // Get result HTML.
        if(count($data) == 0){
            return ['table_name' => array_get($table, 'table_name'), "html" => exmtrans('search.no_result')];
        }

        $headers = array_column($search_columns, 'column_view_name');
        $rows = [];
        foreach ($data as $d)
        {
            // Add columns
            $columns = [];
            foreach (array_column($search_columns, 'column_name') as $c)
            {
                array_push($columns, array_get($d, "value.$c"));
            }
            // Add links
            $link = '<a href="'.admin_base_path('data/'.array_get($table, 'table_name').'/'.array_get($d, 'id')).'" style="margin-right:3px;"><i class="fa fa-eye"></i></a>';
            $link .= '<a href="'.admin_base_path('data/'.array_get($table, 'table_name').'/'.array_get($d, 'id').'/edit').'"><i class="fa fa-edit"></i></a>';
            array_push($columns, $link);

            array_push($rows, $columns);
        }
        return ['table_name' => array_get($table, 'table_name'), "html" => (new Table($headers, $rows))->render()];
    }
    
    // For relation search  --------------------------------------------------    
    /**
     * Get relation search result page. this function is called when user select suggest.
     * @param Request $request
     * @return Content
     */
    protected function getRelationSearch(Request $request, Content $content){
        $this->AdminContent($content);

            $content->header(exmtrans('search.header_relation'));
            $content->description(exmtrans('search.description_relation'));

            // get seleted name
            $table = CustomTable::findByName($request->input('table_name'));
            $label = getLabelColumn($table);
            $model = getModelName($table)::find($request->input('value_id'));
            $value = getValue($model, $label, true);
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
    public function getRelationList(Request $request){
        // value_id is the id user selected.
        $value_id = $request->input('value_id');
        // value_table is the table user selected.
        $value_table = CustomTable::findByName($request->input('value_table_name'), true);
        /// $search_table is the table for search. it's ex. select_table, relation, ...
        $search_table = CustomTable::findByName($request->input('search_table_name'), true);
        $search_type = $request->input('search_type');

        // Get search enabled columns.
        $search_columns = getSearchEnabledColumns($search_table->table_name);

        switch($search_type){
            // self table
            case 'self':
                $data = [getModelName($search_table)::find($value_id)->toArray()];
                break;   
            // select_table(select box)             
            case 'select_table':
                // テーブル「カスタム列」の、列「options.search_target_table」が、value_idであるレコード一覧を取得する
                // Retrieve the record list whose value is "value_id" in the column "options.search_target_table" of the table "custom column"
                $selecttable_columns = CustomColumn::where('column_type', 'select_table')
                    ->where('options->search_target_table', $value_table->id)
                    ->get();

                if(count($search_columns) == 0){
                    return ['table_name' => array_get($search_table, 'table_name'), "html" => exmtrans('search.no_result')];
                }

                $query = getModelName(array_get($search_table, 'table_name'))::query();
                $data = $this->searchValue($query, $search_table, $search_columns, 5);
                break;
            
            // one_to_many
            case 'one_to_many':
                $data = getModelName($search_table)::where('parent_id', $value_id)->take(5)->get()->toArray();
                break;
            // many_to_many
            case 'many_to_many':
                $relation_name = getRelationNamebyObjs($value_table, $search_table);

                // get search_table value
                // where: parent_id is value_id
                $data = getModelName($search_table)
                    ::join($relation_name, "$relation_name.child_id", getDBTableName($search_table).".id")
                    ->where("$relation_name.parent_id", $value_id)
                    ->take(5)
                    ->get([getDBTableName($search_table).".*"])->toArray();
                // if(isset($data)){
                //     $data = $data->map(function($value, $key) use($relation_name){
                //         return $value->{$relation_name}->value;
                //     })->toArray();
                // }
                break;            
        }

        // Get search result HTML.
        if(count($data) == 0){
            return ['table_name' => array_get($table, 'table_name'), "html" => exmtrans('search.no_result')];
        }

        $headers = array_column($search_columns, 'column_view_name');
        $rows = [];
        foreach ($data as $d)
        {
            // Get items
            $columns = [];
            foreach (array_column($search_columns, 'column_name') as $c)
            {
                array_push($columns, array_get($d, "value.$c"));
            }
            // Add link
            $link = '<a href="'.admin_base_path('data/'.array_get($search_table, 'table_name').'/'.array_get($d, 'id')).'" style="margin-right:3px;"><i class="fa fa-eye"></i></a>';
            $link .= '<a href="'.admin_base_path('data/'.array_get($search_table, 'table_name').'/'.array_get($d, 'id').'/edit').'"><i class="fa fa-edit"></i></a>';
            array_push($columns, $link);

            array_push($rows, $columns);
        }
        return ['table_name' => array_get($search_table, 'table_name'), "html" => (new Table($headers, $rows))->render()];
    }

    /**
     * Get Search enabled relation table list.
     * It contains search_type(self, select_table, one_to_many, many_to_many)
     */
    protected function getSearchTargetRelationTable($value_table){
        $results = [];

        // 1. For self-table
        array_push($results, [
            'id' => array_get($value_table, 'id'),
            'table_name' => array_get($value_table, 'table_name'),
            'table_view_name' => array_get($value_table, 'table_view_name'),
            'icon' => array_get($value_table, 'icon'),
            'search_type' => 'self',
        ]);

        // 2. Get tables as "select_table". They contains these columns matching them.
        // * table_column > options > search_enabled is true.
        // * table_column > options > search_target_table is table id user selected.
        $tables = CustomTable
        ::whereHas('custom_columns', function($query) use($value_table){
            $query->where('options->search_enabled', true)
            ->where('options->search_target_table', $value_table->id);
        })
        ->where('search_enabled', true)
        ->get()
        ->toArray();

        foreach ($tables as $table) {
            // if not authority, continue
            if (!Admin::user()->hasPermissionTable(array_get($table, 'table_name'), Define::AUTHORITY_VALUES_AVAILABLE_ACCESS_CUSTOM_VALUE)) {
                continue;
            }

            array_push($results, [
                'id' => array_get($table, 'id'),
                'table_name' => array_get($table, 'table_name'),
                'table_view_name' => array_get($table, 'table_view_name'),
                'icon' => array_get($table, 'icon'),
                'search_type' => 'select_table',
            ]);
        }

        // 3. Get relation tables.
        // * table "custom_relations" and column "parent_custom_table_id" is $value_table->id.
        $tables = CustomTable
        ::join('custom_relations', 'custom_tables.id', 'custom_relations.parent_custom_table_id')
        ->join('custom_tables AS child_custom_tables', 'child_custom_tables.id', 'custom_relations.child_custom_table_id')
            ->whereHas('custom_relations', function($query) use($value_table){
                $query->where('parent_custom_table_id', $value_table->id);
            })->get(['child_custom_tables.*', 'custom_relations.relation_type'])->toArray();
        foreach ($tables as $table) {
            // if not authority, continue
            if (!Admin::user()->hasPermissionTable(array_get($table, 'table_name'), Define::AUTHORITY_VALUES_AVAILABLE_ACCESS_CUSTOM_VALUE)) {
                continue;
            }

            array_push($results, [
                'id' => array_get($table, 'id'),
                'table_name' => array_get($table, 'table_name'),
                'table_view_name' => array_get($table, 'table_view_name'),
                'icon' => array_get($table, 'icon'),
                'search_type' => array_get($table, 'relation_type'),
            ]);
        }

        return $results;
    }

    /**
     * search value using search-enabld column
     */
    protected function searchValue($q, $table, $search_columns, $max_count){
        $data = [];
        foreach ($search_columns as $search_column)
        {
            // get data
            $foodata = getModelName(array_get($table, 'table_name'))
                ::where(getColumnName($search_column), 'LIKE', $q.'%')
                ->take($max_count - count($data))
                ->get()->toArray();
            
            foreach($foodata as $foo){
                if(count($data) >= $max_count){break;}

                // if exists id, continue
                if(!is_null(collect($data)->first(function($value, $key) use($foo){
                    return array_get($value, 'id') == array_get($foo, 'id');
                }))){
                    continue;
                }

                $data[] = $foo;
            }
        }

        return $data;
    }
}