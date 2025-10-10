<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Exceedone\Exment\Enums\TenantType;
use Exceedone\Exment\Casts\EnvironmentSettingsCast;
use Exceedone\Exment\Casts\SecretKeyEncrypted;

class Tenant extends Model
{
    use SoftDeletes;

    protected $table = 'tenants';

    protected $fillable = [
        'tenant_suuid',
        'subdomain',
        'new_subdomain',
        'tenant_path',
        'type',
        'plan_info',
        'status',
        'token',
        'environment_settings'
    ];

    protected $casts = [
        'plan_info' => 'array',
        'environment_settings' => EnvironmentSettingsCast::class,
        'token' => SecretKeyEncrypted::class,
    ];

    /**
     * Properties for static analyzers
     *
     * @property string $tenant_suuid
     * @property string|null $subdomain
     * @property string|null $new_subdomain
     * @property string|null $tenant_path
     * @property string $type
     * @property array $plan_info
     * @property string $status
     * @property string $token
     * @property array|null $environment_settings
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'tenant_suuid',
            'subdomain',
            'new_subdomain',
            'tenant_path',
            'type',
            'plan_info',
            'status',
            'token',
            'environment_settings',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }
    /**
     * Determine if tenant uses subdomain type
     */
    public function usesSubdomain(): bool
    {
        return $this->type === TenantType::SUBDOMAIN;
    }

    /**
     * Get environment settings
     */
    public function getEnvironmentSettings()
    {
        return $this->environment_settings;
    }

    /**
     * Set environment settings
     */
    public function setEnvironmentSettings($settings)
    {
        $this->environment_settings = $settings;
        return $this;
    }

    /**
     * Get plan information
     */
    public function getPlanInfo()
    {
        return $this->plan_info;
    }

    /**
     * Set plan information
     */
    public function setPlanInfo($planInfo)
    {
        $this->plan_info = $planInfo;
        return $this;
    }
}
