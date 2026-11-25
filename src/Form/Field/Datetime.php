<?php

namespace Exceedone\Exment\Form\Field;

use Exceedone\Exment\Services\UserTimezone;

/**
 * A date and time input in the time zone of the user (ISS02835).
 * The value of the database (the time zone of the site) is shown in the time zone of the user,
 * and the typed value is saved in the time zone of the site. Nothing changes when they are the same.
 */
class Datetime extends \ExmentAdminCore\Admin\Form\Field\Datetime
{
    /**
     * @param array<string, mixed>|mixed $data
     * @return void
     */
    public function fill($data)
    {
        $before = $this->value;
        parent::fill($data);

        // only the value read from the record now (not a default, not a value set before)
        if (empty($before) && !empty($this->value) && !is_array($this->value)) {
            $this->value = UserTimezone::formatToUser($this->value);
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function prepare($value)
    {
        $value = parent::prepare($value);

        return UserTimezone::formatFromUser($value);
    }
}
