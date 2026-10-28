<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Carbon\Carbon;
use Exceedone\Exment\Services\DataImportExport\DataImportExportService;
use Exceedone\Exment\Enums\ValidateCalledType;

class DefaultTableProvider extends ProviderBase
{
    // @phpstan-ignore-next-line
    protected $custom_table;

    // @phpstan-ignore-next-line
    protected $custom_columns;

    // @phpstan-ignore-next-line
    protected $primary_key;

    // @phpstan-ignore-next-line
    protected $filter;

    /**
     * Select Table not found errors
     *
     * @var array
     */
    // @phpstan-ignore-next-line
    protected $selectTableNotFounds;

    // @phpstan-ignore-next-line
    public function __construct($args = [])
    {
        $this->custom_table = array_get($args, 'custom_table');

        $this->custom_columns = $this->custom_table->custom_columns_cache;

        $this->primary_key = array_get($args, 'primary_key', 'id');

        $this->filter = array_get($args, 'filter');

        $this->selectTableNotFounds = [];
    }

    /**
     * get data and object.
     * set matched model data
     */
    // @phpstan-ignore-next-line
    public function getDataObject($data, $options = [])
    {
        $headers = [];
        $value_customs = [];
        $primary_values = [];
        $row_count = 0;

        foreach ($data as $line_no => $value) {
            // get header if $line_no == 0
            if ($line_no == 0) {
                $headers = $value;
                continue;
            }
            // continue if $line_no == 1
            elseif ($line_no == 1) {
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

            // filter data
            if ($this->filterData($value_custom)) {
                continue;
            }

            $value_customs[$line_no] = $value_custom;

            // get primary values
            $primary_values[] = array_get($value_custom, $this->primary_key);
        }

        // get all custom value for performance
        $models = $this->custom_table->getMatchedCustomValues($primary_values, $this->primary_key);

        // Recheck unmatched IDs including soft-deleted records, which still occupy their primary keys.
        // These records are used only to report conflicts, not to restore or update them.
        $conflicting_models = [];
        if ($this->primary_key === 'id') {
            $unmatched_ids = array_filter($primary_values, function ($id) use ($models) {
                return !is_nullorempty($id) && !isset($models[strval($id)]);
            });
            $conflicting_models = $this->custom_table->getMatchedCustomValues($unmatched_ids, 'id', true);
        }

        // set all select table's value
        $this->custom_table->setSelectTableValues(collect($value_customs));

        $results = [];
        $seen_ids = [];
        foreach ($value_customs as $line_no => $value_custom) {
            $options['datalist'] = $value_customs;
            ///// convert data first.
            // @phpstan-ignore-next-line
            $value_custom = $this->dataProcessingFirst($value_custom, $line_no, $options);

            // get model
            $modelName = getModelName($this->custom_table);
            // select $model using primary key and value
            $primary_value = array_get($value_custom, $this->primary_key);
            // if not exists, new instance
            if (is_nullorempty($primary_value)) {
                $model = new $modelName();
            }
            // if exists, firstOrNew
            else {
                // get model from models
                $model = array_get($models, strval($primary_value));
                if (!isset($model)) {
                    $model = new $modelName();
                }
            }
            if (!isset($model)) {
                continue;
            }
            $model->saved_notify(false);

            $row_errors = [];
            if ($this->primary_key === 'id' && !is_nullorempty($primary_value)) {
                $id = strval($primary_value);
                // Only new records are checked: repeating an ID to be inserted always fails with a duplicate key.
                // Repeated IDs of existing records keep their previous behavior.
                // Keep the first data row number so repeated IDs can point back to their original row.
                if (!$model->exists) {
                    if (isset($seen_ids[$id])) {
                        $row_errors[] = exmtrans('custom_value.import.duplicate_id_in_file', [
                            'id' => $id,
                            'row' => $seen_ids[$id],
                        ]);
                    } else {
                        $seen_ids[$id] = $line_no - 1;
                    }
                }

                // Distinguish deleted records from other existing records that were not matched for update.
                if (isset($conflicting_models[$id])) {
                    $key = $conflicting_models[$id]->trashed() ? 'deleted_id_conflict' : 'id_conflict';
                    $row_errors[] = exmtrans('custom_value.import.'.$key, ['id' => $id]);
                }
            }

            // Carry conflict errors and the data row number (header rows excluded) forward for validation before saving.
            $results[] = [
                'data' => $value_custom,
                'model' => $model,
                'row_number' => $line_no - 1,
                'import_errors' => array_map(function ($message) use ($line_no) {
                    return sprintf(
                        exmtrans('custom_value.import.import_error_format_sheet'),
                        $this->custom_table->table_name,
                        $line_no - 1,
                        $message
                    );
                }, $row_errors),
            ];
        }

        return $results;
    }

    /**
     * validate imported all data.
     * @param mixed $dataObjects
     * @return array
     */
    // @phpstan-ignore-next-line
    public function validateImportData($dataObjects)
    {
        if (count($this->selectTableNotFounds) > 0) {
            return [[], $this->selectTableNotFounds];
        }

        ///// get all table columns
        $validate_columns = $this->custom_table->custom_columns;

        $error_data = [];
        $success_data = [];
        foreach ($dataObjects as $line_no => $value) {
            if (!empty($value['import_errors'])) {
                $error_data = array_merge($error_data, $value['import_errors']);
                continue;
            }
            $check = $this->validateDataRow($line_no, $value, $validate_columns, $dataObjects);
            if ($check === true) {
                $success_data[] = $value;
            } else {
                $error_data = array_merge($error_data, $check);
            }
        }

        return [$success_data, $error_data];
    }

    /**
     * validate data row
     *
     * @param int $line_no
     * @param array $dataAndModel
     * @param array $validate_columns
     * @param array $dataObjects
     * @return array|true
     */
    // @phpstan-ignore-next-line
    public function validateDataRow($line_no, $dataAndModel, $validate_columns, $dataObjects)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');

        $errors = [];

        $validateRow = true;
        // check create or update check
        // *Only check user object for batch
        if (isset($model) && !is_nullorempty(\Exment::user())) {
            if (!$model->exists && ($code = $this->custom_table->enableCreate()) !== true) {
                $validateRow = false;
            } elseif (array_key_value_exists('deleted_at', $data) && ($code = $model->enableDelete()) !== true) {
                $validateRow = false;
            } elseif ($model->exists && ($code = $model->enableEdit()) !== true) {
                $validateRow = false;
            }
        }

        if (!$validateRow && isset($code) && $code !== true) {
            $errors[] = sprintf(exmtrans('custom_value.import.import_error_format'), ($line_no+1), $code->getMessage());
        }

        list($uniqueCheckSiblings, $uniqueCheckIgnoreIds) = $this->getUniqueCheckParams($line_no, $dataObjects);

        // execute validation
        $validator = $this->custom_table->validateValue(array_dot_reverse($data), $model, [
            'systemColumn' => true,
            'column_name_prefix' => 'value.',
            'appendKeyName' => true,
            'checkCustomValueExists' => false,
            'validateLock' => false,
            'uniqueCheckSiblings' => $uniqueCheckSiblings,
            'uniqueCheckIgnoreIds' => $uniqueCheckIgnoreIds,
            'calledType' => ValidateCalledType::IMPORT,
        ]);

        if ($validator->fails()) {
            // create error message
            foreach ($validator->getMessages() as $message) {
                $errors[] = sprintf(exmtrans('custom_value.import.import_error_format'), ($line_no+1), implode(',', $message));
            }
            // return $errors;
        }

        if (!is_nullorempty($errors)) {
            return $errors;
        }
        return true;
    }

