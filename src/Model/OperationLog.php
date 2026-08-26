<?php

namespace Exceedone\Exment\Model;

/**
 * @property mixed $user
 * @property mixed $user_id
 * @property mixed $created_at
 */
class OperationLog extends \ExmentAdminCore\Admin\Auth\Database\OperationLog
{
    use Traits\SerializeDateTrait;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'path',
        'method',
        'ip',
        'input',
        'event_type',
        'resource_type',
        'resource_id',
        'before_json',
        'after_json',
        'diff_json',
        'request_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'diff_json' => 'array',
    ];
    //protected $appends = ['base_user_id'];


    // @phpstan-ignore-next-line
    public function getBaseUserIdAttribute()
    {
        if (isMatchString($this->user_id, 0)) {
            return "0";
        }

        $user = $this->user;
        return $user ? $user->base_user_id : "0";
    }


    // @phpstan-ignore-next-line
    public function getUserNameAttribute()
    {
        if (isMatchString($this->user_id, 0)) {
            return null;
        }

        $user = $this->user;
        return $user ? $user->user_name : null;
    }

    /**
     * @return array<mixed>
     */
    public function getDiffAttribute(): array
    {
        return $this->diff_json ?: [];
    }


    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format($this->getDateFormat());
    }
}
