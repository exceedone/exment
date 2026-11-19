<?php

namespace Exceedone\Exment\Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Exment's API routes use the "api" middleware group and the "api" rate limiter
 * (RouteServiceProvider, config exment.api_max_rate_limit).
 *
 * The Laravel 10 app Kernel had "throttle:api" in the "api" group. Laravel 11+ adds it only when
 * bootstrap/app.php calls throttleApi(), so before the fix the API had no rate limit on Laravel 12.
 */
class ApiThrottleTest extends FeatureTestBase
{
    public function testTheApiGroupIsThrottledByTheApiLimiter(): void
    {
        $this->assertContains('throttle:api', app('router')->getMiddlewareGroups()['api']);
    }

    public function testAnExmentApiRouteIsThrottled(): void
    {
        $route = app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create(admin_url('api/template/search'), 'POST')
        );

        $this->assertContains(ThrottleRequests::class . ':api', app('router')->gatherRouteMiddleware($route));
    }

    public function testTooManyRequestsAreRefused(): void
    {
        $key = 'exment-api-throttle-test-' . short_uuid();
        RateLimiter::for('api', function () use ($key) {
            return Limit::perMinute(2)->by($key);
        });
        Route::middleware('api')->get('exment-api-throttle-test', function () {
            return 'ok';
        });

        $this->get('exment-api-throttle-test')->assertOk();
        $this->get('exment-api-throttle-test')->assertOk();
        $this->get('exment-api-throttle-test')->assertStatus(429);
    }
}
