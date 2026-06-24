<?php

namespace Exceedone\Exment\Services\Line;

use GuzzleHttp\Client;

/**
 * Bọc LINE Messaging API: push / reply / verify chữ ký webhook.
 * Chỉ lo HTTP, không chứa logic nghiệp vụ.
 *
 * Cấu hình đọc từ config('exment.line.*') (xem config/exment.php).
 */
class LineMessagingClient
{
    /** @var Client */
    protected $http;
    /** @var string */
    protected $token;
    /** @var string */
    protected $secret;
    /** @var string */
    protected $base;

    public function __construct($token = null, $secret = null, ?Client $http = null)
    {
        $this->token  = $token  ?? (string) config('exment.line.channel_access_token');
        $this->secret = $secret ?? (string) config('exment.line.channel_secret');
        $this->base   = rtrim((string) config('exment.line.api_base', 'https://api.line.me'), '/');
        $this->http   = $http ?? new Client([
            'base_uri' => $this->base,
            'timeout'  => (float) config('exment.line.timeout', 10),
        ]);
    }

    public static function make($token = null, $secret = null): LineMessagingClient
    {
        return new self($token, $secret);
    }

    /** Gửi tin chủ động tới 1 userId. */
    public function push(string $to, array $messages): array
    {
        return $this->request('/v2/bot/message/push', [
            'to'       => $to,
            'messages' => $this->normalize($messages),
        ]);
    }

    /** Trả lời 1 event bằng replyToken. */
    public function reply(string $replyToken, array $messages): array
    {
        return $this->request('/v2/bot/message/reply', [
            'replyToken' => $replyToken,
            'messages'   => $this->normalize($messages),
        ]);
    }

    /** Verify chữ ký webhook (HMAC-SHA256 base64 với channel secret). */
    public function verifySignature(string $requestBody, ?string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }
        $hash = base64_encode(hash_hmac('sha256', $requestBody, $this->secret, true));
        return hash_equals($hash, $signature);
    }

    /** Helper dựng message text. */
    public static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /** Cho phép truyền 1 message hoặc mảng nhiều message. */
    protected function normalize(array $messages): array
    {
        return isset($messages['type']) ? [$messages] : array_values($messages);
    }

    protected function request(string $path, array $json): array
    {
        $res = $this->http->request('POST', $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ],
            'json'        => $json,
            'http_errors' => false,
        ]);
        $status = $res->getStatusCode();
        $raw    = (string) $res->getBody();
        return [
            'status' => $status,
            'ok'     => $status >= 200 && $status < 300,
            'body'   => json_decode($raw, true) ?: [],
            'raw'    => $raw,
        ];
    }
}
