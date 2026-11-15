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
    // @phpstan-ignore-next-line
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
            $this->length_array = $this->getColumnKeys(array_slice($this->length_array, 1));

            $str = $target_value->getValue(implode(',', $this->length_array), false, $this->matchOptions) ?? '';
        }

        return $str;
    }

    /**
     * Get the column names to follow, from the keys after "file".
     *
     * ${file:column} is the file of this data, and ${file:column:file_column} is the file of
     * the data selected by a select_table column. The second one can also be written as the
     * value of a select_table, ${select_table:column.file_column}:
     * ${file:select_table:column.file_column}.
     *
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    protected function getColumnKeys(array $keys): array
    {
        // A column name has no dot, so a key with a dot after "select_table" is not the
        // column named "select_table" followed by a column of the selected data.
        if (count($keys) == 2 && $keys[0] === 'select_table' && strpos($keys[1], '.') !== false) {
            return explode('.', $keys[1]);
        }

        return $keys;
    }
}
