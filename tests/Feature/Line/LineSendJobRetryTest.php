<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Contracts\Queue\Job;
use Mockery;

/**
 * LineSendJob must retry retryable LINE API failures (429 / 5xx) when it runs on a
 * real (async) queue: release back with backoff instead of swallowing the failure,
 * and only write the line_send_log failure row on the FINAL attempt. On the sync
 * driver (no retry possible) and on success, behavior is unchanged: one log row.
 */
class LineSendJobRetryTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.line.channel_access_token' => 'retry-test-token']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Bind a LineMessagingClient whose transport returns the given responses. */
    protected function bindClient(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.line.me']);
        $this->app->bind(LineMessagingClient::class, function () use ($guzzle) {
            return new LineMessagingClient('retry-test-token', null, $guzzle);
        });
    }

    /** A queue-context mock: the job contract LineSendJob sees via InteractsWithQueue. */
    protected function queueJobMock(string $connection, int $attempts): Job
    {
        $mock = Mockery::mock(Job::class);
        $mock->shouldReceive('getConnectionName')->andReturn($connection);
        $mock->shouldReceive('attempts')->andReturn($attempts);
        $mock->shouldReceive('isReleased')->andReturn(false);
        $mock->shouldReceive('isDeleted')->andReturn(false);
        $mock->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $mock->shouldReceive('uuid')->andReturn('test-uuid');
        return $mock;
    }

    protected function sendLogRows()
    {
        return CustomTable::getEloquent('line_send_log')->getValueQuery()->get();
    }

    public function testRetryableFailureOnRealQueueIsReleasedWithoutFinalLog()
    {
        $this->bindClient([new GuzzleResponse(429, [], '{"message":"rate limited"}')]);

        $before = $this->sendLogRows()->count();

        $job = new LineSendJob('Uretry1', [LineMessagingClient::text('hello')]);
        $queueJob = $this->queueJobMock('database', 1);
        $queueJob->shouldReceive('release')->once(); // released back for retry
        $job->setJob($queueJob);

        $job->handle();

        $this->assertEquals($before, $this->sendLogRows()->count(), 'No final log row while the job will still be retried.');
    }

    public function testRetryableFailureOnFinalAttemptWritesFailureLog()
    {
        $this->bindClient([new GuzzleResponse(429, [], '{"message":"rate limited"}')]);

        $before = $this->sendLogRows()->count();

        $job = new LineSendJob('Uretry2', [LineMessagingClient::text('hello')]);
        $queueJob = $this->queueJobMock('database', $job->tries); // last allowed attempt
        $queueJob->shouldReceive('release')->never();
        $job->setJob($queueJob);

        $job->handle();

        $rows = $this->sendLogRows();
        $this->assertEquals($before + 1, $rows->count(), 'The final attempt must record the failure.');
        $this->assertEquals('failed', array_get($rows->last()->value, 'status'));
    }

    public function testRetryableFailureOnSyncQueueWritesLogImmediately()
    {
        $this->bindClient([new GuzzleResponse(500, [], '{"message":"server error"}')]);

        $before = $this->sendLogRows()->count();

        $job = new LineSendJob('Uretry3', [LineMessagingClient::text('hello')]);
        $queueJob = $this->queueJobMock('sync', 1); // sync driver cannot retry
        $queueJob->shouldReceive('release')->never();
        $job->setJob($queueJob);

        $job->handle();

        $rows = $this->sendLogRows();
        $this->assertEquals($before + 1, $rows->count(), 'Sync driver cannot retry: log once, immediately.');
        $this->assertEquals('failed', array_get($rows->last()->value, 'status'));
    }

    public function testNonRetryableFailureIsNotReleased()
    {
        // 400 invalid user id: retrying cannot help
        $this->bindClient([new GuzzleResponse(400, [], '{"message":"invalid to"}')]);

        $before = $this->sendLogRows()->count();

        $job = new LineSendJob('Uretry4', [LineMessagingClient::text('hello')]);
        $queueJob = $this->queueJobMock('database', 1);
        $queueJob->shouldReceive('release')->never();
        $job->setJob($queueJob);

        $job->handle();

        $rows = $this->sendLogRows();
        $this->assertEquals($before + 1, $rows->count());
        $this->assertEquals('failed', array_get($rows->last()->value, 'status'));
    }

    /**
     * A network-level exception (Guzzle ConnectException: DNS/timeout, no HTTP
     * status) escapes handle() by design so the queue retries it — but once the
     * job finally dies, the failure must STILL leave a line_send_log row, like
     * every HTTP-status failure does. Laravel calls failed() at that point.
     */
    public function testExhaustedNetworkFailureWritesFailureLog()
    {
        $before = $this->sendLogRows()->count();

        $job = new LineSendJob('Uretry6', [LineMessagingClient::text('hello')], ['user_id' => 2]);
        $job->failed(new \RuntimeException('Connection timed out'));

        $rows = $this->sendLogRows();
        $this->assertEquals($before + 1, $rows->count(), 'A dead job must record the failure.');
        $last = $rows->last();
        $this->assertEquals('failed', array_get($last->value, 'status'));
        $this->assertEquals('Uretry6', array_get($last->value, 'line_user_id'));
        $this->assertStringContainsString('Connection timed out', (string) array_get($last->value, 'error_message'));
    }

    public function testSuccessWritesSuccessLogWithoutRelease()
    {
        $this->bindClient([new GuzzleResponse(200, [], '{}')]);

        $before = $this->sendLogRows()->count();

        $job = new LineSendJob('Uretry5', [LineMessagingClient::text('hello')]);
        $queueJob = $this->queueJobMock('database', 1);
        $queueJob->shouldReceive('release')->never();
        $job->setJob($queueJob);

        $job->handle();

        $rows = $this->sendLogRows();
        $this->assertEquals($before + 1, $rows->count());
        $this->assertEquals('success', array_get($rows->last()->value, 'status'));
    }
}
