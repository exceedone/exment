<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field  as AdminField;

class Embeds extends AdminField\Embeds
{
    /**
     * get fields in NestedEmbeddedForm
     */
    public function fields(){
        return $this->buildEmbeddedForm()->fields();
    }
}
