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
    
    public function scopeActive($query){
        $today = \Carbon\Carbon::today();
        return $query->where('active_flg', true)
            ->where(function ($query) use($today){
                $query->where('active_start_date', '>=', $today)
                    ->orWhereNull('active_start_date');
            })->where(function ($query) use($today) {
                $query->where('active_start_date', '<=', $today)
                    ->orWhereNull('active_start_date');
            });
    }

}
