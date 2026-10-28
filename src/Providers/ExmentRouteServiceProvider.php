<?php

namespace Exceedone\Exment\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

/**
 * Base route service provider for Exment. Loads the routes of the provider itself only.
 *
 * Laravel keeps the application route loader in a *static* property of
 * Illuminate\Foundation\Support\Providers\RouteServiceProvider, set by withRouting() in
 * bootstrap/app.php. That property is shared with every subclass, so each Exment route
 * provider would re-run the application route files (routes/web.php ...) before calling
 * its own map().
 *
 * Besides loading those files once per provider on every request, the re-run registers
 * the application routes again *after* Exment's own routes, and Laravel keeps only the
 * route registered last for the same method and URI. With an empty ADMIN_ROUTE_PREFIX
 * the Exment home route is "/" - the same URI as the welcome route of a new Laravel
 * application - so the admin screen was unreachable.
 *
 * The application routes are still loaded, once, by the application's own route provider.
 */
abstract class ExmentRouteServiceProvider extends ServiceProvider
{
    /**
     * Load the routes of this provider, without re-running the application route files.
     *
     * The application loader is hidden from the parent implementation for the time it
     * loads the routes of this provider, and put back right after, so the application's
     * own route provider still loads them.
     *
     * @return void
     */
    protected function loadRoutes()
    {
        $appRoutes = self::$alwaysLoadRoutesUsing;

        parent::loadRoutesUsing(null);

        try {
            parent::loadRoutes();
        } finally {
            parent::loadRoutesUsing($appRoutes);
        }
    }
}
