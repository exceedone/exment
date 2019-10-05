<?php

namespace Exceedone\Exment\Model;

class WorkflowStatus extends ModelBase
{
    public function deletingChildren()
    {
    }

    protected static function boot()
    {
        parent::boot();
        
        // add default order
        static::addGlobalScope(new OrderScope('order'));
    }
}
