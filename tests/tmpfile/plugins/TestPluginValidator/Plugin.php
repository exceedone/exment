<?php

namespace App\Plugins\TestPluginValidator;

use Exceedone\Exment\Services\Plugin\PluginValidatorBase;

class Plugin extends PluginValidatorBase
{
    public function validate()
    {
        $result = true;

        // 入力値を取得する
        $integer = array_get($this->input_value, 'integer');
        $currency = array_get($this->input_value, 'currency');

        // 元の値を取得する
        $old_integer = $this->original_value->getValue('integer');
        $old_currency = $this->original_value->getValue('currency');

        if (isset($integer) && isset($currency)) {
            if (isset($old_integer) && $old_integer > $integer) {
                $this->messages['integer'] = '以前より大きな値を入力してください。';
                $result = false;
            }
            if (isset($old_currency) && $old_currency > $currency) {
                $this->messages['currency'] = '以前より大きな値を入力してください。';
                $result = false;
            }
        } elseif (isset($integer) || isset($currency)) {
            $this->messages['integer'] = '整数と通貨は同時に入力してください。';
            $result = false;
        }

        return $result;
    }
}
