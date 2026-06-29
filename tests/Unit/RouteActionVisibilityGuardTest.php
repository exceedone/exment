<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Providers\RouteServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;

/**
 * GUARD test — born from commit 9f6976072ea166c845cc28310602e899776f609a
 * ("refactor: update method signatures to include return types and improve null checks").
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * That commit had to fix the SAME mistake in many places: a controller method that is
 * the target of a route was declared `protected` (or did not exist), which is fatal
 * under Laravel 12+. In Laravel <= 10 the framework's base Controller had a magic
 * `callAction()` that could invoke protected/private actions; the modern base
 * Controller (Laravel 11/12) is an empty abstract class, so the route dispatcher calls
 * `$controller->{$method}(...)` directly. A non-public routed method then throws
 *   Error: Call to protected method ...::method() from ...
 * and a missing method throws
 *   BadMethodCallException: Method ...::method() does not exist
 * — both surface as HTTP 500 on the very first request to that route.
 *
 * The commit made these routed actions public / removed dead routes, in at least:
 *   - QrCodeController::scanRedirect            (was protected)            [BUG-05]
 *   - JanCodeController::scanRedirect/listTable/assignJancode (was protected)
 *   - FileController::uploadTempFile / uploadTempImagePublicForm (was protected) [BUG-07]
 *   - RouteServiceProvider system/update,system/version -> removed dead routes [BUG-30]
 *
 * The per-bug tests (BugBUG05Test, BugBUG07Test, BugBUG30Test) pin those individual
 * cases. THIS test is the umbrella guard so the WHOLE CLASS of mistake cannot be
 * re-introduced on ANY Exment web route in the future: every routed
 * `Exceedone\Exment\Controllers\*@method` action must (1) exist and (2) be public.
 *
 * WHAT IT CHECKS
 * --------------
 * Registers the real Exment WEB route groups (mapExmentWebRotes +
 * mapExmentAnonymousWebRotes + mapExmentInstallWebRotes) onto a fresh, isolated Router
 * via reflection (so the app's real routes are untouched), then for every route whose
 * action is `Class@method` with Class under Exceedone\Exment\Controllers asserts:
 *   - testEveryRoutedExmentActionExists  : method_exists(Class, method)  (no BadMethodCall)
 *   - testEveryRoutedExmentActionIsPublic: ReflectionMethod->isPublic()  (no protected dispatch)
 *
 * It passes on the post-9f6976072 code and FAILS the moment a routed action is made
 * protected/private or points at a non-existent method again.
 *
 * SCOPE / NO DATABASE
 * -------------------
 * Only the WEB route groups are registered — their map methods issue no query (they
 * just call $router->get/post/...). The API groups (mapExmentApiRotes /
 * mapExmentAnonymousApiRotes) are intentionally EXCLUDED because they call
 * System::api_available() which hits the DB; all of the commit's visibility fixes live
 * in the web groups, so they are fully covered here. Coverage is reported explicitly
 * (testCoreWebRouteGroupIsCovered) so it can never silently drop to zero. Uses an
 * isolated Router + swap/restore; nothing runs migrate:fresh or touches the DB.
 * UnitTestBase boots the app only.
 */
class RouteActionVisibilityGuardTest extends UnitTestBase
{
    /** Only audit routes whose controller lives in the Exment controllers namespace. */
    private const EXMENT_CONTROLLER_MARKER = 'Exment\\Controllers';

    /** The Exment WEB route-map methods to register (all DB-free). */
    private const WEB_MAP_METHODS = [
        'mapExmentWebRotes',
        'mapExmentAnonymousWebRotes',
        'mapExmentInstallWebRotes',
    ];

    /** @var array<string, array{uri: string, class: string, method: string}>|null cache: "Class@method" => info */
    private $actionsCache = null;

    /** @var list<string> map methods successfully registered */
    private $coveredGroups = [];

    /** @var list<string> map methods skipped (with reason) */
    private $skippedGroups = [];

