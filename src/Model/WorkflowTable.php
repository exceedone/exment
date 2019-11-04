<?php

namespace Exceedone\Exment\Model;

class WorkflowTable extends ModelBase
{
    use Traits\UseRequestSessionTrait;

    public function workflow()
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }
    
    protected static function boot()
    {
        parent::boot();
        
        static::saved(function ($model) {
            System::resetCache();
        });
    }
}
