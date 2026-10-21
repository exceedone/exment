<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Carbon\Carbon;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSendLock;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Illuminate\Cache\Lock as BaseLock;
use Illuminate\Support\Facades\Cache;

class SafetyCheckSendLockTest extends FeatureTestBase
{
    use SafetyCheckTestHelpers;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testOneSendPerEventAtATime(): void
    {
        $first = SafetyCheckSendLock::acquire('lock-test-1');
        $this->assertNotNull($first);

        $this->assertNull(SafetyCheckSendLock::acquire('lock-test-1'), 'A second send of the same event must be refused.');
        $other = SafetyCheckSendLock::acquire('lock-test-2');
        $this->assertNotNull($other, 'Another event is not held up.');

        $first->release();
        $other->release();
        $this->assertFalse($this->eventLocked('lock-test-1'));
    }

    public function testALateSendReleasesOnlyItsOwnLock(): void
    {
        $crashed = SafetyCheckSendLock::acquire('lock-test-3');
        $this->assertNotNull($crashed);

        // a send that stopped refreshing frees the event after SECONDS
        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::SECONDS + 1));
        $next = SafetyCheckSendLock::acquire('lock-test-3');
        $this->assertNotNull($next, 'A lapsed lock must not block the event any longer.');

        $crashed->release();
        $this->assertTrue($this->eventLocked('lock-test-3'), 'Releasing late must not free the event under the next send.');

        $next->release();
        $this->assertFalse($this->eventLocked('lock-test-3'));
    }

    public function testKeepRefreshesOnlyNowAndThen(): void
    {
        // frozen first: acquire() reads the clock in whole seconds
        Carbon::setTestNow(now());
        $lock = SafetyCheckSendLock::acquire('lock-test-4');
        $this->assertNotNull($lock);

        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::KEEP_EVERY_SECONDS - 1));
        $lock->keep();
        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::SECONDS - SafetyCheckSendLock::KEEP_EVERY_SECONDS + 2));
        $this->assertFalse($this->eventLocked('lock-test-4'), 'Fixture: a keep() too soon after taking the lock does not refresh it.');

        $lock = SafetyCheckSendLock::acquire('lock-test-4');
        $this->assertNotNull($lock);
        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::KEEP_EVERY_SECONDS));
        $lock->keep();
        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::SECONDS - 1));
        $this->assertTrue($this->eventLocked('lock-test-4'), 'keep() refreshes the lock once KEEP_EVERY_SECONDS have passed.');
        $lock->release();
    }

    public function testALostLockIsTakenBackWhileStillFree(): void
    {
        Carbon::setTestNow(now());
        $lock = SafetyCheckSendLock::acquire('lock-test-7');
        $this->assertNotNull($lock);
        // wiped, e.g. by Cache::flush()
        Cache::lock(SafetyCheckSendLock::key('lock-test-7'))->forceRelease();

        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::KEEP_EVERY_SECONDS));
        $lock->keep();

        $this->assertTrue($this->eventLocked('lock-test-7'), 'A 再送 must stay refused while this send goes on.');
        $lock->release();
        $this->assertFalse($this->eventLocked('lock-test-7'));
    }

    public function testALostLockTakenBySomeoneElseStaysTheirs(): void
    {
        Carbon::setTestNow(now());
        $lock = SafetyCheckSendLock::acquire('lock-test-8');
        $this->assertNotNull($lock);
        Cache::lock(SafetyCheckSendLock::key('lock-test-8'))->forceRelease();
        $other = SafetyCheckSendLock::acquire('lock-test-8');
        $this->assertNotNull($other);

        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::KEEP_EVERY_SECONDS));
        $lock->keep();
        $lock->release();

        $this->assertTrue($this->eventLocked('lock-test-8'), 'Neither keep() nor release() may touch the other send\'s lock.');
        $other->release();
    }

    public function testACompanionIsKeptAndReleasedWithTheLock(): void
    {
        Carbon::setTestNow(now());
        $eventLock = SafetyCheckSendLock::acquire('lock-test-9');
        $adminLock = SafetyCheckSendLock::acquireKey('lock-test-admin');
        $this->assertNotNull($eventLock);
        $this->assertNotNull($adminLock);
        $eventLock->with($adminLock);

        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::KEEP_EVERY_SECONDS));
        $eventLock->keep();
        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::SECONDS - 1));
        $this->assertTrue($this->lockHeld('lock-test-admin'), 'The companion is refreshed along.');

        $eventLock->release();
        $this->assertFalse($this->eventLocked('lock-test-9'));
        $this->assertFalse($this->lockHeld('lock-test-admin'), 'and released along.');
    }

    public function testFailingCacheDoesNotStopTheSend(): void
    {
        $original = Cache::getFacadeRoot();
        Cache::shouldReceive('lock')->once()->andThrow(new \RuntimeException('cache down'));
        try {
            $lock = SafetyCheckSendLock::acquire('lock-test-5');
        } finally {
            Cache::swap($original);
        }
        $this->assertNotNull($lock, 'Sending matters more than the lock.');
        $lock->keep();
        $lock->release();
    }

    public function testLockThatCannotRefreshOrReleaseDoesNotStopTheSend(): void
    {
        $broken = \Mockery::mock(BaseLock::class);
        $broken->shouldReceive('get')->once()->andReturn(true);
        $broken->shouldReceive('refresh')->once()->andThrow(new \RuntimeException('This lock driver does not support refreshing locks.'));
        $broken->shouldReceive('release')->once()->andThrow(new \RuntimeException('cache down'));

        $original = Cache::getFacadeRoot();
        Cache::shouldReceive('lock')->once()->andReturn($broken);
        try {
            $lock = SafetyCheckSendLock::acquire('lock-test-6');
        } finally {
            Cache::swap($original);
        }
        $this->assertNotNull($lock);

        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::KEEP_EVERY_SECONDS));
        $lock->keep();
        Carbon::setTestNow(now()->addSeconds(SafetyCheckSendLock::KEEP_EVERY_SECONDS));
        $lock->keep(); // refresh() is not tried again: once() above
        $lock->release();
    }
}
