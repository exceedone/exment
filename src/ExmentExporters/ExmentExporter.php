<?php

namespace Exceedone\Exment\ExmentExporters;

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
        $headers = [
            'Content-Encoding'    => 'UTF-8',
            'Content-Type'        => 'text/csv;charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        response()->stream(function (){
            $handle = fopen('php://output', 'w');

            $titles = [];

            // if has column data, output header band body
            if(getModelName($this->table)::count() > 0){
                $this->chunk(function ($records) use ($handle, &$titles) {
                    if (empty($titles)) {
//                      Get header from record
                        $titles = $this->getHeaderRowFromRecords($records);

//                        Add CSV headers
                        fputcsv($handle, $titles);
                    }

                    // get_template
                    $get_template = boolval(\Request::capture()->query('temp'));
                    // is not template, output fields
                    if(!$get_template){
                        foreach ($records as $record) {
                            //Add CSV Data
                            fputcsv($handle, $this->getFormattedRecord($record));
                        }
                    }
                });
            } else {
//                if have no data, get only header
                $titles = $this->getOnlyHeader($this->table);
                fputcsv($handle, $titles);
            }

            // Close the output stream
            fclose($handle);
        }, 200, $headers)->send();

        exit;
    }

    /**
     * @param $table
     * @return array
     */
    public function getOnlyHeader($table){
        $titleRespose = [];
        $columnName = \Schema::getColumnListing('exm__'.$table->suuid);
        foreach($columnName as $key => $value){
            if((strpos($value, 'id') !== false && strpos($value, 'parent') === false) || strpos($value, 'suuid') !== false){
                $titleRespose[$key] = $value;
            }
            if(strpos($value, 'column_') !== false){
                $columnSUUID = str_replace('column_', '', $value);
                array_push($titleRespose, 'value.'.$this->getDisplayColumnName($columnSUUID));
            }
        }
        array_push($titleRespose, 'created_at');
        array_push($titleRespose, 'updated_at');
        return array_dot($titleRespose);
    }

    /**
     * @param Collection $records
     *
     * @return array
     */
    public function getHeaderRowFromRecords(Collection $records): array
    {
        $titles = collect(array_dot($records->first()->toArray()))->keys()->map(
            function ($key){
                $key = str_replace('.', ' ', $key);
                return Str::ucfirst($key);
            }
        );

        $titleRespose = [];
        foreach($titles as $key => $value){
            if((strpos($value, 'Id') !== false && strpos($value, 'Parent') === false) || strpos($value, 'Suuid') !== false){
                $titleRespose[$key] = strtolower($value);
            }
            if(strpos($value, 'Value') !== false){
                $columnName = str_replace('Value ', 'value.', $value);
                array_push($titleRespose, $columnName);
            }
        }
        array_push($titleRespose, 'created_at');
        array_push($titleRespose, 'updated_at');
        return array_dot($titleRespose);
    }

    /**
     * @param Model $record
     *
     * @return array
     */
    public function getFormattedRecord(Model $record)
    {
        $recordTemps = $record->getAttributes();
        $recordReturn = [];
        array_push($recordReturn, $recordTemps['id']);
        array_push($recordReturn, $recordTemps['suuid']);
        foreach($recordTemps as $key => $value){
            if(strpos($key, 'column_') !== false){
                array_push($recordReturn, $recordTemps[$key]);
            }
        }

        array_push($recordReturn, $recordTemps['created_at']);
        array_push($recordReturn, $recordTemps['updated_at']);

        return array_dot($recordReturn);
    }

    /**
     * @param $columnSUUID
     * @return mixed
     */
    public function getDisplayColumnName($columnSUUID){
        $columnName = DB::table('custom_columns')
            ->where('suuid', '=' ,$columnSUUID)
            ->first();
        return $columnName->column_name;
    }
}
