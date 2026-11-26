<?php

namespace Exceedone\Exment\Services\Sync;

use Exceedone\Exment\Model\SyncMapping;

/**
 * A connector that writes its items itself (ISS00113), instead of the field map of the engine:
 * one item may be several records (an issue with its comments and attachments) or need rules of
 * its own. The engine keeps the rest: the schedule, the lock, the runs, the dry run, the bell,
 * the link of the item (when a record is given back) and the page.
 *
 * write() throws a SyncException when the item cannot be written (the run lists it, with its
 * message); with $dry it writes nothing and tells what it would do.
 */
interface SyncWriter
{
    public function write(SyncMapping $mapping, RemoteItem $item, bool $dry): SyncWriteResult;
}
