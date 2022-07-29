<?php

namespace Exceedone\Exment\Enums;

class FileType extends EnumBase
{
    /**
     * custom value's column(file, image)
     */
    public const CUSTOM_VALUE_COLUMN = '1';

    /**
     * Custom value's document
     */
    public const CUSTOM_VALUE_DOCUMENT = '2';

    /**
     * User avatar
     */
    public const AVATAR = '3';

    /**
     * System icon, logo, etc
     */
    public const SYSTEM = '4';

    /**
     * Custom form column
     */
    public const CUSTOM_FORM_COLUMN = '5';

    /**
     * Public form logo etc.
     */
    public const PUBLIC_FORM = '6';
}
