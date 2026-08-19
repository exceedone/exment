<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Http;

/**
 * Shared fixtures for the SafetyCheck feature tests: event/answer-row factories,
 * LINE account linking, and the signed-webhook + mocked-LINE-transport scaffolding
 * (same conventions as LineWebhookTest). Keep changes to the webhook signature,
 * the answer-row schema, or the LINE mock HERE — five test classes use this trait.
 */
trait SafetyCheckTestHelpers
{
    public const WEBHOOK_SECRET = 'safety-webhook-test-secret';
    public const WEBHOOK_URL = 'admin/line/webhook';

    /** @var \ArrayObject Guzzle transactions captured from the mocked LINE transport. */
    protected $lineHistory;

    /**
     * Configures the LINE channel and binds a LineMessagingClient whose transport is
     * mocked: reply()/push() use Guzzle directly (not the Http facade), so this keeps
     * the suite hermetic and records every outgoing request in $this->lineHistory.
     */
    protected function setUpLineWebhookMock(): void
    {
        config(['exment.line.channel_secret' => static::WEBHOOK_SECRET]);
        config(['exment.line.channel_access_token' => 'safety-webhook-test-token']);

        $this->lineHistory = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 20, new GuzzleResponse(200, [], '{}'))));
        $stack->push(Middleware::history($this->lineHistory));
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.line.me']);
        $this->app->bind(LineMessagingClient::class, function () use ($guzzle) {
            return new LineMessagingClient(null, static::WEBHOOK_SECRET, $guzzle);
        });

        Http::fake(['api.line.me/*' => Http::response('{}', 200)]);
    }

    /** POST to the webhook with a body and a valid signature (signed with WEBHOOK_SECRET). */
    protected function postWebhook(array $payload)
    {
        $body = json_encode($payload);
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

    /** Text of the LAST '/v2/bot/message/reply' request captured, or null if none. */
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

    /** Seed a `safety_check_answer` row directly (bypasses SafetyCheckSender's pre-creation). */
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

    /** Re-fetch an answer row via a fresh query (bypasses the request-session cache). */
    protected function freshAnswerRow($rowId)
    {
        return CustomTable::getEloquent('safety_check_answer')->getValueQuery()->find($rowId);
    }

    protected function linkUser(int $userId): string
    {
        $lineUserId = 'U_test_' . $userId;
        LineAccountLink::forUser($userId)->markLinked($lineUserId);
        return $lineUserId;
    }

    /** All safety_check_answer rows for the given event id, via a fresh query (no caching). */
    protected function answerRows($eventId)
    {
        $answerTable = CustomTable::getEloquent('safety_check_answer');
        return $answerTable->getValueQuery()->get()->filter(function ($row) use ($eventId) {
            return (int) array_get($row->value, 'event') === (int) $eventId;
        })->values();
    }

    protected function totalUserCount(): int
    {
        return getModelName(SystemTableName::USER)::all()->count();
    }
}
