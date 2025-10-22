<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AssistantWorkflow extends ModelBase
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'status', 'workflow_table_name_draft', 'workflow_draft_json', 'workflow_statuses_draft_json', 'workflow_actions_draft_json'
    ];

    public function messages()
    {
        return $this->morphMany(AssistantMessage::class, 'conversable', 'conversable_type', 'conversable_id', 'id');
    }
}
