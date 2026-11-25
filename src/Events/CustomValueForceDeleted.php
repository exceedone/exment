<?php

namespace Exceedone\Exment\Events;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;

/**
 * A row was deleted for good (CustomValue::forceDelete(), or any delete when
 * exment.delete_force_custom_value is on), from any page or the API (ISS00334). Sent after the
 * row, its files and its revisions are gone: the model keeps the values it had. A row of a child
 * table deleted with it sends one too.
 * A plugin or an application listens with Event::listen(CustomValueForceDeleted::class, ...).
 */
class CustomValueForceDeleted
{
    public CustomTable $custom_table;

    public function __construct(public CustomValue $custom_value)
    {
        $this->custom_table = $custom_value->custom_table;
    }
}
