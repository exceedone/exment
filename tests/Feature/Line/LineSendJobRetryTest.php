<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Exceptions\LineSendFailedException;
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

    protected function bindClient(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.line.me']);
        $this->app->bind(LineMessagingClient::class, function () use ($guzzle) {
            return new LineMessagingClient('retry-test-token', null, $guzzle);
        });
    }

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
        $queueJob->shouldReceive('release')->once();
        $job->setJob($queueJob);

        $job->handle();

        $this->assertEquals($before, $this->sendLogRows()->count(), 'No final log row while the job will still be retried.');
    }

    public function testRetryableFailureOnFinalAttemptWritesFailureLog()
    {
        $this->bindClient([new GuzzleResponse(429, [], '{"message":"rate limited"}')]);

        $before = $this->sendLogRows()->count();

        $job = new LineSendJob('Uretry2', [LineMessagingClient::text('hello')]);
        $queueJob = $this->queueJobMock('database', $job->tries);
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

        $job = new LineSendJob('Uretry3', [LineMessagingClient::text('hello')], [], true);
        $queueJob = $this->queueJobMock('sync', 1);
        $queueJob->shouldReceive('release')->never();
        $job->setJob($queueJob);

        $thrown = null;
        try {
            $job->handle();
        } catch (LineSendFailedException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'Sync driver must surface the failure to the caller.');
        $this->assertEquals(500, $thrown->getResult()['status']);

        $rows = $this->sendLogRows();
        $this->assertEquals($before + 1, $rows->count(), 'Sync driver cannot retry: log once, immediately.');
        $this->assertEquals('failed', array_get($rows->last()->value, 'status'));

        $job->failed($thrown);
        $this->assertEquals($before + 1, $this->sendLogRows()->count(), 'failed() must not log the API failure twice.');
    }

    public function testNonRetryableFailureIsNotReleased()
    {
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
    public function testRejectedAfterResponsePushDoesNotAbortLaterPushes()
    {
        $this->bindClient([
            new GuzzleResponse(401, [], '{"message":"invalid token"}'),
            new GuzzleResponse(200, [], '{}'),
        ]);
        $before = $this->sendLogRows()->count();

        LineSendJob::dispatchAfterResponse('Uafter1', [LineMessagingClient::text('hello')], ['user_id' => 1]);
        LineSendJob::dispatchAfterResponse('Uafter2', [LineMessagingClient::text('hello')], ['user_id' => 2]);
        $this->app->terminate();

        $rows = $this->sendLogRows();
        $this->assertEquals($before + 2, $rows->count(), 'The second recipient must still be pushed after the first was rejected.');
        $this->assertEquals('failed', array_get($rows[$before]->value, 'status'));
        $this->assertEquals('success', array_get($rows[$before + 1]->value, 'status'));
    }
}
