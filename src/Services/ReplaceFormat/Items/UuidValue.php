<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

/**
 * replace value
 */
class UuidValue extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    // @phpstan-ignore-next-line
    public function replace($format, $options = [])
    {
        return make_uuid();
    }
}
