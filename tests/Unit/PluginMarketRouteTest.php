<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;

class PluginMarketRouteTest extends TestCase
{
    public function testPluginMarketHasEditAndResourceActionRoutes(): void
    {
        $routes = Route::getRoutes();

        $editRoute = $routes->getByName('plugin.market.edit');
        $this->assertNotNull($editRoute);
        $this->assertSame($this->routeUri('plugin-market/{id}/edit'), $editRoute->uri());
        $this->assertSame(['GET', 'HEAD'], $editRoute->methods());
        $this->assertStringEndsWith('PluginController@edit', $editRoute->getActionName());

        $updateRoute = $routes->getByName('plugin.market.setting.update');
        $this->assertNotNull($updateRoute);
        $this->assertSame($this->routeUri('plugin-market/{id}'), $updateRoute->uri());
        $this->assertSame(['PUT'], $updateRoute->methods());
        $this->assertStringEndsWith('PluginController@update', $updateRoute->getActionName());

        $patchRoute = $this->findRouteByMethodAndUri('PATCH', $this->routeUri('plugin-market/{id}'));
        $this->assertNotNull($patchRoute);
        $this->assertStringEndsWith('PluginController@update', $patchRoute->getActionName());

        $deleteRoute = $routes->getByName('plugin.market.setting.delete');
        $this->assertNotNull($deleteRoute);
        $this->assertSame($this->routeUri('plugin-market/{id}'), $deleteRoute->uri());
        $this->assertSame(['DELETE'], $deleteRoute->methods());
        $this->assertStringEndsWith('PluginController@destroy', $deleteRoute->getActionName());
    }

    public function testPluginMarketSettingButtonUsesPluginMarketEditRoute(): void
    {
        $view = file_get_contents(exment_package_path('resources/views/plugin/market/index.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString("route('plugin.market.edit', \$plugin['local_db_id'])", $view);
        $this->assertStringNotContainsString("admin_url('plugin/' . \$plugin['local_db_id'] . '/edit')", $view);
    }

    private function routeUri(string $path): string
    {
        return trim(admin_base_path($path), '/');
    }

    private function findRouteByMethodAndUri(string $method, string $uri): ?IlluminateRoute
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
