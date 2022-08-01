<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

trait SelectTrait
{
    public function getSelectFilterQuery($query, $input)
    {
        return $query->whereInArrayString($this->index(), $input);
    }

    public function isMultipleEnabledTrait()
    {
        return boolval(array_get($this->custom_column, 'options.multiple_enabled', false));
    }

    /**
     * Get default type and value
     *
     * @return array offset 0: type, 1: value
     */
    protected function getDefaultSetting()
    {
        list($default_type, $default) = parent::getDefaultSetting();

        if ($this->isMultipleEnabled() && is_string($default)) {
            $default = explode(',', $default);
        }

        return [$default_type, $default];
    }
}