    /**
     * Register the real Exment web route groups on a fresh, isolated Router and return
     * the de-duplicated set of Exment-controller "Class@method" actions they target.
     * The app's own router/routes are swapped out and restored.
     *
     * @return array<string, array{uri: string, class: string, method: string}>
     */
    private function exmentControllerActions(): array
    {
        if ($this->actionsCache !== null) {
            return $this->actionsCache;
        }

        /** @var \Illuminate\Contracts\Foundation\Application $app */
        $app = $this->app;
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $originalRouter = $app->bound('router') ? $app->make('router') : null;

        $actions = [];
        $covered = [];
        $skipped = [];

        try {
            foreach (self::WEB_MAP_METHODS as $mapMethod) {
                // Fresh router per group so a failing group cannot corrupt the others.
                $freshRouter = new Router($events, $app);
                $app->instance('router', $freshRouter);
                Route::clearResolvedInstance('router');
                Route::swap($freshRouter);

                try {
                    $provider = new RouteServiceProvider($app);
                    $rm = new ReflectionMethod(RouteServiceProvider::class, $mapMethod);
                    $rm->setAccessible(true);
                    $rm->invoke($provider);

                    foreach ($freshRouter->getRoutes() as $route) {
                        /** @var IlluminateRoute $route */
                        $action = $route->getActionName(); // "FQCN@method" | "Closure" | "FQCN"
                        if (!str_contains($action, '@')) {
                            continue; // closures / invokable handled elsewhere; not the bug class
                        }
                        [$class, $method] = explode('@', $action, 2);
                        $class = ltrim($class, '\\');
                        if (!str_contains($class, self::EXMENT_CONTROLLER_MARKER)) {
                            continue; // only audit Exment controllers
                        }
                        $actions[$class . '@' . $method] = [
                            'uri'    => $route->uri(),
                            'class'  => $class,
                            'method' => $method,
                        ];
                    }
                    $covered[] = $mapMethod;
                } catch (\Throwable $e) {
                    // A web map needing the DB (unexpected) is recorded, not silently dropped.
                    $skipped[] = $mapMethod . ' (' . $e->getMessage() . ')';
                }
            }
        } finally {
            if ($originalRouter !== null) {
                $app->instance('router', $originalRouter);
                Route::clearResolvedInstance('router');
                Route::swap($originalRouter);
            }
        }

        $this->coveredGroups = $covered;
        $this->skippedGroups = $skipped;
        $this->actionsCache = $actions;

        return $actions;
    }

    /**
     * Coverage guard: the core web route group MUST be auditable, otherwise the two
     * assertions below would pass vacuously. Keeps the guard honest.
     *
     * @return void
     */
    public function testCoreWebRouteGroupIsCovered(): void
    {
        $actions = $this->exmentControllerActions();

        $this->assertContains(
            'mapExmentWebRotes',
            $this->coveredGroups,
            'The core Exment web route group could not be registered for auditing; skipped: '
            . implode('; ', $this->skippedGroups)
        );

        $this->assertNotEmpty(
            $actions,
            'No Exment-controller routes were collected — the guard would be vacuous. Skipped groups: '
            . implode('; ', $this->skippedGroups)
        );
    }

    /**
     * Every routed Exment-controller action must EXIST (no BadMethodCallException / 500).
     * Catches the BUG-30 class: a route pointing at a method that was never implemented
     * or was removed/renamed.
     *
     * @return void
     */
    public function testEveryRoutedExmentActionExists(): void
    {
        $missing = [];
        foreach ($this->exmentControllerActions() as $info) {
            if (!method_exists($info['class'], $info['method'])) {
                $missing[] = $info['uri'] . '  ->  ' . $info['class'] . '::' . $info['method'] . '()';
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These Exment web routes target controller methods that DO NOT EXIST, so dispatching them\n"
            . "throws BadMethodCallException (HTTP 500). Implement the method or remove the route:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * Every routed Exment-controller action must be PUBLIC. Under Laravel 12 the route
     * dispatcher calls the action directly, so a protected/private routed method is a
     * fatal Error on the first request. This is the exact recurring mistake that commit
     * 9f6976072 fixed across QrCode/JanCode/FileController.
     *
     * @return void
     * @throws \ReflectionException
     */
    public function testEveryRoutedExmentActionIsPublic(): void
    {
        $nonPublic = [];
        foreach ($this->exmentControllerActions() as $info) {
            if (!method_exists($info['class'], $info['method'])) {
                continue; // existence is asserted by the test above
            }
            $rm = new ReflectionMethod($info['class'], $info['method']);
            if (!$rm->isPublic()) {
                $visibility = $rm->isPrivate() ? 'private' : 'protected';
                $nonPublic[] = $info['uri'] . '  ->  ' . $info['class'] . '::' . $info['method']
                    . '()  [' . $visibility . ']';
            }
        }

        $this->assertSame(
            [],
            $nonPublic,
            "These Exment web routes target NON-PUBLIC controller methods. Laravel 12 dispatches route\n"
            . "actions directly, so a protected/private routed method throws a fatal Error (HTTP 500) on\n"
            . "the first request (the bug class fixed in commit 9f6976072). Make each action `public`:\n  "
            . implode("\n  ", $nonPublic)
        );
    }
}
