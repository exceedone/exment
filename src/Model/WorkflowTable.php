<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-consistent-constructor
 * @property mixed $workflow_id
 * @property mixed $custom_table_id
 * @property mixed $active_flg
 * @property mixed $active_start_date
 * @property mixed $active_end_date
 * @method static int count($columns = '*')
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
