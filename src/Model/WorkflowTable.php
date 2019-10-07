<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;

class WorkflowTable extends ModelBase
{
    public function workflow()
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

}
