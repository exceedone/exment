<?php

namespace Exceedone\Exment\Enums;

class CustomValuePageType extends EnumBase
{
    public const CREATE = 'create';
    public const EDIT = 'edit';
    public const GRID = 'grid';
    public const SHOW = 'show';
    public const DELETE = 'delete';

    // For page validation ----------------------------------------------------
    public const EXPORT = 'export';
    public const IMPORT = 'import';

    public const GRIDMODAL = 'gridmodal';

    public static function getFormDataType($pageType)
    {
        switch ($pageType) {
            case static::CREATE:
                return FormDataType::CREATE;
            case static::EDIT:
                return FormDataType::EDIT;
            case static::SHOW:
                return FormDataType::SHOW;
        }
    }
}
