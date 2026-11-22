<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Form\Navbar\NotifyNav;
use Exceedone\Exment\Form\Navbar\WorkflowTaskNav;
use Exceedone\Exment\Model\NotifyNavbar;

/**
 * The navbar polls (the bell, the workflow tasks) cost less (ISS02824): an interval from the
 * config, one shared poll per browser, none in a hidden tab, a per-user cache and an ETag.
 */
class NavbarPollTest extends UnitTestBase
{
    /**
     * @return void
     */
    protected function init()
    {
        $this->initAllTest();
    }

    protected function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    /**
     * @return void
     */
    public function testBellInterval()
    {
        try {
            config(['exment.notify_navbar_interval' => null]);
            $this->assertSame(NotifyNav::DEFAULT_INTERVAL, NotifyNav::interval());
            config(['exment.notify_navbar_interval' => 5]);
            $this->assertSame(NotifyNav::MIN_INTERVAL, NotifyNav::interval(), 'never faster than the floor');
            config(['exment.notify_navbar_interval' => 120]);
            $this->assertSame(120, NotifyNav::interval());
        } finally {
            config(['exment.notify_navbar_interval' => 60]);
        }
    }

    /**
     * The page hands the interval and a key of the user to the javascript (not the user id).
     *
     * @return void
     */
    public function testTheNavbarsHandTheIntervalAndTheUserKey()
    {
        $this->be(\Exceedone\Exment\Model\LoginUser::first());
        $key = NotifyNav::userKey();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);

        $bell = (new NotifyNav())->render();
        $this->assertStringContainsString('id="notify_navbar_interval" type="hidden" value="' . NotifyNav::interval() . '" data-user="' . $key . '"', $bell);

        $tasks = (new WorkflowTaskNav())->render();
        $this->assertStringContainsString('id="workflow_task_navbar_interval" type="hidden" value="' . WorkflowTaskNav::interval() . '" data-user="' . $key . '"', $tasks);
    }

    /**
     * A change of the user's notifications moves the cache key of the bell.
     *
     * @return void
     */
    public function testTheCacheKeyMovesOnAChange()
    {
        $this->be(\Exceedone\Exment\Model\LoginUser::first());
        $before = NotifyNavbar::navbarCacheKey();
        $this->assertNotNull($before);
        NotifyNavbar::navbarCacheForget([\Exment::getUserId(), null, '']);
        $after = NotifyNavbar::navbarCacheKey();
        $this->assertNotSame($before, $after);

        // another user's change does not move it
        NotifyNavbar::navbarCacheForget('no-such-user');
        $this->assertSame($after, NotifyNavbar::navbarCacheKey());
    }

    /**
     * Both polls go through the ETag middleware; the shared timer is loaded before its users,
     * and the scripts no longer ask on a fixed timer of their own.
     *
     * @return void
     */
    public function testTheWiring()
    {
        foreach (['notifyPage', 'workflowTaskPage'] as $name) {
            $found = false;
            foreach (\Route::getRoutes()->getRoutes() as $route) {
                if (!str_ends_with($route->uri(), '/' . $name)) {
                    continue;
                }
                $found = true;
                $middleware = implode(' ', array_filter($route->gatherMiddleware(), 'is_string'));
                $this->assertStringContainsString('SetCacheHeaders:private;no_cache;etag', $middleware, $route->uri());
            }
            $this->assertTrue($found, $name);
        }

        $bootstrap = $this->read('src/Middleware/Bootstrap.php');
        $poll = strpos($bootstrap, "'vendor/exment/js/navbar_poll.js'");
        $this->assertNotFalse($poll);
        $this->assertLessThan(strpos($bootstrap, "'vendor/exment/js/notify_navbar.js'"), $poll);
        $this->assertLessThan(strpos($bootstrap, "'vendor/exment/js/workflow_task_navbar.js'"), $poll);

        $js = $this->read('public/vendor/exment/js/navbar_poll.js');
        $this->assertStringContainsString("'visibilitychange'", $js, 'a hidden tab does not ask');
        $this->assertStringContainsString("'storage'", $js, 'the tabs share the answer');
        $this->assertStringContainsString('localStorage', $js);

        foreach (['public/vendor/exment/js/notify_navbar.js', 'public/vendor/exment/js/workflow_task_navbar.js'] as $file) {
            $source = $this->read($file);
            $this->assertStringContainsString('new Exment.NavbarPoll(', $source, $file);
            $this->assertStringNotContainsString('setTimeout(', $source, $file . ': the timer is the shared one');
        }
        $this->assertStringContainsString("'#notify_navbar_interval'", $this->read('public/vendor/exment/js/notify_navbar.js'));
    }
}
