<?php

namespace Exceedone\Exment\DashboardBoxItems\SystemItems;

class Guideline
{
    /**
     * get header
     */
    public function header()
    {
        return null;
    }

    /**
     * get footer
     */
    public function footer()
    {
        return null;
    }

    /**
     * get html body
     */
    public function body()
    {
        /** @phpstan-ignore-next-line Expression on left side of ?? is not nullable. */
        return view('exment::dashboard.system.guideline')->render() ?? null;
    }
}
