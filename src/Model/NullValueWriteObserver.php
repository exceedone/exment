<?php

namespace Exceedone\Exment\Model;

/**
 * The production ValueWriteObserver: observes nothing.
 */
class NullValueWriteObserver implements ValueWriteObserver
{
    public function observe(ValueWriteObservation $observation): void
    {
    }
}
