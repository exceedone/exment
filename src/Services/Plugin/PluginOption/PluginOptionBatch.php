<?php

namespace Exceedone\Exment\Services\Plugin\PluginOption;

class PluginOptionBatch extends PluginOptionBase
{
    public $command_options = [];

    public function __construct($options = [])
    {
        if (isset($options['command_options'])) {
            $this->command_options = $options['command_options'];
        }
    }
}
