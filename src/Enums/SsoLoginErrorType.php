<?php

namespace Exceedone\Exment\Enums;

/**
 * Sso Login Error Type Difinition.
 */
class SsoLoginErrorType extends EnumBase
{
    /**
     * Not exists provider user.
     * When jit is false.
     */
    public const NOT_EXISTS_PROVIDER_USER = 'not_exists_provider_user';

    /**
     * provider undefined error.
     */
    public const PROVIDER_ERROR = 'provider_error';

    /**
     * Sync mapping error.
     * Ex. cannot get email.
     */
    public const SYNC_MAPPING_ERROR = 'sync_mapping_error';

    /**
     * Sync validation error.
     * Ex. email is null.
     */
    public const SYNC_VALIDATION_ERROR = 'sync_validation_error';

    /**
     * Not accept domain.
     */
    public const NOT_ACCEPT_DOMAIN = 'not_accept_domain';

    /**
     * Not exists exment user.
     * When jit is false.
     */
    public const NOT_EXISTS_EXMENT_USER = 'not_exists_exment_user';

    public const UNDEFINED_ERROR = 'undefined_error';
}
