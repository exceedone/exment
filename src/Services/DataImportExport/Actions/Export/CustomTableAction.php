<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Export;

use Exceedone\Exment\Services\DataImportExport\ExportService;
use Exceedone\Exment\Services\DataImportExport\ExportProviders;
use Exceedone\Exment\Services\DataImportExport\Providers\Export;
use Exceedone\Exment\Services\DataImportExport\Actions\Traits\CustomTableTrait;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\RelationType;

class CustomTableAction implements ActionInterface
{
    use CustomTableTrait{
        CustomTableTrait::__construct as traitconstruct;
    }
    
    /**
     * laravel-admin grid
     */
    protected $grid;

    public function __construct($args = []){
        $this->traitconstruct($args);
        $this->grid = array_get($args, 'grid');
    }

    public function datalist(){
        $providers = [];

        // get default data
        $providers[] = new Export\DefaultTable([
            'custom_table' => $this->custom_table,
            'grid' => $this->grid
        ]);
        
        foreach ($this->relations as $relation) {
            // if n:n, create as RelationPivotTable
            if($relation->relation_type == RelationType::MANY_TO_MANY){
                $providers[] = new Export\RelationPivotTable
                (
                    [
                        'relation' => $relation,
                        'grid' => $this->grid
                    ]
                );
            }else{
                $providers[] = new Export\DefaultTable(
                    [
                        'custom_table' => $relation->child_custom_table,
                        'grid' => $this->grid
                    ]
                );
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
