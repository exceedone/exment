<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\ExportImportLibrary;
use Exceedone\Exment\ColumnItems\ParentItem;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Services\DataImportExport\Formats\FormatBase;
use Exceedone\Exment\Services\DataImportExport\Formats\SpOut\SpOut;
use Exceedone\Exment\Services\DataImportExport\Formats\SpOut\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Validator;

/**
 */
trait DataImportExportServiceTrait
{
    public static $queryName = '_export_';

    /**
     * csv or excel format string (xlsx, csv)
     */
    protected $format;

    /**
     * file base name
     */
    protected $filebasename;

    /**
     * import action.
     */
    protected $importAction;

    /**
     * export action.
     */
    protected $exportAction;

    /**
     * view export action.
     */
    protected $viewExportAction;

    /**
     * plugin export action.
     */
    protected $pluginExportAction;

    public function __construct($args = [])
    {
        $this->format = static::getFormat($args);

        if (array_has($args, 'grid')) {
            $this->setGrid(array_get($args, 'grid'));
        }
    }

    public function format($format = null)
    {
        if (!func_num_args()) {
            return $this->format;
        }

        $this->format = static::getFormat($format);

        return $this;
    }

    public function filebasename($filebasename = null)
    {
        if (!func_num_args()) {
            return $this->filebasename;
        }

        $this->filebasename = $filebasename;

        return $this;
    }

    protected static function getFormat($args = []): string
    {
        if ($args instanceof FormatBase) {
            return $args->getFormat();
        }

        if ($args instanceof UploadedFile) {
            $format = $args->extension();
            if ($args->getClientMimeType() === "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet") {
                $format = "xlsx";
            }
        } elseif (is_string($args)) {
            $format = $args;
        } elseif (array_has($args, 'format')) {
            $format = array_get($args, 'format');
        } else {
            $format = app('request')->input('format');
        }

        if (is_null($format)) {
            $format = 'xlsx';
        }

        return $format;
    }

    protected function getFormatClass(string $library, bool $isExport): FormatBase
    {
        if ($isExport) {
            if ($this->exportAction && method_exists($this->exportAction, 'getFormatClass')) {
                return $this->exportAction->getFormatClass($this->format, $library);
            }
        } else {
            if ($this->importAction && method_exists($this->importAction, 'getFormatClass')) {
                return $this->importAction->getFormatClass($this->format, $library);
            }
        }

        return FormatBase::getFormatClass($this->format, $library, $isExport);
    }

    public function importAction($importAction)
    {
        $this->importAction = $importAction;

        return $this;
    }

    public function exportAction($exportAction)
    {
        $this->exportAction = $exportAction;

        return $this;
    }

    public function viewExportAction($viewExportAction)
    {
        $this->viewExportAction = $viewExportAction;

        return $this;
    }

    public function pluginExportAction($pluginExportAction)
    {
        $this->pluginExportAction = $pluginExportAction;

        return $this;
    }

    /**
     * execute export
     */
    public function export()
    {
        \Exment::setTimeLimitLong();

        $formatObj = $this->getFormatClass(ExportImportLibrary::PHP_SPREAD_SHEET, true);

        // get export action type
        $action = request()->get('action');

        if ($action == 'plugin_export' && isset($this->pluginExportAction)) {
            return $this->pluginExportAction->execute();
        }

        if ($action == 'view_export' && isset($this->viewExportAction)) {
            $datalist = $this->viewExportAction->datalist();
        } else {
            $datalist = $this->exportAction->datalist();
        }

        $files = $formatObj
            ->datalist($datalist)
            ->filebasename($this->exportAction->filebasename())
            ->createFile();

        /** @phpstan-ignore-next-line  */
        $formatObj->sendResponse($files);
    }

