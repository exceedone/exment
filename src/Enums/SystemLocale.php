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
    public static function getLocaleOptions()
    {
        // get expand system locale
        $system_locales = config('exment.system_locale_options');
        if (isset($system_locales)) {
            $system_locales = explode(',', $system_locales);
        } else {
            $system_locales = [];
        }
        $system_locales[] = 'ja';
        $system_locales[] = 'en';

        // get label name
        $options = [];
        foreach ($system_locales as $system_locale) {
            $options[$system_locale] = \Lang::get('exment::exment.label', [], $system_locale);
        }

        return $options;
    }
}
