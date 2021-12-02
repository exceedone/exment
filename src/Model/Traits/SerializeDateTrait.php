<?php

namespace Exceedone\Exment\Model\Traits;

use DateTimeInterface;

trait SerializeDateTrait
{
    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format($this->getDateFormat());
    }
}
