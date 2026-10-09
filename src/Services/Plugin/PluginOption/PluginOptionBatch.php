<?php

namespace Exceedone\Exment\Services\Plugin\PluginOption;

class PluginOptionBatch extends PluginOptionBase
{
    // @phpstan-ignore-next-line
    public $command_options = [];

    // @phpstan-ignore-next-line
    public function __construct($options = [])
    {
        if (isset($options['command_options'])) {
            $this->command_options = $options['command_options'];
        }
    }
}
