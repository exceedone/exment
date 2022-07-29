<?php

namespace Exceedone\Exment\Enums;

class FilterKind extends EnumBase
{
    public const VIEW = 'view';
    public const WORKFLOW = 'workflow';
    public const FORM = 'form';
    public const OPERATION = 'operation';

    public static function FILTER_KIND_USE_DATE()
    {
        return [
            FilterKind::VIEW,
            FilterKind::FORM,
            FilterKind::WORKFLOW,
        ];
    }

    public static function useDate($filter_kind)
    {
        return in_array($filter_kind, static::FILTER_KIND_USE_DATE());
    }
}
