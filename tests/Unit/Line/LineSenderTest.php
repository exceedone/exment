<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Notifications\LineSender;
use Exceedone\Exment\Tests\Unit\UnitTestBase;
use Illuminate\Support\Facades\Bus;

/**
 * Phase 1: LineSender — text branch. Subject/body come from the mail template (HTML),
 * so they must be converted to plain text before being pushed to LINE (LINE does not
 * render HTML).
 *
 * Verified through the public send() path: Bus::fake() intercepts LineSendJob, then its
 * contents are inspected.
 */
class LineSenderTest extends UnitTestBase
{
    public const TO = 'Uabcdef';

    /** Sends, then returns the messages array pushed into the job (null if no job was dispatched). */
    protected function sentMessages(string $subject, string $body): ?array
    {
        Bus::fake();

        LineSender::make(static::TO, $subject, $body)->send();

        $dispatched = collect(Bus::dispatchedAfterResponse(LineSendJob::class));
        if ($dispatched->isEmpty()) {
            return null;
        }

        $prop = (new \ReflectionClass(LineSendJob::class))->getProperty('messages');
        $prop->setAccessible(true);
        return $prop->getValue($dispatched->first());
    }

    /** The sent text (first message). */
    protected function sentText(string $subject, string $body): ?string
    {
        $messages = $this->sentMessages($subject, $body);
        return $messages === null ? null : $messages[0]['text'];
    }

    public function test_anchor_becomes_bare_url_because_line_renders_links_itself(): void
    {
        $text = $this->sentText('', '<a href="https://example.com/detail/1">Xem chi tiết</a>');

        $this->assertEquals('https://example.com/detail/1', $text);
    }

    public function test_block_tags_become_newlines(): void
    {
        $text = $this->sentText('', 'dòng 1<br />dòng 2<p>dòng 3</p>');

        $this->assertEquals("dòng 1\ndòng 2\ndòng 3", $text);
    }

    public function test_html_entities_are_decoded(): void
    {
        $text = $this->sentText('', 'A &amp; B &lt;C&gt; &quot;D&quot;');

        $this->assertEquals('A & B <C> "D"', $text);
    }

    public function test_remaining_tags_are_stripped(): void
    {
        $text = $this->sentText('', '<div><strong>đậm</strong> thường</div>');

        $this->assertEquals('đậm thường', $text);
    }

    public function test_subject_is_put_on_its_own_line_above_body(): void
    {
        $text = $this->sentText('Tiêu đề', 'Nội dung');

        $this->assertEquals("Tiêu đề\nNội dung", $text);
    }

    public function test_excess_blank_lines_are_collapsed(): void
    {
        // The mail template HTML often emits a run of empty </p><p>, which would flood LINE with blank lines
        $text = $this->sentText('', 'trên</p><p></p><p></p><p></p>dưới');

        $this->assertEquals("trên\n\ndưới", $text);
    }

    public function test_message_is_text_type_and_context_marks_it_as_text(): void
    {
        Bus::fake();

        LineSender::make(static::TO, 'Tiêu đề', 'Nội dung')->send();

        Bus::assertDispatchedAfterResponse(LineSendJob::class, function ($job) {
            $reflection = new \ReflectionClass($job);

            $to = $reflection->getProperty('to');
            $to->setAccessible(true);
            $this->assertEquals(static::TO, $to->getValue($job));

            $messages = $reflection->getProperty('messages');
            $messages->setAccessible(true);
            $this->assertEquals('text', $messages->getValue($job)[0]['type']);

            $context = $reflection->getProperty('context');
            $context->setAccessible(true);
            $this->assertEquals('text', $context->getValue($job)['message_type']);

            return true;
        });
    }

    public function test_nothing_is_sent_when_there_is_no_recipient(): void
    {
        Bus::fake();

        LineSender::make('', 'Tiêu đề', 'Nội dung')->send();

        Bus::assertNotDispatchedAfterResponse(LineSendJob::class);
    }

    public function test_nothing_is_sent_when_content_is_empty_after_stripping_html(): void
    {
        // Body contains only empty tags -> stripping them leaves an empty string -> LINE would return a 400 error if sent
        $this->assertNull($this->sentMessages('', '<p></p><br />'));
    }
}
