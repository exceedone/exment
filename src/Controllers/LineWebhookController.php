<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Services\Line\LineAccountLinker;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Nhận webhook từ LINE. Verify chữ ký, định tuyến event:
 *  - message "LINK <mã>" -> liên kết tài khoản
 *  - follow             -> reply hướng dẫn
 *
 * Route công khai (không qua web/CSRF/auth) - xem RouteServiceProvider.
 */
class LineWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $client = new LineMessagingClient();
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

        return response('', 200); // LINE luôn cần 200
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
                    $msg = '✅ Liên kết LINE thành công!';
                } elseif ($isLinkCommand && $alreadyLinked) {
                    // Là lệnh LINK nhưng LINE này đã gắn 1 tài khoản
                    $msg = 'LINE này đã được liên kết với một tài khoản. Nếu muốn đổi, hãy huỷ liên kết trên web trước.';
                } elseif ($isLinkCommand) {
                    $msg = 'Mã liên kết không đúng hoặc đã hết hạn. Vui lòng kiểm tra lại.';
                } elseif ($alreadyLinked) {
                    // đã liên kết, gửi text thường (không phải lệnh, action chỉ qua nút) -> không hợp lệ
                    $msg = 'Lệnh không hợp lệ.';
                } else {
                    $msg = 'Gửi đúng cú pháp: LINK <mã> để liên kết tài khoản.';
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
        } elseif ($type === 'follow' && $replyToken) {
            $client->reply($replyToken, [
                LineMessagingClient::text('Chào bạn! Gửi "LINK <mã>" để liên kết tài khoản.'),
            ]);
        }
    }
}
