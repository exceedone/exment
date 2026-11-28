<?php

namespace Exceedone\Exment\Exceptions;

/**
 * A backup that did not complete (the database dump failed, or a file was not made).
 * The message is shown to the administrator: it has no password.
 */
class BackupFailedException extends \Exception
{
}
