<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Model\ColumnQueryKey;

trait SelectTrait
{
    // @phpstan-ignore-next-line
    public function getSelectFilterQuery($query, $input)
    {
        // the index column as a query expression; index() stays the display / field-name helper
        return $query->whereInArrayString(ColumnQueryKey::index($this->custom_column)->expression(), $input);
    }

    // @phpstan-ignore-next-line
    public function isMultipleEnabledTrait()
    {
        return boolval(array_get($this->custom_column, 'options.multiple_enabled', false));
    }

    /**
     * Get default type and value
     *
     * @return array offset 0: type, 1: value
     */
    // @phpstan-ignore-next-line
    protected function getDefaultSetting()
    {
        list($default_type, $default) = parent::getDefaultSetting();

        if ($this->isMultipleEnabled() && is_string($default)) {
            $default = explode(',', $default);
        }

        return [$default_type, $default];
    }
}
