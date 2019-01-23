<?php

namespace Exceedone\Exment\Services\DataImportExport\Actions\Traits;

use Exceedone\Exment\Services\DataImportExport\ExportService;
use Exceedone\Exment\Services\DataImportExport\ExportProviders;
use Exceedone\Exment\Services\DataImportExport\Providers\Export;
use Exceedone\Exment\Services\DataImportExport\Providers\Import;
use Exceedone\Exment\Model\CustomRelation;

trait CustomTableTrait
{
    /**
     * target custom table
     */
    protected $custom_table;

    /**
     * custom_table's relations
     */
    protected $relations;

    public function __construct($args = []){
        $this->custom_table = array_get($args, 'custom_table');

        // get relations
        $this->relations = CustomRelation::getRelationsByParent($this->custom_table);
    }
}
