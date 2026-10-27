<?php

namespace Exceedone\Exment\Form\Field;

use ExmentAdminCore\Admin\Form\Field;

/**
 * Hidden field. Use for hidden option (form).
 * Also carries a column holding several values, see form/field/hidden.blade.php.
 */
class Hidden extends Field\Hidden
{
    protected $view = 'exment::form.field.hidden';
}
