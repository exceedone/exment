<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Encore\Admin\Form;

class WorkflowConditionHeader extends ModelBase
{
    public function workflow_action()
    {
        return $this->belongsTo(WorkflowAction::class, 'workflow_action_id');
    }
    
    public function workflow_conditions()
    {
        return $this->morphMany(Condition::class, 'morph', 'morph_type', 'morph_id');
    }

}
