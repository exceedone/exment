<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Services\Line\LineAccountLinker;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Receives LINE webhooks. Verifies the signature and routes events:
 *  - message "LINK <code>" -> link account
 *  - postback              -> execute a workflow action
 *
 * Public route (no web/CSRF/auth middleware) - see RouteServiceProvider.
 */
class LineWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Container-resolved (bound in ExmentServiceProvider); tests re-bind it
        // with a mocked transport.
        $client = app(LineMessagingClient::class);
        $linker = new LineAccountLinker();

        $body = $request->getContent();
        $sig  = $request->header('X-Line-Signature');

        if (!$client->verifySignature($body, $sig)) {
            return response('invalid signature', 400);
        }

        $events = json_decode($body, true)['events'] ?? [];
        foreach ($events as $event) {
            if (!$this->markEventProcessed($event)) {
                // Already handled: LINE may redeliver webhooks, and redelivery order is
                // not guaranteed — replaying an old "safe" tap after a newer "need_help"
                // would overwrite the newer answer. Skip silently (a redelivered
                // replyToken is expired anyway).
                continue;
            }
            try {
                $this->dispatchEvent($event, $client, $linker);
            } catch (\Throwable $e) {
                // Release the exactly-once key so LINE's redelivery of this event can
                // succeed — otherwise a transient failure (DB deadlock etc.) would turn
                // into a permanent skip and the user's action would be silently lost.
                // Rethrow: LINE sees a non-2xx and redelivers the whole batch; events
                // that DID complete keep their key and are deduped on that replay.
                $this->forgetEventProcessed($event);
                throw $e;
            }
        }

        return response('', 200); // LINE always expects a 200 response
    }

    /**
     * Exactly-once guard per webhookEventId. Cache::add is atomic (first caller wins),
     * so a concurrent redelivery cannot slip through between check and set. Events
     * without a webhookEventId (old payloads, tests) are processed unconditionally.
     * TTL 25h covers LINE's redelivery window comfortably.
     */
    protected function markEventProcessed(array $event): bool
    {
        $eventId = $event['webhookEventId'] ?? null;
        if (is_nullorempty($eventId)) {
            return true;
        }
        return \Cache::add('line_webhook_event.' . $eventId, 1, 60 * 60 * 25);
    }

    /** Undo markEventProcessed after a failed dispatch, so a redelivery is accepted. */
    protected function forgetEventProcessed(array $event): void
    {
        $eventId = $event['webhookEventId'] ?? null;
        if (!is_nullorempty($eventId)) {
            \Cache::forget('line_webhook_event.' . $eventId);
        }
    }

    protected function dispatchEvent(array $event, LineMessagingClient $client, LineAccountLinker $linker): void
    {
        $type       = $event['type'] ?? null;
        $userId     = $event['source']['userId'] ?? null;
        $replyToken = $event['replyToken'] ?? null;

        if ($type === 'message' && ($event['message']['type'] ?? null) === 'text') {
            $text   = $event['message']['text'] ?? '';
            $linked = $linker->handleMessage($text, $userId);
            if ($replyToken) {
                $isLinkCommand = (bool) preg_match('/^\s*LINK\s+/i', $text);
                $alreadyLinked = $userId && LineAccountLink::where('line_user_id', $userId)->exists();
                if ($linked) {
                    $msg = exmtrans('line.link_success');
                } elseif ($isLinkCommand && $alreadyLinked) {
                    // LINK command, but this LINE user is already tied to an account
                    $msg = exmtrans('line.link_already_linked');
                } elseif ($isLinkCommand) {
                    $msg = exmtrans('line.link_invalid_code');
                } elseif ($alreadyLinked && \Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction::attachComment($userId, $text)) {
                    // Plain text from an already-linked user, attached as a comment on their
                    // current open safety-check answer row (e.g. injury detail follow-up).
                    $msg = exmtrans('safety.comment_added');
                } elseif ($alreadyLinked) {
                    // Already linked; plain text (not a command, actions only via buttons) is invalid
                    $msg = exmtrans('line.invalid_command');
                } else {
                    $msg = exmtrans('line.link_syntax_guide');
                }
                $client->reply($replyToken, [LineMessagingClient::text($msg)]);
            }
        } elseif ($type === 'postback') {
            $raw = $event['postback']['data'] ?? '';
            $data = \Exceedone\Exment\Services\Line\LineWorkflowAction::parsePostback($raw);
            if (($data['act'] ?? null) === 'workflow') {
                $msg = \Exceedone\Exment\Services\Line\LineWorkflowAction::handle($data, $userId);
                if ($replyToken) {
                    $client->reply($replyToken, [LineMessagingClient::text($msg)]);
                }
            } elseif (($data['act'] ?? null) === 'safety') {
                $msg = \Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction::handle($data, $userId);
                if ($replyToken) {
                    $client->reply($replyToken, [LineMessagingClient::text($msg)]);
                }
            }
        }
        // No reply on 'follow': linking is driven from the web QR/deep link, which pre-fills the
        // real "LINK <code>" in the compose box, so a follow greeting would be redundant.
    }
}
