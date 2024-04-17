<?php

namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Plugin as PluginModel;

/**
 * Plugin (Validator) base class
 */
class PluginValidatorBase
{
    use PluginBase;

    /**
     * Tagret custom table
     *
     * @var CustomTable
     */
    public $custom_table;

    /**
     * original custom value
     *
     * @var CustomValue|null
     */
    public $original_value;

    /**
     * Input value
     *
     * @var array
     */
    public $input_value;

    /**
     * Whether this validation is called.
     *
     * @var mixed
     */
    public $called_type;

    /**
     * Error messages. If error, Please set key and value.
     *
     * @var array
     */
    protected $messages = [];

    /**
     * Construct.
     *
     * @param PluginModel $plugin
     * @param CustomTable|null $custom_table
     * @param $original_value
     * @param array $options
     *      'called_type' => Whether this validation is called.
     */
    public function __construct(PluginModel $plugin, ?CustomTable $custom_table, $original_value, array $options = [])
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        $this->input_value = array_get($options, 'input_value');
        $this->called_type = array_get($options, 'called_type');

        if ($original_value instanceof CustomValue) {
            $this->original_value = $original_value;
        } elseif (!is_nullorempty($original_value) && !is_nullorempty($custom_table)) {
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
    
    public function validateDestroy($model)
    {
    }
}
