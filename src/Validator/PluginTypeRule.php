<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Enums\PluginType;

/**
 * PluginTypeRule.
 * Consider comma.
 */
class PluginTypeRule implements Rule
{
    public function __construct()
    {
    }

    /**
    * Check Validation
    *
    * @param  string  $attribute
    * @param  mixed  $value
    * @return bool
    */
    public function passes($attribute, $value)
    {
        if (is_null($value)) {
            return true;
        }

        $values = explode(',', $value);
        $pluginTypes = $this->getPluginTypeDifinitions();
        foreach ($values as $v) {
            if (!$pluginTypes->contains($v)) {
                return false;
            }
        }

        return true;
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        $pluginTypes = $this->getPluginTypeDifinitions()->toArray();
        return str_replace(':values', implode(exmtrans('common.separate_word'), $pluginTypes), trans('validation.in'));
    }

    protected function getPluginTypeDifinitions()
    {
        return collect(PluginType::keys())->map(function ($p) {
            return strtolower($p);
        });
    }
}
