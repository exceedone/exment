<?php

namespace Exceedone\Exment\Exceptions;

/**
 * A restore that stopped: before the database was changed (nothing was restored), or midway
 * (the database may be partly restored). The message is shown to the administrator: it has no
 * password.
 */
class RestoreFailedException extends \Exception
{
    /** Whether the database was changed before the restore stopped */
    protected bool $changed = false;

    public static function nothingChanged(string $reason): self
    {
        $e = new self(strval(exmtrans('backup.message.restore_nothing_restored', ['reason' => $reason])));
        $e->changed = false;
        return $e;
    }

    public static function midway(string $reason): self
    {
        $e = new self(strval(exmtrans('backup.message.restore_stopped_midway', ['reason' => $reason])));
        $e->changed = true;
        return $e;
    }

    /**
     * The same failure, with a sentence added to its message (e.g. where the backup made before
     * the restore is).
     */
    public function withNote(string $note): self
    {
        $e = new self($this->getMessage() . ' ' . $note, 0, $this);
        $e->changed = $this->changed;
        return $e;
    }

    public function databaseChanged(): bool
    {
        return $this->changed;
    }
}
