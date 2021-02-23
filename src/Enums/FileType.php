<?php

namespace Exceedone\Exment\Enums;

class FileType extends EnumBase
{
    /**
     * custom value's column(file, image)
     */
    const CUSTOM_VALUE_COLUMN = '1';

    /**
     * Custom value's document
     */
    const CUSTOM_VALUE_DOCUMENT = '2';

    /**
     * User avatar
     */
    const AVATAR = '3';

    /**
     * System icon, logo, etc
     */
    const SYSTEM = '4';

    /**
     * Custom form column
     */
    const CUSTOM_FORM_COLUMN = '5';

    /**
     * Public form logo etc.
     */
    const PUBLIC_FORM = '6';
}
