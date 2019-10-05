<?php

namespace Exceedone\Exment\Model;

class WorkflowAuthority extends ModelBase
{
    /**
     * Get user or organization
     */
    public function user_organization()
    {
        return $this->morphTo('user_organization', 'related_type', 'related_id');
    }

}
