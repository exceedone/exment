<?php

namespace Exceedone\Exment\Enums;

class DocumentType extends EnumBase
{
    public const EXCEL = 'excel';
    public const PDF = 'pdf';

    public static function getSelectableString()
    {
        return static::EXCEL.','.static::PDF;
    }
    public static function getExtension($document_type)
    {
        if ($document_type == static::PDF) {
            return '.pdf';
        } else {
            return '.xlsx';
        }
    }
}
