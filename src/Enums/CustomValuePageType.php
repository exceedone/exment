<?php

namespace Exceedone\Exment\Enums;

class CustomValuePageType extends EnumBase
{
    const CREATE = 'create';
    const EDIT = 'edit';
    const GRID = 'grid';
    const SHOW = 'show';
    const DELETE = 'delete';
 
    // For page validation ----------------------------------------------------
    const EXPORT = 'export';
    const IMPORT = 'import';
    
    const GRIDMODAL = 'gridmodal';

    public static function getFormDataType($pageType){
        switch($pageType){
            case static::CREATE:
                return FormDataType::CREATE;
            case static::EDIT:
                return FormDataType::EDIT;
            case static::SHOW:
                return FormDataType::SHOW;
        }
    }
}
