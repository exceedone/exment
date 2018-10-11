<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\EmbeddedForm;
use Encore\Admin\Form\Field  as AdminField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class Embeds extends AdminField\Embeds
{
    /**
     * get fields in NestedEmbeddedForm
     */
    public function fields(){
        return $this->buildEmbeddedForm()->fields();
    }
}
