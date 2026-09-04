<?php

namespace Exceedone\Exment\Services\SystemRequire;

class FilePermissionInstaller extends FilePermission
{
    // @phpstan-ignore-next-line
    protected $checkPaths = [
        '.env',
        'app',
        'config',
        'public',
        'resources',
        'storage',
        'bootstrap/cache',
    ];


    public function getExplain(): string
    {
        return exmtrans('system_require.type.file_permission_installer.explain');
    }
}
