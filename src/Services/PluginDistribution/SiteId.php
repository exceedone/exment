<?php

namespace Exceedone\Exment\Services\PluginDistribution;

use Exceedone\Exment\Model\System;

/**
 * The id of the site (ISS00419-421): a UUID made once by the migration (not
 * the domain: a site may change its domain). The market and the licenses of
 * the plugins know the site by it. A SaaS gives its own with EXMENT_SITE_ID
 * (the uuid of the tenant), which comes first.
 */
class SiteId
{
    public static function get(): ?string
    {
        $given = config('exment.site_id');
        if (is_string($given) && trim($given) !== '') {
            return strtolower(trim($given));
        }
        $made = System::site_id();
        return is_string($made) && $made !== '' ? $made : null;
    }
}