    /**
     * @param int $current_no
     * @param array $dataObjects
     * @return array
     */
    // @phpstan-ignore-next-line
    protected function getUniqueCheckParams($current_no, $dataObjects)
    {
        $siblings = [];
        $ignoreIds = [];

        foreach ($dataObjects as $line_no => $value) {
            if ($line_no != $current_no) {
                $siblings[] = array_get($value, 'data');
            }
            $model = array_get($value, 'model');
            if (isset($model) && !is_null($id = array_get($model, 'id'))) {
                $ignoreIds[] =  $id;
            }
        }
        return [$siblings, $ignoreIds];
    }

    /**
     *
     *
     * @param array $data
     * @return array
     */
    // @phpstan-ignore-next-line
    public function dataProcessing($data)
    {
        $data_custom = [];
        $value_arr = [];
        foreach ($data as $key => $value) {
            if (strpos($key, "value.") !== false) {
                $new_key = str_replace('value.', '', $key);
                $value_arr[$new_key] = is_nullorempty($value) ? null : preg_replace("/\\\\r\\\\n|\\\\r|\\\\n/", "\n", $value);
            } else {
                $data_custom[$key] = is_nullorempty($value) ? null : $value;
            }
        }
        $data_custom['value'] = $value_arr;
        return $data_custom;
    }


