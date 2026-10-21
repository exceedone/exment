<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSendLock;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

trait SafetyCheckTestHelpers
{
    public const WEBHOOK_SECRET = 'safety-webhook-test-secret';
    public const WEBHOOK_URL = 'admin/line/webhook';

    /** @var \ArrayObject<int, array<string, mixed>> Guzzle history: request, response per LINE API call */
    protected $lineHistory;

    protected function setUpLineWebhookMock(): void
    {
        config(['exment.line.channel_secret' => static::WEBHOOK_SECRET]);
        config(['exment.line.channel_access_token' => 'safety-webhook-test-token']);

        $this->lineHistory = new \ArrayObject();
        $history = $this->lineHistory; // same object; a local keeps the by-reference parameter off the typed property
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 20, new GuzzleResponse(200, [], '{}'))));
        $stack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.line.me']);
        $this->app->bind(LineMessagingClient::class, function () use ($guzzle) {
            return new LineMessagingClient(null, static::WEBHOOK_SECRET, $guzzle);
        });

        Http::fake(['api.line.me/*' => Http::response('{}', 200)]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postWebhook(array $payload)
    {
        $body = (string) json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $body, static::WEBHOOK_SECRET, true));

        return $this->call(
            'POST',
            static::WEBHOOK_URL,
            [],
            [],
            [],
            ['HTTP_X_LINE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body
        );
    }

    protected function lastReplyText(): ?string
    {
        $text = null;
        foreach ($this->lineHistory as $tx) {
            if ($tx['request']->getUri()->getPath() === '/v2/bot/message/reply') {
                $body = json_decode((string) $tx['request']->getBody(), true);
                $text = $body['messages'][0]['text'] ?? null;
            }
        }
        return $text;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return \Exceedone\Exment\Model\CustomValue
     */
    protected function createEvent(array $overrides = [])
    {
        $value = array_merge([
            'title' => 'Test event',
            'trigger_type' => 'manual',
            'event_status' => 'open',
            'triggered_at' => now()->format('Y-m-d H:i:s'),
        ], $overrides);

        $event = CustomTable::getEloquent('safety_check_event')->getValueModel();
        $event->setValue($value)->save();
        return $event;
    }

    /**
     * @param int|string $eventId
     * @param array<string, mixed> $overrides
     * @return \Exceedone\Exment\Model\CustomValue
     */
    protected function createAnswerRow($eventId, int $userId, array $overrides = [])
    {
        $value = array_merge([
            'event' => $eventId,
            'user' => $userId,
            'answer_status' => 'not_answered',
        ], $overrides);

        $answerTable = CustomTable::getEloquent('safety_check_answer');
        $row = $answerTable->getValueModel();
        $row->setValue($value)->save();
        return $row;
    }

    /**
     * @param int|string $rowId
     * @return \Exceedone\Exment\Model\CustomValue|null
     */
    protected function freshAnswerRow($rowId)
    {
        /** @var \Exceedone\Exment\Model\CustomValue|null $row */
        $row = CustomTable::getEloquent('safety_check_answer')->getValueQuery()->find($rowId);
        return $row;
    }

    protected function linkUser(int $userId): string
    {
        $lineUserId = 'U_test_' . $userId;
        LineAccountLink::forUser($userId)->markLinked($lineUserId);
        return $lineUserId;
    }

    /**
     * @param int|string $eventId
     * @return \Illuminate\Database\Eloquent\Collection<int, \Exceedone\Exment\Model\CustomValue>
     */
    protected function answerRows($eventId)
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, \Exceedone\Exment\Model\CustomValue> $rows */
        $rows = CustomTable::getEloquent('safety_check_answer')->getValueQuery()->get();
        return $rows->filter(function ($row) use ($eventId) {
            return (int) array_get($row->value, 'event') === (int) $eventId;
        })->values();
    }

    protected function totalUserCount(): int
    {
        return getModelName(SystemTableName::USER)::all()->count();
    }

    /**
     * @param int|string $id
     * @return \Exceedone\Exment\Model\CustomValue|null
     */
    protected function freshEvent($id)
    {
        /** @var \Exceedone\Exment\Model\CustomValue|null $event */
        $event = CustomTable::getEloquent('safety_check_event')->getValueQuery()->find($id);
        return $event;
    }

    /**
     * Whether a send of the event holds its lock. The lock sits in the cache's lock store,
     * which Cache::has() does not see, so try to take it.
     *
     * @param int|string $eventId
     */
    protected function eventLocked($eventId): bool
    {
        return $this->lockHeld(SafetyCheckSendLock::key($eventId));
    }

    protected function lockHeld(string $key): bool
    {
        $probe = Cache::lock($key, 1);
        if ($probe->get()) {
            $probe->release();
            return false;
        }
        return true;
    }
}