    /**
     * @param Request $request
     * @return mixed|void error message or success message etc...
     */
    public function import($request)
    {
        \Exment::setTimeLimitLong();

        /** @var Xlsx $formatObj */
        $formatObj = $this->getFormatClass(ExportImportLibrary::SP_OUT, false);

        // validate request
        if (($errors = $this->validateRequest($request)) !== true) {
            return [
                'result' => false,
                //'toastr' => exmtrans('common.message.import_error'),
                'errors' => $errors,
            ];
        }

        if ($request instanceof Request) {
            $import_plugin = $request->get('import_plugin');
            if (isset($import_plugin)) {
                return $this->customImport($import_plugin, $request->file('custom_table_file'), $request->get('custom_table_id'));
            }
        }

        $formatObj->filebasename($this->filebasename);

        // get table data
        $datalist = $formatObj->getDataTable($request);

        // if over count, return over length
        if (is_int($datalist)) {
            return [
                'result' => false,
                'toastr' => exmtrans('common.message.import_error'),
                'errors' => ['import_error_message' => ['type' => 'input', 'message' => exmtrans('error.import_max_row_count', [
                    'count' => config('exment.import_max_row_count', 1000),
                ])]],
            ];
        }

        // filter data
        $datalist = $this->importAction->filterDatalist($datalist);

        if (count($datalist) == 0 || (count($datalist) == 1 && array_has($datalist, Define::SETTING_SHEET_NAME))) {
            return [
                'result' => false,
                'toastr' => exmtrans('common.message.import_error'),
                'errors' => ['import_error_message' => ['type' => 'input', 'message' => exmtrans('error.failure_import_file')]],
            ];
        }

        $response = $this->importAction->import($datalist);

        return $response;
    }


    /**
     * @param string $file_path
     * @param array  $options
     * @return array error message or success message etc...
     */
    public function importBackground(\Illuminate\Console\Command $command, $file_name, $file_path, array $options = [])
    {
        \Exment::setTimeLimitLong();

        $formatObj = $this->getFormatClass(ExportImportLibrary::SP_OUT, false);
        $formatObj
            ->background()
            ->filebasename($this->filebasename);

        // append loop option
        $options['file_name'] = $file_name;
        $options['command'] = $command;

        // get table data
        /** @var Xlsx $formatObj */
        $datalist = $formatObj->getDataTable($file_path, $options);
        // filter data
        $datalist = $this->importAction->filterDatalist($datalist);

        if (count($datalist) == 0) {
            $command->error(exmtrans('error.failure_import_file'));
            return [
                'result' => false,
            ];
        }

        $result = $this->importAction->importChunk($datalist, $options);

        if (boolval(array_get($result, 'result'))) {
            return [
                'result' => true,
                'data_import_cnt' => array_get($result, 'data_import_cnt', 0),
            ];
        } else {
            return [
                'result' => false,
            ];
        }
    }

    /**
     * execute export background
     */
    public function exportBackground(array $options = [])
    {
        \Exment::setTimeLimitLong();
        $formatObj = $this->getFormatClass(ExportImportLibrary::PHP_SPREAD_SHEET, true);

        $datalist = $this->exportAction->datalist();
        $datacount = $this->exportAction->getCount();

        $files = $formatObj
            ->output_aszip(false)
            ->background()
            ->datalist($datalist)
            ->filebasename($this->filebasename() ?? $this->exportAction->filebasename())
            ->createFile();

        if ($datacount == 0 && boolval(array_get($options, 'breakIfEmpty', false))) {
            return [
                'status' => 1,
                'message' => exmtrans('common.message.notfound'),
            ];
        }

        /** @phpstan-ignore-next-line */
        $formatObj->saveAsFile($options['dirpath'], $files);

        return [
            'status' => 0,
            'message' => exmtrans('command.export.success_message', $options['dirpath']),
            'dirpath' => $options['dirpath'],
        ];
    }

    /**
     * import data by custom logic
     * @param int|string $import_plugin
     * @param mixed $file
     */
    protected function customImport($import_plugin, $file, $custom_table_id = null)
    {
        $plugin = Plugin::find($import_plugin);
        $options = ['file' => $file];
        if (isset($custom_table_id)) {
            $custom_table = CustomTable::getEloquent($custom_table_id);
            $options['custom_table'] = $custom_table;
        }
        $batch = $plugin->getClass(PluginType::IMPORT, $options);
        $result = $batch->execute();
        if (gettype($result) == 'boolean') {
            if ($result === false) {
                return [
                    'result' => false,
                    'toastr' => exmtrans('common.message.import_error')
                ];
            } else {
                return [
                    'result' => true,
                    'toastr' => exmtrans('common.message.import_success')
                ];
            }
        } else {
            return $result;
        }
    }

