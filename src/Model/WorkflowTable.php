<?php

namespace Exceedone\Exment\Model;

class WorkflowTable extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;

    public function workflow()
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }
}
