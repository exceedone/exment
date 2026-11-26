<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Services\Sync\SyncConnector;
use Exceedone\Exment\Services\Sync\SyncConnectors;
use Exceedone\Exment\Services\Sync\SyncSecrets;
use Illuminate\Database\Eloquent\Model;

/**
 * A connection of the data sync to another service (ISS00113).
 *
 * @property int $id
 * @property string $name
 * @property string $connector
 * @property array<string, mixed>|null $settings
 * @property array<string, string>|null $secrets each encrypted (SyncSecrets)
 * @property bool $enabled
 * @property int|null $created_user_id
 * @property int|null $updated_user_id
 */
class SyncConnection extends Model
{
    protected $table = 'sync_connections';

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'secrets' => 'array',
        'enabled' => 'boolean',
    ];

    /** never in an array or a JSON of the model */
    protected $hidden = ['secrets'];

    public function connectorInstance(): ?SyncConnector
    {
        return SyncConnectors::get($this->connector);
    }

    /**
     * @return mixed
     */
    public function setting(string $name)
    {
        return array_get($this->settings ?? [], $name);
    }

    /**
     * A secret, decrypted (null: not set, or not readable here).
     */
    public function secret(string $name): ?string
    {
        return SyncSecrets::decrypt(array_get($this->secrets ?? [], $name));
    }

    public function hasSecret(string $name): bool
    {
        return !is_nullorempty(array_get($this->secrets ?? [], $name));
    }

    /**
     * Keep a secret (encrypted), or remove it (null).
     */
    public function setSecret(string $name, ?string $plain): void
    {
        $secrets = $this->secrets ?? [];
        if (is_nullorempty($plain)) {
            unset($secrets[$name]);
        } else {
            $secrets[$name] = SyncSecrets::encrypt(strval($plain));
        }
        $this->secrets = $secrets;
    }

    public function mappings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SyncMapping::class, 'sync_connection_id');
    }
}
