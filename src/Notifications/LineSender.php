<?php

namespace Exceedone\Exment\Notifications;

use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\Line\LineSendLogger;

class LineSender extends SenderBase
{
    /** @var string Recipient line_user_id */
    protected $to;
    /** @var array */
    protected $options;
    /** @var array Context for the line_send_log entry (see LineSendLogger::record) */
    protected $context;

    public function __construct($to, $subject, $body, array $options = [], array $context = [])
    {
        $this->to      = $to;
        $this->subject = $subject;
        $this->body    = $body;
        $this->options = $options;
        $this->context = $context;
    }

    public static function make($to, $subject, $body, array $options = [], array $context = []): LineSender
    {
        return new self($to, $subject, $body, $options, $context);
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
        $context = array_merge($this->context, [
            'message_type'     => LineSendLogger::TYPE_TEXT,
            'flex_template_id' => null,
            'subject'          => $subject,
        ]);

        // dispatchAfterResponse: push AFTER the response so the confirmation reply (postback) always arrives first.
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
