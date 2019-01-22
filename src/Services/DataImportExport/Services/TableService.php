<?php

namespace Exceedone\Exment\Services\DataImportExport\Services;

use Exceedone\Exment\Services\DataImportExport\ExportService;
use Exceedone\Exment\Services\DataImportExport\ExportProviders;
use Exceedone\Exment\Model\CustomRelation;

class TableService extends ExportService implements ServiceInterface
{
    protected $custom_table;
    protected $relations;

    public function __construct($args = []){
        parent::__construct($args);

        $this->custom_table = array_get($args, 'custom_table');

        // get relations
        $this->relations = CustomRelation::getRelationsByParent($this->custom_table);
    }

    public function datalist(){
        $providers = [];

        // get default data
        $providers[] = new ExportProviders\DefaultTable($this->custom_table, $this->grid);
        
        foreach ($this->relations as $relation) {
            // if n:n, create as RelationPivotTable
            if($relation->relation_type == RelationType::MANY_TO_MANY){
                $providers[] = new ExportProviders\RelationPivotTable($relation, $this->grid);
            }else{
                $providers[] = new ExportProviders\DefaultTable($relation->child_custom_table, $this->grid);
            }
        }
        
        $datalist = [];
        foreach ($providers as $provider) {
            $datalist[] = ['name' => $provider->name(), 'outputs' => $provider->data()];
        }

        return $datalist;
    }

    public function filebasename(){
        return $this->custom_table->table_view_name;
    }
}
