<?php

namespace Exceedone\Exment\Notifications;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\WebhookUrlGuard;
use Illuminate\Support\Facades\Http;

/**
 * A notification to a person's Teams chat through a Teams workflow (Power Automate, "When a Teams
 * webhook request is received"; ISS02863). Exment posts JSON to the workflow; the workflow posts
 * the Adaptive Card of the JSON to the chat of the person (to_email) as Flow bot.
 *
 * The address of the workflow is a secret (whoever has it can post to the chats of the company):
 * a system setting that only the system administrators set, never shown on a page again, never
 * written to a log (only its host).
 *
 * The JSON: to_email, title, body, url, sender, locale, card (an Adaptive Card 1.4: the title in
 * bold, the body as short paragraphs, a numbered list kept as one, the button to the data).
 */
class TeamsWorkflowSender extends SenderBase
{
    /** @var string */
    protected $to_email;

    /** @var string|null */
    protected $url;

    /** @var string|null */
    protected $sender_name;

    /** @var string */
    protected $locale;

    /** @var string|null the address of the workflow (System::system_teams_workflow_url when null) */
    protected $workflow_url;

    /** @var int|null the HTTP status of the post (null: not posted) */
    protected $status;

    /**
     * @param string $to_email
     * @param string $subject
     * @param string $body
     * @param array<string, mixed> $options url, sender, locale, workflow_url
     */
    public function __construct($to_email, $subject, $body, $options = [])
    {
        $this->subject = $subject;
        $this->body = $body;
        $this->to_email = strval($to_email);
        $this->url = array_get($options, 'url');
        $this->sender_name = array_get($options, 'sender');
        $this->locale = strval(array_get($options, 'locale') ?: app()->getLocale());
        $this->workflow_url = array_get($options, 'workflow_url');
    }

    /**
     * @param string $to_email
     * @param string $subject
     * @param string $body
     * @param array<string, mixed> $options
     */
    public static function make($to_email, $subject, $body, $options = []): self
    {
        return new self($to_email, $subject, $body, $options);
    }

    /**
     * The JSON posted to the workflow.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'to_email' => $this->to_email,
            'title' => strval($this->subject),
            'body' => strval($this->body),
            'url' => $this->url,
            'sender' => $this->sender_name,
            'locale' => $this->locale,
            'card' => $this->card(),
        ];
    }

    /**
     * The Adaptive Card the workflow posts as it is.
     *
     * @return array<string, mixed>
     */
    public function card(): array
    {
        $items = [[
            'type' => 'TextBlock',
            'text' => strval($this->subject),
            'weight' => 'Bolder',
            'size' => 'Medium',
            'wrap' => true,
        ]];
        if (!is_nullorempty($this->sender_name)) {
            $items[] = ['type' => 'TextBlock', 'text' => strval($this->sender_name), 'isSubtle' => true, 'spacing' => 'None', 'size' => 'Small', 'wrap' => true];
        }
        foreach (static::paragraphs(strval($this->body)) as $paragraph) {
            // TextBlock reads **bold**, [link](url), "1. " and "- " lists (Teams' markdown)
            $items[] = ['type' => 'TextBlock', 'text' => $paragraph, 'wrap' => true];
        }
        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => $items,
        ];
        if (!is_nullorempty($this->url)) {
            $card['actions'] = [[
                'type' => 'Action.OpenUrl',
                'title' => strval(trans('exment::exment.notify.teams_open', [], $this->locale)),
                'url' => strval($this->url),
            ]];
        }
        return $card;
    }

    /**
     * The paragraphs of a text (separated by an empty line). The lines of a paragraph stay
     * together (a numbered list is one block); an HTML body of a mail template loses its tags.
     *
     * @return array<int, string>
     */
    public static function paragraphs(string $body): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        if ($text !== strip_tags($text)) {
            $text = preg_replace('#<br\s*/?>|</p>|</div>|</li>#i', "\n", $text) ?? $text;
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $paragraphs = [];
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph !== '') {
                // Teams' TextBlock breaks a line only at an empty line: one per line of the text
                $paragraphs[] = implode("\n\n", array_map('trim', explode("\n", $paragraph)));
            }
        }
        return $paragraphs;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * Post to the workflow. Nothing is posted without its address, to an address that is not
     * allowed (WebhookUrlGuard), or without the mail address of the person.
     */
    public function send()
    {
        $url = strval($this->workflow_url ?? System::system_teams_workflow_url());
        if ($url === '' || $this->to_email === '') {
            return;
        }
        $host = strval(parse_url($url, PHP_URL_HOST));
        if (!WebhookUrlGuard::isAllowed($url)) {
            \Log::warning('Exment notify: the address of the Teams workflow is not allowed and nothing was sent: ' . $host);
            return;
        }
        try {
            $response = Http::timeout(15)->connectTimeout(5)->withoutRedirecting()->asJson()->post($url, $this->payload());
            $this->status = $response->status();
            if (!$response->successful()) {
                // the address is a secret: only its host is written
                \Log::warning('Exment notify: the Teams workflow answered ' . $response->status() . ' (' . $host . ')');
            }
        } catch (\Throwable $e) {
            \Log::warning('Exment notify: the Teams workflow could not be reached (' . $host . '): ' . get_class($e));
        }
    }
}
