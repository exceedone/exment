<?php

namespace Exceedone\Exment\Services\Sync;

use Exceedone\Exment\Model\SyncMapping;

/**
 * A connector with work of its own at the end of a run (ISS00113): what can be done only when
 * all the items of the run are written, as the parent of an item that came before its parent, or
 * a check of its own of the items gone from the service.
 */
interface SyncFinisher
{
    /**
     * @param bool $complete no item of the run failed
     * @return array<int, string> notes for the run (none: an empty list)
     */
    public function finished(SyncMapping $mapping, bool $dry, bool $complete): array;
}
