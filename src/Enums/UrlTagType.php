<?php

namespace Exceedone\Exment\Enums;

use League\Flysystem\Filesystem;
use Exceedone\Exment\Storage\Adapter;

class UrlTagType extends EnumBase
{
    public const NONE = 'none';
    public const TOP = 'top';
    public const BLANK = 'blank';
    public const MODAL = 'modal';
}