    /**
     * @param Request $request
     * @return array|boolean
     */
    public function validateRequest($request)
    {
        $formatObj = $this->getFormatClass(ExportImportLibrary::PHP_SPREAD_SHEET, false);

        if (!($request instanceof Request)) {
            return true;
        }
        //validate
        $rules = [
            'custom_table_file' => 'required|file',
            'select_primary_key' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $validator->errors()->messages();
        }

        // file validation.
        // (↑"$rules" always error by mimes because uploaded by ajax??)
        $file = $request->file('custom_table_file');
        $validator = Validator::make(
            [
                'file'      => $file,
                'custom_table_file' => strtolower($file->getClientOriginalExtension()),
            ],
            [
                'file'          => 'required',
                'custom_table_file'      => 'required|in:'.$formatObj->accept_extension(),
            ],
            [
                'custom_table_file' => \Lang::get('validation.mimes')
            ]
        );
        if ($validator->fails()) {
            // return errors as custom_table_file.
            /** @phpstan-ignore-next-line */
            return $validator->getMessages();
        }

        return true;
    }

    /**
     * Import Modal
     *
     * @param array $pluginlist
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getImportModal($pluginlist = null)
    {
        // create form fields
        $form = new ModalForm();

        $fileOption = array_merge(
            Define::FILE_OPTION(),
            [
                'showPreview' => false,
                'dropZoneEnabled' => false,
            ]
        );

        // import formats
        $formats = [];
        // check config value
        if (!boolval(config('exment.export_import_export_disabled_csv', false))) {
            $formats['csv'] = 'csv';
            $formats['zip'] = 'zip';
        }
        if (!boolval(config('exment.export_import_export_disabled_excel', false))) {
            $formats['excel'] = 'xlsx';
        }

        /** @phpstan-ignore-next-line */
        $form->descriptionHtml('<span class="red">' . exmtrans('common.help.import_max_row_count', [
            'count' => config('exment.import_max_row_count', 1000),
            'manual' => \getManualUrl('data_bulk_insert')
        ]) . '</span>')
        ->setWidth(8, 3);

        /** @phpstan-ignore-next-line */
        $form->action(admin_urls($this->importAction->getImportEndpoint(), 'import'))
            ->file('custom_table_file', exmtrans('custom_value.import.import_file'))
            ->rules('mimes:' . implode(',', array_keys($formats)))->setWidth(8, 3)->addElementClass('custom_table_file')
            ->options($fileOption)
            ->required()
            ->removable()
            ->attribute(['accept' => collect(array_values($formats))->map(function ($format) {
                return '.' . $format;
            })->implode(',')])
            ->help(exmtrans('custom_value.import.help.custom_table_file', implode(',', array_values($formats))) . array_get($fileOption, 'maxFileSizeHelp'));

        // get import primary key list
        $form->select('select_primary_key', exmtrans('custom_value.import.primary_key'))
            ->options($this->importAction->getPrimaryKeys())
            ->default('id')
            ->setWidth(8, 3)
            ->required()
            ->config('allowClear', false)
            ->addElementClass('select_primary_key')
            ->help(exmtrans('custom_value.import.help.primary_key'));

        if (!empty($pluginlist)) {
            $form->select('import_plugin', exmtrans('custom_value.import.import_plugin'))
            ->options($pluginlist)
            ->setWidth(8, 3)
            ->help(exmtrans('custom_value.import.help.import_plugin'));
        }

        $form->hidden('select_action')->default('stop');
        // $form->select('select_action', exmtrans('custom_value.import.error_flow'))
        //     ->options(getTransArray(Define::CUSTOM_VALUE_IMPORT_ERROR, "custom_value.import.error_options"))
        //     ->default('stop')
        //     ->setWidth(8, 3)
        //     ->addElementClass('select_action')
        //     ->help(exmtrans('custom_value.import.help.error_flow'));

        $form->textarea('import_error_message', exmtrans('custom_value.import.import_error_message'))
            ->attribute(['readonly' => true])
            ->setWidth(8, 3)
            ->rows(4)
            ->addElementClass('import_error_message')
            ->help(exmtrans('custom_value.import.help.import_error_message'));

