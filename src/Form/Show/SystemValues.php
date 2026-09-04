<?php

namespace Exceedone\Exment\Form\Show;

use Exceedone\Exment\Form\SystemValuesTrait;
use Encore\Admin\Show\AbstractField;

class SystemValues extends AbstractField
{
    use SystemValuesTrait;

    public $escape = false;

    // @phpstan-ignore-next-line
    public function render($options = [])
    {
        if (boolval(array_get($options, 'withTrashed'))) {
            $this->withTrashed = true;
        }
        return $this->renderSystemItem($this->model);
    }
}
