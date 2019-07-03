<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

trait ImportValueTrait
{
    /**
     * replace value for import
     *
     * @param mixed $value
     * @param array $setting
     * @return void
     */
    public function getImportValue($value, $setting = [])
    {
        $options = $this->getImportValueOption();
        
        // get default value
        if (array_has($options, $value)) {
            return $value;
        }

        //
        foreach ($options as $k => $v) {
            if ($v == $value) {
                return $k;
            }
        }
        return null;
    }
}
