<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class AssistantMessage extends ModelBase
{
    use HasFactory;

    protected $fillable = [
        'conversable_type', 'conversable_id', 'message_text', 'role'
    ];

    public function conversable()
    {
        return $this->morphTo();
    }
}
