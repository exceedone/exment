<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns {
    use Exceedone\Exment\Tests\Unit\ClipboardImageFixture;

    function admin_urls(...$segments): string
    {
        return 'http://fixture.invalid/admin/'.implode('/', $segments);
    }

    function admin_url(string $path, array $segments = []): string
    {
        return admin_urls($path, ...$segments);
    }

    function session()
    {
        return ClipboardImageFixture::$session;
    }

    function make_uuid(): string
    {
        return ClipboardImageFixture::FILE_UUID;
    }

    function path_join(...$segments): string
    {
        return implode('/', $segments);
    }

    function exmtrans(string $key): string
    {
        return $key;
    }

    function public_form_url(): string
    {
        return 'http://fixture.invalid/public';
    }
}

namespace App\Plugins\PmPortal {
    function admin_urls(...$segments): string
    {
        return \Exceedone\Exment\ColumnItems\CustomColumns\admin_urls(...$segments);
    }
}
