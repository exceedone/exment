<?php

namespace Exceedone\Exment\Middleware;

use Exceedone\Exment\Services\BackupRestore\BackupRuns;
use Illuminate\Http\Request;

/**
 * While a restore goes on (ISS02943), the page of the backup, the page of a run and the state of
 * the run are answered here: first of all the middleware, before the maintenance (the restore
 * has the site down), the session, the login, the permissions and the database (the restore
 * replaces them). The pages are a page of the progress without anything of the database; its
 * script reads the state with the token kept in the tab (BackupRunStateController), or only
 * says to sign in again after the end. Never a page of an error. Any other request, and any
 * request when no restore goes on, goes on as before (the maintenance answers 503).
 */
class BackupRestoreGuard
{
    /**
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }
        $state = static::stateOf($request);
        if (!isset($state) && !static::isPageOfTheBackup($request)) {
            return $next($request);
        }
        try {
            $status = BackupRuns::restoringStatus();
            if (!isset($status)) {
                return $next($request);
            }
            // the state of a run (its token, a few reads a minute): the maintenance does not stop it
            if (isset($state)) {
                return static::state($request, $state);
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
     * The state of the run, as its route does (BackupRunStateController): 404 for anything else.
     */
    protected static function state(Request $request, string $uuid): \Symfony\Component\HttpFoundation\Response
    {
        try {
            return (new \Exceedone\Exment\Controllers\BackupRunStateController())->show($request, $uuid);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response('', $e->getStatusCode(), ['Cache-Control' => 'no-store']);
        } catch (\Throwable $e) {
            return response('', 404, ['Cache-Control' => 'no-store']);
        }
    }

    /**
     * The uuid of {prefix}/backup/runs/{uuid}/state, else null.
     */
    public static function stateOf(Request $request): ?string
    {
        $path = static::pathAfterPrefix($request);
        if (isset($path) && preg_match('#\Abackup/runs/([0-9a-f\-]{36})/state\z#', $path, $match) === 1) {
            return $match[1];
        }
        return null;
    }

    /**
     * The path after the prefix of the admin pages (null: not one of them).
     */
    protected static function pathAfterPrefix(Request $request): ?string
    {
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        $path = trim($request->path(), '/');
        if ($prefix === '') {
            return $path;
        }
        if (!str_starts_with($path . '/', $prefix . '/')) {
            return null;
        }
        return trim(substr($path, strlen($prefix)), '/');
    }

    /**
     * {prefix}/backup or {prefix}/backup/runs/{uuid} (not the state, the status or the log).
     */
    public static function isPageOfTheBackup(Request $request): bool
    {
        $path = static::pathAfterPrefix($request);
        return isset($path) && ($path === 'backup' || preg_match('#\Abackup/runs/[0-9a-f\-]{36}\z#', $path) === 1);
    }
}
