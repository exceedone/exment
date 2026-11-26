<?php

namespace Exceedone\Exment\Services\Sync;

/**
 * What a connector says went wrong (ISS00113): shown in the run and in the bell, so it never has
 * a secret in it.
 */
class SyncException extends \RuntimeException
{
}
