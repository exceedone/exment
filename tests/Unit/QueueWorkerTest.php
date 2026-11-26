<?php

namespace Exceedone\Exment\Tests\Unit;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Jobs\QueueHeartbeatJob;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\QueueWorker;
use Exceedone\Exment\Services\SystemRequire\Security\QueueWorkerSecurity;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * The queue's workers (ISS02908): the restart signal kept when the cache is cleared, and the
 * heartbeat that tells whether a worker runs.
 */
class QueueWorkerTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        config(['queue.default' => 'sync']);
        Cache::forget(QueueWorker::RESTART_KEY);
        parent::tearDown();
    }

    protected function useQueue(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
    }

    public function testTheRestartSignalIsKeptWhenTheCacheIsEmptied(): void
    {
        Cache::forever(QueueWorker::RESTART_KEY, 1800000000);
        $result = QueueWorker::keepRestartSignal(function () {
            Cache::flush();

            return 'done';
        });
        $this->assertSame('done', $result);
        $this->assertSame(1800000000, Cache::get(QueueWorker::RESTART_KEY));

        // no signal: none is made
        Cache::forget(QueueWorker::RESTART_KEY);
        QueueWorker::keepRestartSignal(function () {
            Cache::flush();
        });
        $this->assertNull(Cache::get(QueueWorker::RESTART_KEY));
    }

    public function testLaravelsCacheClearKeepsTheSignal(): void
    {
        Cache::forever(QueueWorker::RESTART_KEY, 1800000002);
        \Artisan::call('cache:clear');
        $this->assertSame(1800000002, Cache::get(QueueWorker::RESTART_KEY));

        // the events alone, as the command fires them
        Cache::forever(QueueWorker::RESTART_KEY, 1800000003);
        event('cache:clearing', [null, []]);
        Cache::flush();
        event('cache:cleared', [null, []]);
        $this->assertSame(1800000003, Cache::get(QueueWorker::RESTART_KEY));
    }

    public function testClearingExmentsCacheKeepsTheSignal(): void
    {
        config(['exment.use_cache' => true]);
        Cache::forever(QueueWorker::RESTART_KEY, 1800000001);
        System::clearCache();
        $this->assertSame(1800000001, Cache::get(QueueWorker::RESTART_KEY));
        config(['exment.use_cache' => false]);
    }

    public function testWithoutAQueueNothingIsWatched(): void
    {
        config(['queue.default' => 'sync']);
        Queue::fake();
        $this->assertFalse(QueueWorker::used());
        QueueWorker::beat();
        Queue::assertNothingPushed();
        $this->assertSame('unused', QueueWorker::state());
        $this->assertSame(SystemRequireResult::OK, (new QueueWorkerSecurity())->checkResult());
    }

    public function testTheHeartbeat(): void
    {
        $this->useQueue();
        \DB::table((new System())->getTable())->whereIn('system_name', [QueueWorker::KEY_SENT, QueueWorker::KEY_DONE])->delete();
        $this->assertTrue(QueueWorker::used());
        $this->assertSame('unknown', QueueWorker::state());
        $this->assertSame(SystemRequireResult::WARNING, (new QueueWorkerSecurity())->checkResult());

        $fake = Queue::fake();
        $t0 = Carbon::parse('2026-11-27 10:00:00');
        QueueWorker::beat($t0);
        $fake->assertPushed(QueueHeartbeatJob::class);
        // sent just now: still waiting its turn
        $this->assertSame('ok', QueueWorker::state($t0->copy()->addMinutes(5)));
        // not run for 15 minutes: no worker
        $this->assertSame('stale', QueueWorker::state($t0->copy()->addMinutes(15)));

        // a worker ran it
        QueueWorker::done($t0->copy()->addMinutes(16));
        $this->assertSame('ok', QueueWorker::state($t0->copy()->addMinutes(17)));

        // the next ones not run: stale counted from the last one run
        QueueWorker::beat($t0->copy()->addMinutes(20));
        $this->assertSame('ok', QueueWorker::state($t0->copy()->addMinutes(30)));
        $this->assertSame('stale', QueueWorker::state($t0->copy()->addMinutes(31)));
    }

    public function testTheJobWritesTheTime(): void
    {
        $this->useQueue();
        Carbon::setTestNow(Carbon::parse('2026-11-27 11:00:00'));
        try {
            (new QueueHeartbeatJob())->handle();
            $this->assertSame('2026-11-27 11:00:00', optional(QueueWorker::lastDone())->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
