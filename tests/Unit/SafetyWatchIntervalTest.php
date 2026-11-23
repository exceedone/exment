<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ExmentServiceProvider;

class SafetyWatchIntervalTest extends UnitTestBase
{
    public function testTheIntervalOfTheEarthquakeWatch(): void
    {
        config(['exment.safety_check.watch_interval' => null]);
        $this->assertSame('*/5 * * * *', ExmentServiceProvider::safetyWatchCron());
        config(['exment.safety_check.watch_interval' => 5]);
        $this->assertSame('*/5 * * * *', ExmentServiceProvider::safetyWatchCron());
        config(['exment.safety_check.watch_interval' => '1']);
        $this->assertSame('* * * * *', ExmentServiceProvider::safetyWatchCron());
        config(['exment.safety_check.watch_interval' => 15]);
        $this->assertSame('*/15 * * * *', ExmentServiceProvider::safetyWatchCron());
        // anything else is 5
        config(['exment.safety_check.watch_interval' => 7]);
        $this->assertSame('*/5 * * * *', ExmentServiceProvider::safetyWatchCron());
        config(['exment.safety_check.watch_interval' => 'x']);
        $this->assertSame('*/5 * * * *', ExmentServiceProvider::safetyWatchCron());
    }
}
