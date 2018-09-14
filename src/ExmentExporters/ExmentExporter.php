<?php

namespace Exceedone\Exment\ExmentExporters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class ExmentExporter extends ExmentAbstractExporter
{
    const SCOPE_ALL = 'all';
    const SCOPE_TEMPLATE = 'temp';
    const SCOPE_CURRENT_PAGE = 'page';
    const SCOPE_SELECTED_ROWS = 'selected';

    public static $queryName = '_export_';

    /**
     * @param $table
     * @param $search_enabled_columns
     * @return mixed|void
     */
    public function export($table, $search_enabled_columns, $get_template)
    {
        $filename = $table->table_name.date('YmdHis').'.csv';
        $headers = [
            'Content-Encoding'    => 'UTF-8',
            'Content-Type'        => 'text/csv;charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        response()->stream(function () use ($table, $get_template){
            $handle = fopen('php://output', 'w');

            $titles = [];

            if(DB::table('exm__'.$table->suuid)->count() > 0){
                $this->chunk(function ($records) use ($handle, &$titles, $get_template) {
                    if (empty($titles)) {
//                        Get header from record
                        $titles = $this->getHeaderRowFromRecords($records);

//                        Add CSV headers
                        fputcsv($handle, $titles);
                    }

                    if(!$get_template){
                        foreach ($records as $record) {
                            //Add CSV Data
                            fputcsv($handle, $this->getFormattedRecord($record));
                        }
                    }
                });
            } else {
//                if have no data, get only header
                $titles = $this->getOnlyHeader($table);
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

    /**
     * @param string $scope
     * @param null $args
     * @return array
     */
    public static function formatExportQuery($scope = '', $args = null)
    {
        $query = '';

        if ($scope == static::SCOPE_ALL) {
            $query = 'all';
        }

        if ($scope == static::SCOPE_TEMPLATE) {
            $query = 'temp';
        }

        if ($scope == static::SCOPE_CURRENT_PAGE) {
            $query = "page:$args";
        }

        if ($scope == static::SCOPE_SELECTED_ROWS) {
            $query = "selected:$args";
        }

        return [static::$queryName => $query];
    }

    /**
     * @param int $scope
     * @param null $path
     * @return $this|array
     */
    public function resource($scope = 1, $path = null)
    {
        if (!empty($path)) {
            $this->resourcePath = $path;

            return $this;
        }

        if (!empty($this->resourcePath)) {
            return $this->resourcePath;
        }

        return $this->formatExportQuery($scope,$path);
    }

    /**
     * @param $driver
     * @return ExmentAbstractExporter|ExmentExporter
     */
    public function resolve($driver)
    {
        if ($driver instanceof ExmentAbstractExporter) {
            return $driver->setGrid($this->grid);
        }

        return $this->getExporter($driver);
    }

    /**
     * @param $driver
     * @return ExmentExporter
     */
    protected function getExporter($driver)
    {
        if (!array_key_exists($driver, static::$drivers)) {
            return $this->getDefaultExporter();
        }

        return new static::$drivers[$driver]($this->grid);
    }

    /**
     * @return ExmentExporter
     */
    public function getDefaultExporter()
    {
        return new ExmentExporter($this->grid);
    }
}
