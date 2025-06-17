<?php

namespace Exceedone\Exment\Form\Field;

use OpenAdminCore\Admin\Form\Field\Display;

/**
 * Display for view only. Cannot save and update.
 * Use for viewonly option (form).
 */
class ViewOnly extends Display
{
    protected $view = 'exment::form.field.display';
}
