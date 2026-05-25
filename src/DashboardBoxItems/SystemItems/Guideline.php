<?php

namespace Exceedone\Exment\DashboardBoxItems\SystemItems;

class Guideline
{
    /**
     * get header
     */
    // @phpstan-ignore-next-line
    public function header()
    {
        return null;
    }

    /**
     * get footer
     */
    // @phpstan-ignore-next-line
    public function footer()
    {
        return null;
    }

    /**
     * get html body
     */
    // @phpstan-ignore-next-line
    public function body()
    {
        // @phpstan-ignore-next-line
        return view('exment::dashboard.system.guideline')->render() ?? null;
    }
}
