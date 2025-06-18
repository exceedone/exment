<?php

namespace Exceedone\Exment\Enums;

class OcrExtractionRoleType extends EnumBase
{
    public const SAME_LINE = 'same_line';
    public const NEXT_LINE = 'next_line';
    public const PREVIOUS_LINE = 'previous_line';
    public const AFTER_KEYWORD = 'after_keyword';
    public const BEFORE_KEYWORD = 'before_keyword';
    public const BELOW_KEYWORD = 'below_keyword';
    public const ABOVE_KEYWORD = 'above_keyword';
}
