<?php

namespace Exceedone\Exment\Services\Plugin\PluginOption;

class PluginOptionEvent extends PluginOptionBase
{
    // @phpstan-ignore-next-line
    public $is_modal = false;
    // @phpstan-ignore-next-line
    public $event_type;
    // @phpstan-ignore-next-line
    public $page_type;

    // @phpstan-ignore-next-line
    public function __construct($options = [])
    {
        if (isset($options['is_modal'])) {
            $this->is_modal = $options['is_modal'];
        }
        if (isset($options['event_type'])) {
            $this->event_type = $options['event_type'];
        }
        if (isset($options['page_type'])) {
            $this->page_type = $options['page_type'];
        }
    }
}
