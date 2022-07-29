<?php

namespace Exceedone\Exment\Enums;

class FormLabelType extends EnumBase
{
    public const FORM_DEFAULT = "form_default";
    public const HORIZONTAL = "horizontal";
    public const VERTICAL = "vertical";
    public const HIDDEN = "hidden";

    public static function getFormLabelTypes()
    {
        return [
            static::HORIZONTAL,
            static::VERTICAL,
            static::HIDDEN,
        ];
    }

    public static function getFieldLabelTypes()
    {
        return [
            static::FORM_DEFAULT,
            static::HORIZONTAL,
            static::VERTICAL,
            static::HIDDEN,
        ];
    }
}
