<?php

namespace Exceedone\Exment\Enums;

class TenantStatus extends EnumBase
{
    public const PENDING = 'pending';
    public const PROVISIONING = 'provisioning';
    public const ACTIVE = 'active';
    public const ACTIVATION_FAILED = 'activation_failed';
    public const SUSPENDED = 'suspended';
    public const DELETED = 'deleted';
    public const SUBDOMAIN_CHANGE_PENDING = 'subdomain_change_pending';
}
