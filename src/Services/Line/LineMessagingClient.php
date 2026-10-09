<?php

namespace Exceedone\Exment\Services\Line;

use GuzzleHttp\Client;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\Log;

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

    /**
     * @param string|null $token
     * @param string|null $secret
     */
    public function __construct($token = null, $secret = null, ?Client $http = null)
    {
        $this->token  = $token  ?? (string) System::system_line_channel_token();
        $this->secret = $secret ?? (string) System::system_line_channel_secret();
        $this->base   = rtrim((string) config('exment.line.api_base', 'https://api.line.me'), '/');
        $this->http   = $http ?? new Client([
            'base_uri' => $this->base,
            'timeout'  => (float) config('exment.line.timeout', 10),
        ]);
    }

    /**
     * @param string|null $token
     * @param string|null $secret
     */
    public static function make($token = null, $secret = null): LineMessagingClient
    {
        return new self($token, $secret);
    }

    /**
     * @param array<mixed> $messages one message or a list of messages
     * @return array<string, mixed> status, ok, body, raw
     */
    public function push(string $to, array $messages): array
    {
        return $this->request('/v2/bot/message/push', [
            'to'       => $to,
            'messages' => $this->normalize($messages),
        ]);
    }

    /**
     * @param array<mixed> $messages one message or a list of messages
     * @return array<string, mixed> status, ok, body, raw
     */
    public function reply(string $replyToken, array $messages): array
    {
        $res = $this->request('/v2/bot/message/reply', [
            'replyToken' => $replyToken,
            'messages'   => $this->normalize($messages),
        ]);
        if (!$res['ok']) {
            Log::warning('LINE reply failed', ['status' => $res['status'], 'body' => $res['body']]);
        }
        return $res;
    }

    public function verifySignature(string $requestBody, ?string $signature): bool
    {
        if ($this->secret === '' || empty($signature)) {
            return false;
        }
        $hash = base64_encode(hash_hmac('sha256', $requestBody, $this->secret, true));
        return hash_equals($hash, $signature);
    }

    /**
     * @return array<string, string>
     */
    public static function text(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * @param array<string, mixed> $bubble
     * @return array<string, mixed>
     */
    public static function flex(string $altText, array $bubble): array
    {
        $altText = trim($altText);
        if ($altText === '') {
            $altText = 'LINE';
        } elseif (mb_strlen($altText) > 400) {
            $altText = mb_substr($altText, 0, 400);
        }
        return ['type' => 'flex', 'altText' => $altText, 'contents' => $bubble];
    }

    /**
     * @param array<mixed> $messages
     * @return array<int, mixed>
     */
    protected function normalize(array $messages): array
    {
        return isset($messages['type']) ? [$messages] : array_values($messages);
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed> status, ok, body, raw
     */
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
