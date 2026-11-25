<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Services\NameLocales;

/**
 * The names of a table, a column, a view or a menu in the other languages (ISS02859), kept in
 * its options as name_locale_<locale>. "name_locales" reads them as locale => name (the API
 * sends it, read only, beside the stored name) and writes them (the forms of views and menus).
 */
trait NameLocalesTrait
{
    /**
     * @return array<string, string>
     */
    public function getNameLocalesAttribute(): array
    {
        $options = $this->getAttributeValue('options');
        return NameLocales::all(is_array($options) ? $options : null);
    }

    /**
     * @param mixed $value locale => name; an empty name removes that language
     */
    public function setNameLocalesAttribute($value): void
    {
        if (!is_array($value)) {
            return;
        }
        $options = $this->getAttributeValue('options');
        $options = is_array($options) ? $options : [];
        foreach ($value as $locale => $name) {
            if (!is_string($locale) || !preg_match('/\A[A-Za-z_]{2,10}\z/', $locale)) {
                continue;
            }
            $key = NameLocales::key($locale);
            $name = is_scalar($name) ? trim(strval($name)) : '';
            if ($name === '') {
                unset($options[$key]);
            } else {
                $options[$key] = mb_substr($name, 0, 256);
            }
        }
        $this->setAttribute('options', $options);
    }
}
