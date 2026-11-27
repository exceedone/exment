<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Services\BackupRestore\BackupRuns;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The state of a restore of the screen while the site is in maintenance (ISS02943).
 *
 * A restore replaces the database (the sessions and the users with it): the page of the run reads
 * its progress here, without the session, the login, the permissions or the database. The page has
 * a token given to it once when the restore was started (BackupRuns::issueStateToken).
 *
 * - While the run goes on: the progress only (BackupRuns::stateFields).
 * - When it ended: its status and its percent, once; the token is gone after it.
 * - A few reads a minute (a file, BackupRuns::stateRateAllowed); anything else: 404.
 */
class BackupRunStateController extends Controller
{
    public const HEADER = 'X-Exment-Run-Token';

    public function show(Request $request, string $uuid): \Illuminate\Http\JsonResponse
    {
        // no state for this run (nor a token): nothing is written, nothing is said
        if (!BackupRuns::hasStateToken($uuid)) {
            abort(404);
        }
        if (!BackupRuns::stateRateAllowed($uuid, strval($request->ip()))) {
            abort(429);
        }
        $token = strval($request->headers->get(static::HEADER));
        if (!BackupRuns::checkStateToken($uuid, $token)) {
            abort(404);
        }
        $status = BackupRuns::readStatusFile($uuid);
        if (!isset($status)) {
            abort(404);
        }

        if (!boolval($status['active'] ?? false)) {
            // the end, read once: the token is used up
            BackupRuns::forgetStateToken($uuid);
            $run = BackupRuns::endedStateFields($status);
        } else {
            $run = BackupRuns::stateFields($status);
        }

        return response()->json(['run' => $run], 200, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
