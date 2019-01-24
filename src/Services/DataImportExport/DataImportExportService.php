<?php

namespace Exceedone\Exment\Services\DataImportExport;

use Encore\Admin\Grid\Exporters\AbstractExporter;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Services\FormHelper;
use Exceedone\Exment\Services\DataImportExport\Services;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Validator;
use Carbon\Carbon;

class DataImportExportService extends AbstractExporter
{
    //use ImportExportTrait;

    const SCOPE_ALL = 'all';
    const SCOPE_TEMPLATE = 'temp';
    const SCOPE_CURRENT_PAGE = 'page';
    const SCOPE_SELECTED_ROWS = 'selected';

    public static $queryName = '_export_';
    
    /**
     * csv or excel format model
     */
    protected $format;
    
    /**
     * import action.
     */
    protected $importAction;

    /**
     * export action.
     */
    protected $exportAction;

    public function __construct($args = []){
        $this->format = static::getFormat($args);
        
        if (array_has($args, 'grid')) {
            $this->setGrid(array_get($args, 'grid'));
        }
    }

    public function format($format = null){
        if(!func_num_args()){
            return $this->format;
        }

        $this->format = static::getFormat($format);

        return $this;
    }

    public static function getFormat($args = []){
        if($args instanceof FormatBase){
            return $args;
        }
        
        if($args instanceof UploadedFile){
            $format = $args->extension();
        }
        elseif(array_has($args, 'format')){
            $format = array_get($args, 'format');
        }else{
            $format = app('request')->input('format');
        }

        switch ($format) {
            case 'excel':
            case 'xlsx':
                return new Formats\Xlsx();
            default:
                return new Formats\Csv();
        }
    }

    public function importAction($importAction){
        $this->importAction = $importAction;

        return $this;
    }
    
    public function exportAction($exportAction){
        $this->exportAction = $exportAction;

        return $this;
    }
    
    /**
     * execute export
     */
    public function export()
    {
        $datalist = $this->exportAction->datalist();

        $files = $this->format
            ->datalist($datalist)
            ->filebasename($this->exportAction->filebasename())
            ->createFile();
        
        $response = $this->format->createResponse($files);
        $response->send();
        exit;
    }


    
    /**
     * @param $request
     * @return mixed|void error message or success message etc...
     */
    public function import($request)
    {
        set_time_limit(240);
        // validate request
        if (!$this->validateRequest($request)) {
            return [
                'result' => false,
                //'toastr' => exmtrans('common.message.import_error'),
                'errors' => $validateRequest,
            ];
        }

        // get table data
        if(method_exists($this->importAction, 'getDataTable')){
            $datalist = $this->importAction->getDataTable($request);
        }else{
            $datalist = $this->format->getDataTable($request);
        }

        // filter data
        $datalist = $this->importAction->filterDatalist($datalist);
        
        $response = $this->importAction->import($datalist);

        return $response;
    }

    /**
     * @param $request
     * @return bool
     */
    public function validateRequest($request)
    {
        if(!($request instanceof Request)){
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
                'custom_table_file'      => 'required|in:'.$this->format->accept_extension(),
            ],
            [
                'custom_table_file' => \Lang::get('validation.mimes')
            ]
        );
        if ($validator->fails()) {
            // return errors as custom_table_file.
            return $validator->errors()->messages();
        }

        return true;
    }

    // Import Modal --------------------------------------------------
    public function getImportModal()
    {
        // create form fields
        $form = new \Exceedone\Exment\Form\Widgets\ModalForm();
        $form->disableReset();
        $form->modalAttribute('id', 'data_import_modal');
        $form->modalHeader(exmtrans('common.import') . ' - ' . $this->importAction->getImportHeaderViewName());

        $form->action(admin_base_paths($this->importAction->getImportEndpoint(), 'import'))
            ->file('custom_table_file', exmtrans('custom_value.import.import_file'))
            ->rules('mimes:csv,xlsx')->setWidth(8, 3)->addElementClass('custom_table_file')
            ->options(Define::FILE_OPTION())
            ->help(exmtrans('custom_value.import.help.custom_table_file'));
        
        // get import primary key list
        $form->select('select_primary_key', exmtrans('custom_value.import.primary_key'))
            ->options($this->importAction->getPrimaryKeys())
            ->default('id')
            ->setWidth(8, 3)
            ->addElementClass('select_primary_key')
            ->help(exmtrans('custom_value.import.help.primary_key'));

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
            
        return $form->render()->render();
    }
    
    /**
     * get primary key list.
     */
    protected static function getPrimaryKeys($custom_table)
    {
        // default list
        $keys = getTransArray(Define::CUSTOM_VALUE_IMPORT_KEY, "custom_value.import.key_options");

        // get columns where "unique" options is true.
        $columns = $custom_table
            ->custom_columns()
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
    
}
