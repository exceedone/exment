<?php

namespace Exceedone\Exment\Notifications;

use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\Line\LineSendLogger;

class LineSender extends SenderBase
{
    public const TEXT_MAX_LENGTH = 5000;

    /** @var string */
    protected $to;
    /** @var array */
    protected $context;

    public function __construct($to, $subject, $body, array $context = [])
    {
        $this->to      = $to;
        $this->subject = $subject;
        $this->body    = $body;
        $this->context = $context;
    }

    public static function make($to, $subject, $body, array $context = []): LineSender
    {
        return new self($to, $subject, $body, $context);
    }

    public function send()
    {
        if (is_nullorempty($this->to)) {
            return;
        }
        $subject = static::htmlToLineText($this->subject);
        $body    = static::htmlToLineText($this->body);
        $text = trim(($subject ? $subject . "\n" : '') . $body);
        if ($text === '') {
            return;
        }
        if (mb_strlen($text) > static::TEXT_MAX_LENGTH) {
            $text = mb_substr($text, 0, static::TEXT_MAX_LENGTH);
        }
        $context = array_merge($this->context, [
            'message_type'     => LineSendLogger::TYPE_TEXT,
            'flex_template_id' => null,
            'subject'          => $subject,
        ]);

        LineSendJob::dispatchAfterResponse($this->to, [LineMessagingClient::text($text)], $context);
    }

    protected static function htmlToLineText($html): string
    {
        $s = (string) $html;
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>.*?<\/a>/is', '$1', $s);
        $s = preg_replace('/<br\s*\/?>/i', "\n", $s);
        $s = preg_replace('/<\/?(p|div|tr|li|h[1-6])\b[^>]*>/i', "\n", $s);
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\n{3,}/', "\n\n", $s);
        return trim($s);
    }
}
