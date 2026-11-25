<?php

namespace Exceedone\Exment\Events;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;

/**
 * A row came back from the trash (CustomValue::restore()), from any page or the API
 * (ISS00334). A plugin or an application listens with Event::listen(CustomValueRestored::class, ...).
 * The rows of the child tables that came back with it do not get one of their own.
 */
class CustomValueRestored
{
    public CustomTable $custom_table;

    public function __construct(public CustomValue $custom_value)
    {
        $this->custom_table = $custom_value->custom_table;
    }
}
