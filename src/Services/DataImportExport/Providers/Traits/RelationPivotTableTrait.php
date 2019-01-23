<?php

namespace Exceedone\Exment\Services\DataImportExport\Providers\Traits;

use Illuminate\Support\Collection;

/**
 * Relation Pivot table (n:n)
 */
trait RelationPivotTableTrait
{
    protected $relation;

    public function __construct($args = []){
        $this->relation = array_get($args, 'relation');
    }
}
