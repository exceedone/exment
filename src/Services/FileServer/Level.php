<?php

namespace Exceedone\Exment\Services\FileServer;

/**
 * File server: the levels of the permissions (ISS00293).
 * A higher level can do all that a lower one can.
 */
class Level
{
    public const NONE = 0;
    // see the folders and files, download, preview
    public const VIEW = 1;
    // add folders and files (not change or remove those of others)
    public const UPLOAD = 2;
    // change, move, remove, add versions, check out
    public const EDIT = 3;
    // change the permissions and the settings of the workspace, remove from the trash for good
    public const MANAGE = 4;

    /**
     * @return int[]
     */
    public static function all(): array
    {
        return [static::VIEW, static::UPLOAD, static::EDIT, static::MANAGE];
    }

    public static function isValid(int $level): bool
    {
        return in_array($level, static::all(), true);
    }

    public static function label(int $level): string
    {
        switch ($level) {
            case static::VIEW:
                return exmtrans('file_server.level_view');
            case static::UPLOAD:
                return exmtrans('file_server.level_upload');
            case static::EDIT:
                return exmtrans('file_server.level_edit');
            case static::MANAGE:
                return exmtrans('file_server.level_manage');
        }
        return '';
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (static::all() as $level) {
            $options[$level] = static::label($level);
        }
        return $options;
    }
}
