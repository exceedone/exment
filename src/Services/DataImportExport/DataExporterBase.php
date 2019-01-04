<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Exporters\AbstractExporter;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\RelationType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

abstract class DataExporterBase extends AbstractExporter
{
    const SCOPE_ALL = 'all';
    const SCOPE_TEMPLATE = 'temp';
    const SCOPE_CURRENT_PAGE = 'page';
    const SCOPE_SELECTED_ROWS = 'selected';

    public static $queryName = '_export_';

    protected $scope;
    protected $custom_table;
    protected $search_enabled_columns;
    protected $relations;

    /**
     * Whether this output is as template
     */
    protected $template = false;

    /**
     * Create a new exporter instance.
     *
     * @param $grid
     */
    public function __construct(Grid $grid = null, $custom_table = null, $search_enabled_columns = null)
    {
        set_time_limit(240);

        if ($grid) {
            $this->setGrid($grid);
        }
        if ($custom_table) {
            $this->custom_table = $custom_table;
            // get relation
            // if count > 0, output relations too.
            $this->relations = CustomRelation::getRelationsByParent($this->custom_table);
        }
        if ($search_enabled_columns) {
            $this->search_enabled_columns = $search_enabled_columns;
        }

        $this->template = boolval(app('request')->query('temp'));
    }

    /**
     * execute export
     */
    public function export()
    {
        $datalist = [
            ['name' => $this->custom_table->table_name, 'outputs' => $this->getDataTable($this->custom_table)]
        ];
        foreach ($this->relations as $relation) {
            $name = $relation->getSheetName();
            $datalist[] = ['name' => $name, 'outputs' => $this->getDataTable($relation->child_custom_table, $relation)];
        }

        $files = $this->createFile($datalist);
        $filename = $this->getFileName();
        
        $response = $this->createResponse($files);
        $response->send();
        exit;
    }

    /**
     * get export table. contains header and body.
     */
    protected function getDataTable($target_table, $relation = null)
    {
        // get header info
        $columnDefines = $this->getColumnDefines($target_table, $relation);
        // get header and body
        $headers = $this->getHeaders($columnDefines, $relation);

        // if only template, output only headers
        if ($this->template) {
            $bodies = [];
        } else {
            $bodies = $this->getBodies($this->getRecords($target_table, $relation), $columnDefines, $relation);
        }
        // get output items
        $outputs = array_merge($headers, $bodies);

        return $outputs;
    }

    /**
     * get column info
     * @return mixed list. first:fixed column id, suuid, parent_id, parent_type. second: custom columns: third: created_at, updated_at, deleted_at
     */
    protected function getColumnDefines($target_table, $relation = null)
    {
        if (isset($relation) && $relation->relation_type == RelationType::MANY_TO_MANY) {
            $columnDefines = ['parent_id','child_id'];
            if($this->template){
                $columnDefines[] = 'delete_flg';
            }
            return $columnDefines;
        }

        $firstColumns = ['id','suuid','parent_id','parent_type'];
        $lastColumns = ['created_at','updated_at','deleted_at'];

        // get custom columns
        $custom_columns = $target_table->custom_columns()->get(['column_name', 'column_view_name'])->toArray();
        return [$firstColumns, $custom_columns, $lastColumns];
    }

    /**
     * get target chunk records
     */
    protected function getRecords($target_table, $relation = null)
    {
        // get base records
        if (isset($relation)) {
            $relation_name = $relation->getRelationName();
            $this->grid->model()->with($relation_name);
        } else {
            $relation_name = null;
        }
        $this->chunk(function ($data) use (&$records, $relation_name) {
            if (!isset($records)) {
                $records = new Collection;
            }
            if (isset($relation_name)) {
                $datalist = $data->map(function ($d) use ($relation_name) {
                    return $d->{$relation_name};
                });
                foreach ($datalist as $d) {
                    $records = $records->merge($d);
                }
            } else {
                $records = $records->merge($data);
            }
        }) ?? [];

        return $records;
    }

