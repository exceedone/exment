<?php

namespace Exceedone\Exment\Tests\Feature;

/**
 * No two routes have the same name: else "php artisan route:cache" stops with "Another route
 * has already been assigned name" (the routes of a plugin under "api" and "webapi", ISS02880),
 * and a site cannot cache its routes.
 */
class RouteNamesUniqueTest extends FeatureTestBase
{
    public function testEveryNameOnce(): void
    {
        $counts = [];
        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && $name !== '') {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        $this->assertNotEmpty($counts);
        $this->assertSame([], array_keys(array_filter($counts, function ($count) {
            return $count > 1;
        })));
    }
}
