<?php

namespace Exceedone\Exment\Exceptions;

/**
 * Thrown by a BulkLoadPreflight to refuse a bulk load before anything is written.
 * A BackupRestoreCheckException, so exment:restore reports it and returns 1 like its other checks;
 * exment:bulkinsert reports it and returns -1 like any error.
 */
class BulkLoadRejectedException extends BackupRestoreCheckException
{
}
