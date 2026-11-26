<?php

namespace Exceedone\Exment\Services\Sync;

/**
 * The data of the data sync (ISS00113), as a connector gives it.
 *
 * A unit of the service (a kintone app, a Backlog project).
 */
class RemoteObject
{
    public function __construct(public string $key, public string $label)
    {
    }
}
