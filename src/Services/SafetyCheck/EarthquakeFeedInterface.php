<?php

namespace Exceedone\Exment\Services\SafetyCheck;

interface EarthquakeFeedInterface
{
    /**
     * @param int $limit
     * @return array<int, array<string, mixed>> parsed bulletins, oldest first
     */
    public function fetchRecent(int $limit = 10): array;
}
