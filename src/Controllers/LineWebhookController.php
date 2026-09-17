<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Services\Line\LineAccountLinker;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LineWebhookController extends Controller
{
    public function handle(Request $request)
    {
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
                continue;
            }
            try {
                $this->dispatchEvent($event, $client, $linker);
            } catch (\Throwable $e) {
                $this->forgetEventProcessed($event);
                throw $e;
            }
        }

        return response('', 200);
    }

    protected function markEventProcessed(array $event): bool
    {
        $eventId = $event['webhookEventId'] ?? null;
        if (is_nullorempty($eventId)) {
            return true;
        }
        return \Cache::add('line_webhook_event.' . $eventId, 1, 60 * 60 * 25);
    }

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
                    $msg = exmtrans('line.link_already_linked');
                } elseif ($isLinkCommand) {
                    $msg = exmtrans('line.link_invalid_code');
                } elseif ($alreadyLinked && \Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction::attachComment($userId, $text)) {
                    $msg = exmtrans('safety.comment_added');
                } elseif ($alreadyLinked) {
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
    }
}
