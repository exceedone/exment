<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

/**
 * replace value
 */
class File extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        $target_value = $this->custom_value;

        if (!isset($target_value)) {
            $str = '';
        } elseif ($this->key == 'documents') {
            $str = $target_value->getDocuments()->map(function ($document) {
                return $document->file_uuid;
            });
        } else {
            // get comma string from index 1.
            $this->length_array = array_slice($this->length_array, 1);

            $str = $target_value->getValue(implode(',', $this->length_array), false, $this->matchOptions) ?? '';
        }

        return $str;
    }
}
