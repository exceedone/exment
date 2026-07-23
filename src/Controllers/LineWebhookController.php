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
        // Resolve from the container only when explicitly bound (tests inject a mock transport);
        // in production nothing binds it, so build it directly with its configured Guzzle client.
        $client = app()->bound(LineMessagingClient::class)
            ? app(LineMessagingClient::class)
            : new LineMessagingClient();
        $linker = new LineAccountLinker();

        $body = $request->getContent();
        $sig  = $request->header('X-Line-Signature');

        if (!$client->verifySignature($body, $sig)) {
            return response('invalid signature', 400);
        }

        $events = json_decode($body, true)['events'] ?? [];
        foreach ($events as $event) {
            $this->dispatchEvent($event, $client, $linker);
        }

        return response('', 200); // LINE always expects a 200 response
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
            }
        }
        // No reply on 'follow': linking is driven from the web QR/deep link, which pre-fills the
        // real "LINK <code>" in the compose box, so a follow greeting would be redundant.
    }
}
