<?php

namespace Exceedone\Exment\Enums;

/**
 * EnumOptionTrait
 *
 * @property static $options
 */
trait EnumOptionTrait
{
    // @phpstan-ignore-next-line
    public function option()
    {
        /** @phpstan-ignore-next-line array_get expects array|ArrayAccess, static(Exceedone\Exment\Enums\SystemColumn) given  */
        return array_get(static::$options, $this->lowerKey(), null);
    }

    // @phpstan-ignore-next-line
    public static function getOptions($filters = [])
    {
        $options = static::$options;
        foreach ($filters as $key => $value) {
            $options = collect($options)->filter(function ($option) use ($key, $value) {
                return array_get($option, $key) == $value;
            });
        }
        return collect($options)->toArray();
    }

    // @phpstan-ignore-next-line
    public static function getOption($filters = [])
    {
        return collect(static::getOptions($filters))->first() ?? null;
    }
}
