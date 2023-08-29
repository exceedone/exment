<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-consistent-constructor
 * @method static \Illuminate\Database\Query\Builder count($columns = '*')
 */
class WorkflowTable extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }
}
