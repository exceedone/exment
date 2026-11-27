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
 * a token given when the restore was started (BackupRuns::issueStateToken); only the progress is
 * answered (no log, no path). Anything else: 404.
 */
class BackupRunStateController extends Controller
{
    public const HEADER = 'X-Exment-Run-Token';

    public function show(Request $request, string $uuid): \Illuminate\Http\JsonResponse
    {
        $token = strval($request->headers->get(static::HEADER));
        if (!BackupRuns::checkStateToken($uuid, $token)) {
            abort(404);
        }
        $status = BackupRuns::readStatusFile($uuid);
        if (!isset($status)) {
            abort(404);
        }

        return response()->json(['run' => BackupRuns::stateFields($status)], 200, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
