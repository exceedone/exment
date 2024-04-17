<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Import;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File as ExmentFile;

class FileColumnProvider extends ProviderBase
{
    /**
     * File directory full path.
     *
     * @var string
     */
    protected $fileDirFullPath;

    protected $primary_key;

    /**
     * Custom table
     *
     * @var CustomTable
     */
    protected $custom_table;

    public function __construct($args = [])
    {
        $this->custom_table = array_get($args, 'custom_table');
        $this->fileDirFullPath = array_get($args, 'fileDirFullPath');
        $this->primary_key = 'id';
    }

    /**
     * get data and object.
     * set matched model data
     */
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

            $value_customs[$line_no] = $value_custom;

            // get primary values
            $primary_values[] = array_get($value_custom, $this->primary_key);
        }

        // get all custom value for performance
        $models = $this->custom_table->getMatchedCustomValues($primary_values, $this->primary_key);

        // set all select table's value
        $this->custom_table->setSelectTableValues(collect($value_customs));

        $results = [];
        foreach ($value_customs as $line_no => $value_custom) {
            $options['datalist'] = $value_customs;

            // select $model using primary key and value
            $primary_value = array_get($value_custom, $this->primary_key);
            // if not exists, new instance
            if (!is_nullorempty($primary_value)) {
                // get model from models
                $model = array_get($models, strval($primary_value));
                if ($model) {
                    $model->saved_notify(false);
                }
            }

            $results[] = ['data' => $value_custom, 'model' => $model ?? null, 'fileFullPath' => $this->getFileFullPath($value_custom)];
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
        ///// get all table columns
        $validate_columns = $this->custom_table->custom_columns_cache->filter(function ($custom_column) {
            return ColumnType::isAttachment($custom_column);
        });

        $error_data = [];
        $success_data = [];
        foreach ($dataObjects as $line_no => $value) {
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
     * @param int $line_no
     * @param array $dataAndModel
     * @param array $validate_columns
     * @param array $dataObjects
     */
    public function validateDataRow($line_no, $dataAndModel, $validate_columns, $dataObjects)
    {
        return $this->_validateDataRow($line_no, $dataAndModel, $validate_columns, true);
    }

    /**
     * validate data row
     *
     * @param $line_no
     * @param $dataAndModel
     * @param $validate_columns
     * @param bool $isCheckColumn
     * @return array|mixed[]|true
     */
    protected function _validateDataRow($line_no, $dataAndModel, $validate_columns, bool $isCheckColumn)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');
        $fileFullPath = array_get($dataAndModel, 'fileFullPath');
        $file_name = array_get($data, 'file_name');
        $display_file_name = array_get($data, 'display_file_name');

        $errors = [];
        $validateRow = true;

        if (!$model) {
            $errors[] = exmtrans('common.message.notfound_or_deny');
        }

        // Whether contains column
        if ($isCheckColumn) {
            $column_name = array_get($data, 'column_name');
            $validate_column = $validate_columns->first(function ($validate_column) use ($column_name) {
                return isMatchString($column_name, $validate_column->column_name);
            });

            if (!$validate_column) {
                $errors[] = exmtrans('custom_value.import.message.file_column_not_match', [
                    'column_name' => $column_name,
                    'table_name' => $this->custom_table->table_name,
                ]);
            } elseif ($validate_column->column_type == ColumnType::IMAGE) {
                $extention = \File::extension($file_name);
                if (!in_array($extention, Define::IMAGE_RULE_EXTENSIONS)) {
                    $errors[] = trans('validation.image', ['attribute' => $file_name]);
                }
            }
        }

        // Whether file exists
        if (is_nullorempty($fileFullPath)) {
            $errors[] = exmtrans('custom_value.import.message.file_not_found', [
                'file_name' => $file_name,
                'dir_path' => $this->fileDirFullPath,
            ]);
        }

        // if has display_file_name, check same extension
        if (!is_nullorempty($display_file_name)) {
            if (!isMatchString(pathinfo($file_name, PATHINFO_EXTENSION), pathinfo($display_file_name, PATHINFO_EXTENSION))) {
                $errors[] = exmtrans('custom_value.import.message.file_column_extension_not_match');
            }
        }

        // Append row no
        $errors = collect($errors)->map(function ($error) use ($line_no) {
            return sprintf(exmtrans('custom_value.import.import_error_format'), ($line_no+1), $error);
        })->toArray();

        if (!is_nullorempty($errors)) {
            return $errors;
        }
        return true;
    }


    /**
     * import data
     */
    public function importData($dataAndModel)
    {
        $data = array_get($dataAndModel, 'data');
        $model = array_get($dataAndModel, 'model');
        $fileFullPath = array_get($dataAndModel, 'fileFullPath');
        $displayFileName = array_get($data, 'display_file_name') ?? array_get($data, 'file_name');

        $column_name = array_get($data, 'column_name');
        $custom_column = CustomColumn::getEloquent($column_name, $this->custom_table);

        // get file
        $file = \File::get($fileFullPath);

        // save file info
        $exmentfile = ExmentFile::storeAs(FileType::CUSTOM_VALUE_COLUMN, $file, $this->custom_table->table_name, $displayFileName)
            ->saveCustomValueAndColumn($model->id, $custom_column, $this->custom_table, !$custom_column->isMultipleEnabled());
        $path = path_join($this->custom_table->table_name, $exmentfile->local_filename);

        // set custom value
        if (!$custom_column->isMultipleEnabled()) {
            $model->setValue($column_name, $path);
        } else {
            // If multiple, merge original array
            $value = array_get($model, 'value.' . $custom_column->column_name) ?? [];
            $value = array_merge($value, [$path]);
            $model->setValue($column_name, $value);
        }

        // return filename
        $model->save();

        return $model;
    }


    /**
     * Get file full path
     *
     * @param array $value_custom
     * @return string|null
     */
    protected function getFileFullPath(array $value_custom): ?string
    {
        $file_name = array_get($value_custom, 'file_name');
        if (is_nullorempty($file_name)) {
            return null;
        }

        // get file
        $file_path = path_join($this->fileDirFullPath, $file_name);
        if (!\File::exists($file_path)) {
            return null;
        }

        return $file_path;
    }
}
