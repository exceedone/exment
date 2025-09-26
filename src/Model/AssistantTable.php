<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AssistantTable extends ModelBase
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'status', 'table_draft_json', 'column_draft_json'
    ];

    public function messages()
    {
        return $this->morphMany(AssistantMessage::class, 'conversable', 'conversable_type', 'conversable_id', 'id');
    }
}
