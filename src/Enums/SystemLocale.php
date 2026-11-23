<?php

namespace Exceedone\Exment\Enums;

class SystemLocale extends EnumBase
{
    public const JA = 'ja';
    public const EN = 'en';

    /**
     * Get System Locale. Getting from config
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    public static function getLocaleOptions()
    {
        // the installed languages (the folders of resources/lang, and EXMENT_SYSTEM_LOCALE_OPTIONS), with their names
        return \Exceedone\Exment\Services\UserLocale::options();
    }
}
