<?php
namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model\CustomValue;

/**
 * Plugin (Trigger) base class
 */
class PluginValidatorBase
{
    use PluginBase;
    
    public $custom_table;
    public $original_value;
    public $input_value;
    protected $messages = [];

    public function __construct($plugin, $custom_table, $original_value, $input_value)
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        $this->input_value = $input_value;
        
        if ($original_value instanceof CustomValue) {
            $this->original_value = $original_value;
        } elseif (isset($original_value) && isset($custom_table)) {
            $this->original_value = $custom_table->getValueModel($original_value);
        }
    }

    public function validate()
    {
    }

    public function messages()
    {
        $messages = [];

        foreach ($this->messages as $key => $message) {
            if (!is_array($message)) {
                $message = [$message];
            }
            if (strpos($key, 'value.') !== 0) {
                $key = "value.$key";                
            }
            $messages[$key] = $message;
        }

        return $messages;
    }
}
