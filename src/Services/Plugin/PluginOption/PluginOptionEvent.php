<?php

namespace Exceedone\Exment\Services\Plugin\PluginOption;

class PluginOptionEvent extends PluginOptionBase
{
    public $is_modal = false;
    public $event_type;
    public $page_type;

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
