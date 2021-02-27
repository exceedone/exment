<?php

namespace Exceedone\Exment\Enums;

class FormLabelType extends EnumBase
{
    const FORM_DEFAULT = "form_default";
    const HORIZONTAL = "horizontal";
    const VERTICAL = "vertical";
    const HIDDEN = "hidden";

    public static function getFormLabelTypes(){
        return [
            static::HORIZONTAL,
            static::VERTICAL,
            static::HIDDEN,
        ];
    }

    public static function getFieldLabelTypes(){
        return [
            static::FORM_DEFAULT,
            static::HORIZONTAL,
            static::VERTICAL,
            static::HIDDEN,
        ];
    }
}
