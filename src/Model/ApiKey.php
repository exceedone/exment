<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * For API auth "api_key"
 * @property Client $client
 */
class ApiKey extends Model
{
    protected $fillable = ['key', 'client_id', 'user_id'];

    protected $table = 'oauth_api_keys';

    public $timestamps = false;

    protected $primary_key = 'key';

    protected $keyType = 'string';

    public $incrementing = false;


    /**
     * Get all of the authentication codes for the client.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Passport::clientModel());
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = make_uuid();
            }
        });
    }
}
