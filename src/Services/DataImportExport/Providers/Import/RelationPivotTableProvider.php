<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

/**
 * Relation Pivot table (n:n)
 */
class RelationPivotTableProvider extends ProviderBase
{
    protected $relation;

    public function __construct($args = [])
    {
        $this->relation = array_get($args, 'relation');
    }

    /**
     * get pivot data for n:n
     */
    public function getDataObject($data, $options = [])
    {
        $results = [];
        $headers = [];
        $row_count = 0;

        foreach ($data as $key => $value) {
            // get header if $key == 0
            if ($key == 0) {
                $headers = $value;
                continue;
            }
            // continue if $key == 1
            elseif ($key == 1) {
                continue;
            }

            $row_count++;
            if (!$this->isReadRow($row_count, $options)) {
                continue;
            }

            // combine value
            $null_merge_array = collect(range(1, count($headers)))->map(function () {
                return null;
            })->toArray();
            $value = $value + $null_merge_array;
            $value_custom = array_combine($headers, $value);
            $delete = boolval(array_get($value_custom, 'delete')) || boolval(array_get($value_custom, 'delete_flg'));

            $value_custom = array_only($value_custom, ['parent_id', 'child_id']);

            $results[] = ['data' => $value_custom, 'delete' => $delete];
        }

        return $results;
    }

    /**
     * validate imported all data.
     * @param mixed $dataObjects
     * @return array
     */
    public function validateImportData($dataObjects)
    {
        return [$dataObjects, null];
    }

    /**
     * import data (n:n relation)
     */
    public function importdata($dataPivot)
    {
        $data = array_get($dataPivot, 'data');
        $delete = array_get($dataPivot, 'delete');

        // get database name
        $table_name = $this->relation->getRelationName();

        // get target id(cannot use Eloquent because not define)
        $id = \DB::table($table_name)
            ->where('parent_id', array_get($data, 'parent_id'))
            ->where('child_id', array_get($data, 'child_id'))
            ->first()->id ?? null;

        // if delete
        if (isset($id) && $delete) {
            \DB::table($table_name)->where('id', $id)->delete();
        } elseif (!isset($id)) {
            \DB::table($table_name)->insert($data);
        }
    }
}
