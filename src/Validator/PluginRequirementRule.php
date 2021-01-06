<?php
namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * PluginRequirementRule.
 * Consider comma.
 */
class PluginRequirementRule implements Rule
{
    protected $composers = [];

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

        if (!is_array($value)) {
            $value = [$value];
        }

        $result = true;

        foreach ($value as $v) {
            $class = array_get($v, 'class');
            $composer = array_get($v, 'composer');

            if (!isset($class) || !isset($composer)) {
                continue;
            }

            if (!class_exists($class)) {
                $this->composers[] = $composer;
                $result = false;
            }
        }

        return $result;
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        $composer = implode(',', $this->composers);
        return str_replace(':composer', $composer, trans('validation.class_requirement'));
    }
}
