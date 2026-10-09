<?php

namespace Exceedone\Exment\Services\SafetyCheck;

interface EarthquakeFeedInterface
{
    /**
     * @param int $limit
     * @return array
     */
    public function fetchRecent(int $limit = 10): array;
}
