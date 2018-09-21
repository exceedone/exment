<?php

namespace Exceedone\Exment\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Exporters\AbstractExporter;

class ExmentExporter extends AbstractExporter
{
    const SCOPE_ALL = 'all';
    const SCOPE_TEMPLATE = 'temp';
    const SCOPE_CURRENT_PAGE = 'page';
    const SCOPE_SELECTED_ROWS = 'selected';

    public static $queryName = '_export_';

    protected $scope;
    protected $table;
    protected $search_enabled_columns;

    /**
     * Create a new exporter instance.
     *
     * @param $grid
     */
    public function __construct(Grid $grid = null, $table = null, $search_enabled_columns = null)
    {
        if ($grid) {
            $this->setGrid($grid);
        }
        if ($table) {
            $this->table = $table;
        }
        if ($search_enabled_columns) {
            $this->search_enabled_columns = $search_enabled_columns;
        }
    }

    /**
     */
    public function export()
    {
        $filename = $this->table->table_name.date('YmdHis').'.csv';
        $res_headers = [
            'Content-Encoding'    => 'UTF-8',
            'Content-Type'        => 'text/csv;charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $titles = [];

        // get header info
        list($firstColumns, $custom_columns, $lastColumns) = $this->getColumnDefines();
        // get header and body
        $headers = $this->getHeaders($firstColumns, $custom_columns, $lastColumns);

        // if only template, output only headers
        $isTepmlate = boolval(\Request::capture()->query('temp'));
        if($isTepmlate){
            $bodies = [];
        }else{
            $bodies = $this->getBodies($this->getRecords(), $firstColumns, $custom_columns, $lastColumns);
        }
        // get output items
        $outputs = array_merge([$headers], $bodies);

        response()->stream(function () use($outputs){
            $handle = fopen('php://output', 'w');
            foreach ($outputs as $output) {
                fputcsv($handle, $output);
            }
            // Close the output stream
            fclose($handle);
        }, 200, $res_headers)->send();

        exit;
    }

    /**
     * get column info
     * @return mixed list. first:fixed column id, suuid, parent_id, parent_type. second: custom columns: third: created_at, updated_at, deleted_at
     */
    protected function getColumnDefines(){
        $firstColumns = ['id','suuid','parent_id','parent_type'];
        $lastColumns = ['created_at','updated_at','deleted_at'];

        // get custom columns
        $custom_columns = $this->table->custom_columns()->pluck('column_name')->toArray();
        return [$firstColumns, $custom_columns, $lastColumns];
    }

    /**
     * get target chunk records
     */
    protected function getRecords(){
        // get target records
        //return target 
        $this->chunk(function ($data) use(&$records){
            $records = $data;
        }) ?? [];

        return $records;
    }

    /**
     * get export headers
     */
    protected function getHeaders($firstColumns, $custom_columns, $lastColumns){
        $mapped_custom_columns = collect($custom_columns)->map(function($value){
            return "value.".$value;
        })->toArray();
        return array_merge($firstColumns, $mapped_custom_columns, $lastColumns);
    }

    /**
     * get export bodies
     */
    protected function getBodies($records, $firstColumns, $custom_columns, $lastColumns){
        $bodies = [];
        foreach($records as $record){
            $body_items = [];
            // add items
            $body_items = array_merge($body_items, $this->getBodyItems($record, $firstColumns));
            $body_items = array_merge($body_items, $this->getBodyItems($record, $custom_columns, "value."));
            $body_items = array_merge($body_items, $this->getBodyItems($record, $lastColumns));

            $bodies[] = $body_items;
        }
        return $bodies;
    }

    protected function getBodyItems($record, $columns, $array_header_key = null){
        $body_items = [];
        foreach($columns as $column){
            // get key. 
            $key = (isset($array_header_key) ? $array_header_key : "").$column;
            $body_items[] = array_get($record, $key);
        }
        return $body_items;
    }
}
