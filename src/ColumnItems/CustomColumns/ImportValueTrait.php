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
        $result = true;
        $options = $this->getImportValueOption();
        
        // not default value
        if (!array_has($options, strval($value))) {
            foreach ($options as $k => $v) {
                if ($v == $value) {
                    $value = $k;
                    break;
                }
            }

            $result = false;
        }

        return [
            'result' => $result,
            'value' => $value,
            'message' => !$result ? exmtrans('custom_value.import.message.select_item_not_found', [
                'column_view_name' => $this->label(),
                'value_options' => implode(exmtrans('common.separate_word'), collect($options)->keys()->toArray())
            ]) : null
        ];
    }
}
