<?php

namespace Exceedone\Exment\Model;

/**
 * Told about each write of a custom value's JSON `value` that Exment can see coming, before its
 * SQL runs (W6). Observation only: an observer cannot change or stop the write, and the
 * production implementation (NullValueWriteObserver) does nothing.
 *
 * Seen: a model save through ValueWriter::prepare (prepared), a save with
 * disable_saving_event (saving_disabled) or restore_revision (restore_revision), and the
 * set-based ValueBulkWriter statements (bulk_remove_key, bulk_set_key). NOT seen: saveQuietly /
 * withoutEvents, query-builder or raw SQL writes (plugins, safety progress), PDO exec and the
 * mysql process of bulkinsert / restore (see BulkLoadPreflight), other processes and old
 * workers. Detection after the fact is not enforcement.
 */
interface ValueWriteObserver
{
    public function observe(ValueWriteObservation $observation): void;
}