    /**
     * Data processing before getting model using imported data
     *
     * @param array $data
     * @param int $line_no
     * @param array $options
     * @return array
     */
    // @phpstan-ignore-next-line
    public function dataProcessingFirst($data, $line_no, $options = [])
    {
        ///// convert data first.
        $options['errorCallback'] = function ($message, $key) use ($line_no) {
            $this->selectTableNotFounds[] = sprintf(exmtrans('custom_value.import.import_error_format'), ($line_no-1), $message);
        };

        return DataImportExportService::processCustomValue($this->custom_columns, $data, $options);
    }

    /**
     * import data
     */
    // @phpstan-ignore-next-line
    public function importData($dataAndModel)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');
        // select $model using primary key and value
        $primary_value = array_get($data, $this->primary_key);
        // if not exists, new instance
        if (is_nullorempty($primary_value)) {
            $isCreate = true;
        }
        // if exists, firstOrNew
        else {
            $isCreate = !$model->exists;
        }
        if (!isset($model)) {
            return;
        }

        // loop for data
        foreach ($data as $dkey => $dvalue) {
            $dvalue = is_nullorempty($dvalue) ? null : $dvalue;
            // if not exists column, continue
            if (!in_array($dkey, ['id', 'suuid', 'parent_id', 'parent_type', 'value', 'created_at', 'updated_at', 'deleted_at'])) {
                continue;
            }
            // setvalue function if key is value
            if ($dkey == 'value' && is_list($dvalue)) {
                $model->setValue($dvalue);
            }
            // if timestamps
            elseif (in_array($dkey, ['created_at', 'updated_at', 'deleted_at'])) {
                // if null, contiune
                if (is_nullorempty($dvalue)) {
                    continue;
                }
                // if not create and created_at, continue(because time back)
                if (!$isCreate && $dkey == 'created_at') {
                    continue;
                }
                // set as date
                $model->{$dkey} = Carbon::parse($dvalue);
            }
            // if id or suuid
            elseif (in_array($dkey, ['id', 'suuid'])) {
                // if null, contiune
                if (is_nullorempty($dvalue)) {
                    continue;
                }
                $model->{$dkey} = $dvalue;
            }
            // else, set
            else {
                $model->{$dkey} = $dvalue;
            }
        }

        // if not has deleted_at value, remove deleted_at value
        if (!array_key_value_exists('deleted_at', $data)) {
            $model->deleted_at = null;
        }

        // save model
        $model->save();

        return $model;
    }

    /**
     * check filter data
     */
    // @phpstan-ignore-next-line
    protected function filterData($value_custom)
    {
        $is_filter = false;
        if (is_array($this->filter) && count($this->filter) > 0) {
            foreach ($this->filter as $key => $list) {
                $value = array_get($value_custom, $key);
                if (!isset($value) || !in_array($value, $list)) {
                    $is_filter = true;
                    break;
                }
            }
        }
        return $is_filter;
    }
}
