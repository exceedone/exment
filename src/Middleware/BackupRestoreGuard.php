<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Services\BackupRestore\BackupRuns;
use Illuminate\Http\Request;

/**
 * While a restore goes on (ISS02943), the page of the backup and the page of a run are answered
 * here, before the session, the login, the permissions and the database (the restore replaces
 * them): a page of the progress, without anything of the database. Its script reads the token of
 * the state kept in the tab (BackupRunStateController); without one it only says to sign in again
 * after the end. Never a page of an error.
 */
class BackupRestoreGuard
{
    /**
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true) || !static::isPageOfTheBackup($request)) {
            return $next($request);
        }
        try {
            $status = BackupRuns::restoringStatus();
            if (!isset($status)) {
                return $next($request);
            }
            $uuid = strval($status['uuid'] ?? '');
            $prefix = trim(strval(config('admin.route.prefix')), '/');
            $base = '/' . ltrim(($prefix === '' ? '' : $prefix . '/') . 'backup/runs', '/');
            $html = view('exment::backup.restoring_run', [
                'uuid' => $uuid,
                'stateUrl' => $base . '/' . $uuid . '/state',
                'loginUrl' => '/' . ltrim(($prefix === '' ? '' : $prefix . '/') . 'auth/login', '/'),
                'progress' => intval($status['progress'] ?? 0),
                'stageLabel' => strval($status['stage_label'] ?? ''),
            ])->render();
        } catch (\Throwable $e) {
            // the page of the maintenance as it is: never a page of an error here
            return $next($request);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * {prefix}/backup or {prefix}/backup/runs/{uuid} (not the state, the status or the log).
     */
    public static function isPageOfTheBackup(Request $request): bool
    {
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $path = trim($request->path(), '/');
        if ($prefix !== '') {
            if (!str_starts_with($path . '/', $prefix . '/')) {
                return false;
            }
            $path = trim(substr($path, strlen($prefix)), '/');
        }
        return $path === 'backup' || preg_match('#\Abackup/runs/[0-9a-f\-]{36}\z#', $path) === 1;
    }
}