        $this->importAction->setImportModalItems($form);

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => exmtrans('common.import') . ' - ' . $this->importAction->getImportHeaderViewName()
        ]);
    }

    /**
     * get primary key list.
     *
     * @param CustomTable $custom_table
     * @return array
     */
    protected static function getPrimaryKeys($custom_table)
    {
        // default list
        $keys = getTransArray(Define::CUSTOM_VALUE_IMPORT_KEY, "custom_value.import.key_options");

        // get columns where "unique" and index enabled options is true.
        $columns = $custom_table
            ->custom_columns()
            ->indexEnabled()
            ->where('options->unique', "1")
            ->pluck('column_view_name', 'column_name')
            ->toArray();
        // add key name "value.";
        $val_columns = [];
        foreach ($columns as $column_key => $column_value) {
            $val_columns['value.'.$column_key] = $column_value;
        }

        // merge
        $keys = array_merge($keys, $val_columns);

        return $keys;
    }

    /**
     * Replace custom value's data array. For import. Calling custom_value import, API POST, API PUT
     *
     * @param \Illuminate\Support\Collection $custom_columns
     * @param array $data
     * @param array $options
     * @return array
     */
    public static function processCustomValue($custom_columns, $data, $options = [])
    {
        foreach ($data as $key => &$value) {
            if (boolval(array_get($options, 'onlyValue')) || strpos($key, "value.") !== false) {
                $new_key = str_replace('value.', '', $key);
                // get target column
                /** @var CustomColumn|null $target_column */
                $target_column = $custom_columns->first(function ($custom_column) use ($new_key) {
                    return array_get($custom_column, 'column_name') == $new_key;
                });
                if (!isset($target_column)) {
                    continue;
                }

                // convert target key's id
                if (isset($value)) {
                    if (ColumnType::isMultipleEnabled(array_get($target_column, 'column_type'))
                        && boolval(array_get($target_column, 'options.multiple_enabled'))) {
                        $value = explode(",", $value);
                    }

                    if (array_has($options, 'setting')) {
                        $s = collect($options['setting'])->filter(function ($s) use ($key) {
                            return isset($s['target_column_name']) && $s['column_name'] == $key;
                        })->first();
                    }
                    if (isset($target_column->column_item)) {
                        $target_table = isset($target_column->select_target_table) ? $target_column->select_target_table : $target_column->custom_table_cache;
                        static::getImportColumnValue($data, $key, $value, $target_column->column_item, $target_column->column_item->label(), $s ?? null, $target_table, $options);
                    }
                }
            } elseif ($key == Define::PARENT_ID_NAME && isset($value)) {
                // convert target key's id
                if (array_has($options, 'setting')) {
                    $s = collect($options['setting'])->filter(function ($s) {
                        return isset($s['target_column_name']) && $s['column_name'] == Define::PARENT_ID_NAME;
                    })->first();
                }

                $target_table = CustomTable::getEloquent(array_get($data, 'parent_type'));
                $parent_item = ParentItem::getItem($target_table);
                if (isset($parent_item)) {
                    static::getImportColumnValue($data, $key, $value, $parent_item, $target_table->table_view_name, $s ?? null, $target_table, $options);
                }
            }
        }
        return $data;
    }

    /**
     * get column import value. if error, set message
     *
     * @return void
     */
    protected static function getImportColumnValue(&$data, $key, &$value, $column_item, $column_view_name, $setting, $target_table, $options = [])
    {
        $setting = $setting ?? [];
        $options = array_merge(
            [
                'errorCallback' => null,
                'datalist' => null,
            ],
            $options
        );

        if (method_exists($column_item, 'getKeyAndIdList')) {
            $datalist = $column_item->getKeyAndIdList($options['datalist'], array_get($setting, 'target_column_name'));
            if (!is_nullorempty($datalist)) {
                $setting['datalist'] = $datalist;
            }
        }

        $base_value = $value;
        $importValue = $column_item->getImportValue($value, $setting);

        if (!isset($importValue)) {
            return;
        }

        // if skip column, remove from data, and return
        if (boolval(array_get($importValue, 'skip'))) {
            array_forget($data, $key);
            return;
        }

        // if not found, set error
        if (!boolval(array_get($importValue, 'result'))) {
            $message = isset($importValue['message']) ? $importValue['message'] : exmtrans('validation.not_has_custom_value', [
                'attribute' => $column_view_name,
                'value' => is_array($base_value) ? implode(exmtrans('common.separate_word'), $base_value) : $base_value,
                'table_view_name' => $target_table->table_view_name
            ]);

            if (isset($options['errorCallback'])) {
                $options['errorCallback']($message, $key);
            }
        }
        $value = array_get($importValue, 'value');
    }
}
