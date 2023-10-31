<?php

namespace Exceedone\Exment\Enums;

/**
 * System Table Name List.
 *
 * @method static SystemTableName SYSTEM()
 */
class LogAction extends EnumBase
{
    public const UPLOAD = 'Upload';
    public const DOWNLOAD = 'Download';
    public const DELETE = 'Delete';
    public const ATTACH_FILE = '添付ファイル';
}
