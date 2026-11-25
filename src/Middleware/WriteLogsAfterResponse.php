<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Services\LogManagement\LogWriter;
use Illuminate\Http\Request;

/**
 * The records of the logs of a web request are written after its response is sent (ISS02903,
 * LogWriter): the request starts them waiting, its end (terminate) writes them.
 */
class WriteLogsAfterResponse
{
    /**
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        LogWriter::begin();
        return $next($request);
    }

    /**
     * @param Request $request
     * @param mixed $response
     */
    public function terminate($request, $response): void
    {
        LogWriter::end();
    }
}
