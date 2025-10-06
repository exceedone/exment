<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AssistantCalendar extends ModelBase
{
    use HasFactory, HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'status'
    ];

    public function messages()
    {
        return $this->morphMany(AssistantMessage::class, 'conversable', 'conversable_type', 'conversable_id', 'id');
    }
}
