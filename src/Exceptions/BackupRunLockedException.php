<?php

namespace Exceedone\Exment\Exceptions;

use Exceedone\Exment\Model\BackupRun;

/**
 * A backup or a restore was asked while another is not over (ISS02943).
 */
class BackupRunLockedException extends \Exception
{
    public function __construct(string $message, public ?BackupRun $active = null)
    {
        parent::__construct($message);
    }
}