    /**
     * get export headers
     * contains custom column name, column view name
     */
    protected function getHeaders($columnDefines, $relation = null)
    {
        // create 2 rows.
        $rows = [];

        if (isset($relation) && $relation->relation_type == RelationType::MANY_TO_MANY) {
            $rows[] = $columnDefines;
            // column_view_names
            $column_view_names =  [
                $relation->parent_custom_table->table_view_name . '_'. exmtrans("common.id"),
                $relation->child_custom_table->table_view_name . '_'. exmtrans("common.id"),
            ];
            if($this->template){
                $column_view_names[] = trans('admin.delete');
            }
            $rows[] = $column_view_names;
        } else {
            list($firstColumns, $custom_columns, $lastColumns) = $columnDefines;
            // 1st row, column name
            $rows[] = array_merge(
                $firstColumns,
                collect($custom_columns)->map(function ($value) {
                    return "value.".array_get($value, 'column_name');
                })->toArray(),
                $lastColumns
            );

            // 2st row, column view name
            $rows[] = array_merge(
                collect($firstColumns)->map(function ($value) {
                    return exmtrans("common.$value");
                })->toArray(),
                collect($custom_columns)->map(function ($value) {
                    return array_get($value, 'column_view_name');
                })->toArray(),
                collect($lastColumns)->map(function ($value) {
                    return exmtrans("common.$value");
                })->toArray()
            );
        }
        return $rows;
    }

    /**
     * get export bodies
     */
    protected function getBodies($records, $columnDefines, $relation = null)
    {
        $bodies = [];
        if (isset($relation) && $relation->relation_type == RelationType::MANY_TO_MANY) {
            foreach ($records as $record) {
                $body_items = [];
                // add items
                $body_items = array_merge($body_items, $this->getBodyItems($record, $columnDefines, "pivot."));
                $bodies[] = $body_items;
            }
        } else {
            list($firstColumns, $custom_columns, $lastColumns) = $columnDefines;
            // convert $custom_columns to pluck column_name array
            $custom_column_names = collect($custom_columns)->pluck('column_name')->toArray();
            foreach ($records as $record) {
                $body_items = [];
                // add items
                $body_items = array_merge($body_items, $this->getBodyItems($record, $firstColumns));
                $body_items = array_merge($body_items, $this->getBodyItems($record, $custom_column_names, "value."));
                $body_items = array_merge($body_items, $this->getBodyItems($record, $lastColumns));

                $bodies[] = $body_items;
            }
        }
        return $bodies;
    }

    protected function getBodyItems($record, $columns, $array_header_key = null)
    {
        $body_items = [];
        foreach ($columns as $column) {
            // get key.
            $key = (isset($array_header_key) ? $array_header_key : "").$column;
            $value = array_get($record, $key);
            if (is_array($value)) {
                $value = implode(",", $value);
            }
            $body_items[] = $value;
        }
        return $body_items;
    }

    /**
     * create file
     * 1 sheet - 1 table data
     */
    protected function createFile($datalist)
    {
        // define writers. if zip, set as array.
        $files = [];
        // create excel
        $spreadsheet = new Spreadsheet();
        foreach ($datalist as $index => $data) {
            $sheet_name = array_get($data, 'name');
            $outputs = array_get($data, 'outputs');

            $sheet = new Worksheet($spreadsheet, $sheet_name);
            $sheet->fromArray($outputs, null, 'A1', false, false);

            // set autosize
            if (count($outputs) > 0) {
                $counts = count($outputs[0]);
                for ($i = 0; $i < $counts; $i++) {
                    $sheet->getColumnDimension(getCellAlphabet($i + 1))->setAutoSize(true);
                }
            }

            if($this->isOutputAsZip()){
                $spreadsheet->addSheet($sheet);
                $spreadsheet->removeSheetByIndex(0);
                $files[] = [
                    'name' => $sheet_name,
                    'writer' => $this->createWriter($spreadsheet)
                ];
                $spreadsheet = new Spreadsheet();
            }else{
                $spreadsheet->addSheet($sheet);
            }
        }

        if(!$this->isOutputAsZip()){
            $spreadsheet->removeSheetByIndex(0);
            $files[] = [
                'name' => $sheet_name,
                'writer' => $this->createWriter($spreadsheet)
            ];
        }
        return $files;
    }

    protected function getDefaultHeaders(){
        $filename = $this->getFileName();
        return [
            'Content-Type'        => 'application/force-download',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];
    }

    /**
     * get exporter model
     */
    public static function getModel(Grid $grid = null, $table = null, $search_enabled_columns = null)
    {
        $format = app('request')->input('format');
        switch ($format) {
            case 'excel':
                return new ExcelExporter($grid, $table, $search_enabled_columns);
            default:
                return new CsvExporter($grid, $table, $search_enabled_columns);
        }
    }
 
}
