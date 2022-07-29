<?php

namespace Exceedone\Exment\Enums;

/**
 * Whether validation is called.
 */
class ValidateCalledType extends EnumBase
{
    public const FORM = 'form';
    public const IMPORT = 'import';
    public const API = 'api';
}
