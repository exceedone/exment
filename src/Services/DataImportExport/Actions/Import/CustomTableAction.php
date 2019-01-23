<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Import;

use Exceedone\Exment\Services\DataImportExport\ExportService;
use Exceedone\Exment\Services\DataImportExport\ExportProviders;
use Exceedone\Exment\Services\DataImportExport\Providers\Export;
use Exceedone\Exment\Services\DataImportExport\Providers\Import;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\RelationType;

class CustomTableAction implements ActionInterface
{
    /**
     * target custom table
     */
    protected $custom_table;

    /**
     * custom_table's relations
     */
    protected $relations;

    protected $primary_key;

    public function __construct($args = []){
        $this->custom_table = array_get($args, 'custom_table');

        // get relations
        $this->relations = CustomRelation::getRelationsByParent($this->custom_table);

        $this->primary_key = array_get($args, 'primary_key', 'id');
    }

    public function import($datalist, $options = []){
        // get target data and model list
        $data_imports = [];
        foreach ($datalist as $table_name => &$data) {
            //$target_table = $data['custom_table'];
            $provider = $this->getProvider($table_name);
            if(!isset($provider)){
                continue;
            }

            $dataObject = $provider->getDataObject($data, $options);

            // validate data
            list($data_import, $error_data) = $provider->validateImportData($dataObject);
        
            // if has error data, return error data
            if (is_array($error_data) && count($error_data) > 0) {
                return response([
                    'result' => false,
                    'toastr' => exmtrans('common.message.import_error'),
                    'errors' => ['import_error_message' => ['type' => 'input', 'message' => implode("\r\n", $error_data)]],
                ]);
            }
            $data_imports[] = [
                'provider' => $provider,
                'data_import' => $data_import
            ];
        }

        foreach ($data_imports as $data_import) {
            // execute imoport
            $provider = $data_import['provider'];
            foreach ($data_import['data_import'] as $index => &$row) {
                // call dataProcessing if method exists
                if(method_exists($provider, 'dataProcessing')){
                    $row['data'] = $provider->dataProcessing(array_get($row, 'data'));
                }
                
                $provider->importData($row);
            }
        }

        return [
            'result' => true,
            'toastr' => exmtrans('common.message.import_success')
        ];
    }

    /**
     * filter only custom_table or relations datalist.
     */
    public function filterDatalist($datalist){
        // get tablenames
        $table_names = [$this->custom_table->table_name];

        foreach ($this->relations as $relation) {
            $table_names[] = $relation->getSheetName();
        }

        return collect($datalist)->filter(function($data, $keyname) use($table_names){
            return in_array($keyname, $table_names);
        })->toArray();
    }
    
    /**
     * get provider
     */
    public function getProvider($keyname){
        // get providers
        if($keyname == $this->custom_table->table_name){
            return new Import\DefaultTable([
                'custom_table' => $this->custom_table,
                'promary_key' => $this->primary_key,
            ]);
        }else{
            // get relations
            foreach ($this->relations as $relation) {
                if ($relation->relation_type == RelationType::MANY_TO_MANY) {
                    return new Import\RelationPivotTable([
                        'relation' => $relation,
                    ]);
                } else {
                    return new Import\DefaultTable([
                        'custom_table' => $relation->child_custom_table,
                        'promary_key' => 'id',
                    ]);
                }
            }
        }
    }
}
