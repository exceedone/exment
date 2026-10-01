<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\GlobalSearch\HeaderSuggester;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use PHPUnit\Framework\TestCase;

class HeaderSuggesterTest extends TestCase
{
    public function testLabelMatchDisplaysTheRecordNameOnlyOnce(): void
    {
        $pre = MeiliSearchService::HIGHLIGHT_PRE;
        $post = MeiliSearchService::HIGHLIGHT_POST;

        $this->assertSame(
            'Customer <mark>007</mark>',
            HeaderSuggester::suggestionText('Customer 007', "Customer {$pre}007{$post}")
        );
    }

    public function testAttachmentMatchDisplaysRecordNameAndSafeExcerpt(): void
    {
        $pre = MeiliSearchService::HIGHLIGHT_PRE;
        $post = MeiliSearchService::HIGHLIGHT_POST;

        $this->assertSame(
            'Customer &lt;007&gt; — …report <mark>budget</mark>.pdf',
            HeaderSuggester::suggestionText('Customer <007>', "…report {$pre}budget{$post}.pdf")
        );
        $this->assertSame(
            'Customer 007 — …&lt;script&gt;<mark>売上</mark>&lt;/script&gt;…',
            HeaderSuggester::suggestionText('Customer 007', "…<script>{$pre}売上{$post}</script>…")
        );
    }

    public function testFilenameAndContentAreEscapedBeforeHighlightMarkup(): void
    {
        $pre = MeiliSearchService::HIGHLIGHT_PRE;
        $post = MeiliSearchService::HIGHLIGHT_POST;
        $html = HeaderSuggester::suggestionText(
            'Parent <record>',
            "<img src=x onerror=alert(1)>.pdf — <script>{$pre}matched{$post}</script>"
        );

        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;.pdf', $html);
        $this->assertStringContainsString('&lt;script&gt;<mark>matched</mark>&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

}
