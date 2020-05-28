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
        if (!array_has($options, $value)) {
            if (!is_array($value)) {
                $k = array_search($value, $options);
                if ($k === false) {
                    $result = false;
                }  else {
                    $value = $k;
                }
            } else {
                $list = [];
                foreach ($value as $v) {
                    $k = array_search($v, $options);
                    if ($k === false) {
                        break;
                    }
                    $list[] = $k;
                }
    
                if (count($value) == count($list)) {
                    $value = $list;
                } else {
                    $result = false;
                }
            }
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
