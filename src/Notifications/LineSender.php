<?php

namespace Exceedone\Exment\Notifications;

use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Services\Line\LineMessagingClient;

/**
 * Gửi thông báo LINE tới 1 line_user_id (user-targeted, giống MailSender).
 */
class LineSender extends SenderBase
{
    /** @var string line_user_id người nhận */
    protected $to;
    /** @var array */
    protected $options;

    public function __construct($to, $subject, $body, array $options = [])
    {
        $this->to      = $to;
        $this->subject = $subject;
        $this->body    = $body;
        $this->options = $options;
    }

    public static function make($to, $subject, $body, array $options = []): LineSender
    {
        return new self($to, $subject, $body, $options);
    }

    public function send()
    {
        if (is_nullorempty($this->to)) {
            return;
        }
        // GĐ1: chỉ text. GĐ3 sẽ thay bằng Flex builder.
        $subject = static::htmlToLineText($this->subject);
        $body    = static::htmlToLineText($this->body);
        $text = trim(($subject ? $subject . "\n" : '') . $body);
        if ($text === '') {
            return;
        }
        LineSendJob::dispatch($this->to, [LineMessagingClient::text($text)]);
    }

    /**
     * Chuyển HTML (từ mail template dùng chung) thành text thuần cho LINE:
     *  - <a href="URL">...</a> -> URL  (LINE tự nhận diện link)
     *  - <br>, </p>, </div>... -> xuống dòng
     *  - bỏ các thẻ còn lại, giải mã entity, gộp dòng trống thừa
     */
    protected static function htmlToLineText($html): string
    {
        $s = (string) $html;
        if ($s === '') {
            return '';
        }
        // anchor -> URL trong href (LINE tự render link)
        $s = preg_replace('/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>.*?<\/a>/is', '$1', $s);
        // ngắt dòng
        $s = preg_replace('/<br\s*\/?>/i', "\n", $s);
        $s = preg_replace('/<\/(p|div|tr|li|h[1-6])>/i', "\n", $s);
        // bỏ thẻ còn lại + giải mã entity
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // gộp >2 dòng trống liên tiếp
        $s = preg_replace('/\n{3,}/', "\n\n", $s);
        return trim($s);
    }
}
