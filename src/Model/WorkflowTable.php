<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;

class WorkflowTable extends ModelBase
{
    public function workflow()
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

}
