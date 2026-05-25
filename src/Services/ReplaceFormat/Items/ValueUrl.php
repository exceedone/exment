<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

/**
 * replace value
 */
class ValueUrl extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    // @phpstan-ignore-next-line
    public function replace($format, $options = [])
    {
        if (!isset($this->custom_value)) {
            return null;
        }

        //else, getting url
        $tag = array_key_value_exists('link', $this->matchOptions);
        $str = $this->custom_value->getUrl(['tag' => $tag, 'modal' => false]) ?? '';
        array_forget($this->matchOptions, 'link');

        return $str;
    }

    // @phpstan-ignore-next-line
    public function getLink($str)
    {
        return $str;
    }
}
