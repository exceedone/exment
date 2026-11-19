<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\CommentMarkdown;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * A comment is shown as Markdown, with nothing dangerous left in it, and the comment box
 * previews it and takes a pasted or dropped image.
 */
class CommentMarkdownTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

    /** a 1x1 PNG */
    protected const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected const SVG = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    /**
     * Files the tests put on the upload disk, which the transaction does not undo.
     *
     * @var array<string>
     */
    protected array $storedPaths = [];

    /** @var array<string> */
    protected array $tempPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        Notification::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->storedPaths as $path) {
            Storage::disk(config('admin.upload.disk'))->delete($path);
        }
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testMarkdownIsShown(): void
    {
        $html = CommentMarkdown::toHtml(implode("\n", [
            '# Heading',
            '',
            '- one',
            '- two',
            '',
            '1. first',
            '',
            'a **bold** and `code` and [link](https://example.com/page)',
            '',
            '```',
            'block <b>',
            '```',
            '',
            '| a | b |',
            '|---|---|',
            '| 1 | 2 |',
        ]));

        $this->assertStringContainsString('<h1>Heading</h1>', $html);
        $this->assertMatchesRegularExpression('/<ul>\s*<li>one<\/li>\s*<li>two<\/li>\s*<\/ul>/', $html);
        $this->assertMatchesRegularExpression('/<ol>\s*<li>first<\/li>\s*<\/ol>/', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*href="https:\/\/example\.com\/page"[^>]*>link<\/a>/', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*target="_blank"/', $html);
        $this->assertMatchesRegularExpression('/<pre><code>block &lt;b&gt;\s*<\/code><\/pre>/', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>a</th>', $html);
        $this->assertStringContainsString('<td>2</td>', $html);
    }

    public function testAPlainTextCommentKeepsItsLineBreaks(): void
    {
        $html = CommentMarkdown::toHtml("first line\nsecond line\r\nthird line\n\nnext paragraph");

        $this->assertMatchesRegularExpression('/first line<br \/>\s*second line<br \/>\s*third line/', $html);
        $this->assertStringContainsString('next paragraph', $html);
        $this->assertSame('firstlinesecondlinethirdlinenextparagraph', preg_replace('/\s+/', '', strip_tags($html)));

        $this->assertSame('', CommentMarkdown::toHtml(null));
        $this->assertSame('', CommentMarkdown::toHtml(''));
    }

    public function testNothingDangerousIsLeft(): void
    {
        $html = CommentMarkdown::toHtml(implode("\n\n", [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(2)>',
            '<a href="javascript:alert(3)" onclick="alert(4)">raw</a>',
            '<iframe src="https://evil.example.com"></iframe>',
            '[js](javascript:alert(5))',
            '[data](data:text/html;base64,PHNjcmlwdD5hbGVydCg2KTwvc2NyaXB0Pg==)',
            '[vb](vbscript:msgbox(7))',
            '![x](javascript:alert(8))',
            '![x" onerror="alert(9)](https://evil.example.com/a.png)',
            '<div style="position:fixed" onmouseover="alert(10)">div</div>',
        ]));

        // html of the comment is text, not html
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<div', $html);

        // no tag has an on* attribute or a script url
        preg_match_all('/<[a-zA-Z][^>]*>/', $html, $tags);
        $this->assertNotEmpty($tags[0]);
        foreach ($tags[0] as $tag) {
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $tag, $tag);
            $this->assertDoesNotMatchRegularExpression('/(javascript|vbscript|data)\s*:/i', $tag, $tag);
        }
    }

    public function testOnlyAnImageOfThisExmentIsShown(): void
    {
        $png = $this->attach('figure.png', (string) base64_decode(static::PNG_BASE64))->uuid;
        $svg = $this->attach('figure.svg', static::SVG)->uuid;

        $html = CommentMarkdown::toHtml("![図](/files/{$png})\n\n![svg](/files/{$svg})\n\n![out](https://other.example.com/a.png)");

        $this->assertSame(1, substr_count($html, '<img'));
        $this->assertMatchesRegularExpression('/<img [^>]*src="' . preg_quote(admin_urls('files', $png), '/') . '"/', $html);
        $this->assertMatchesRegularExpression('/<img [^>]*alt="図"/', $html);
        // the svg and the image of another site are links, not pictures
        $this->assertMatchesRegularExpression('/<a [^>]*href="\/files\/' . $svg . '"[^>]*>svg<\/a>/', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*href="https:\/\/other\.example\.com\/a\.png"[^>]*>out<\/a>/', $html);
    }

    public function testTheDataScreenShowsACommentAsMarkdownAndTheHint(): void
    {
        $custom_value = $this->createValue();
        $this->addComment($custom_value, "**strong point**\n<script>alert('c')</script>");

        System::clearRequestSession();
        $html = (string) $this->get($this->url($custom_value->id))->assertOk()->getContent();

        $this->assertStringContainsString('<strong>strong point</strong>', $html);
        $this->assertStringNotContainsString("<script>alert('c')</script>", $html);
        // the comment box tells that Markdown works, and where the preview and the images go
        $this->assertStringContainsString(exmtrans('common.comment_markdown.help'), $html);
        $this->assertStringContainsString('data-comment-markdown="1"', $html);
        $this->assertStringContainsString($this->url($custom_value->id, 'previewcomment'), $html);
        $this->assertStringContainsString($this->url($custom_value->id, 'commentimage'), $html);
    }

    public function testThePreviewAnswersTheHtmlOfTheComment(): void
    {
        $custom_value = $this->createValue();

        System::clearRequestSession();
        $response = $this->post($this->url($custom_value->id, 'previewcomment'), ['comment' => "# Title\n<script>alert(1)</script>"])
            ->assertOk()
            ->assertJson(['result' => true]);

        $this->assertStringContainsString('<h1>Title</h1>', (string) $response->json('html'));
        $this->assertStringNotContainsString('<script', (string) $response->json('html'));
    }

    public function testAnImageOfTheCommentBoxIsAttachedToTheData(): void
    {
        $custom_value = $this->createValue();

        System::clearRequestSession();
        $response = $this->post($this->url($custom_value->id, 'commentimage'), [
            // the name says jpg, the content is a png: the name follows the content
            'file' => $this->upload('shot [1].jpg', (string) base64_decode(static::PNG_BASE64)),
        ])->assertOk()->assertJson(['result' => true]);

        $document = $custom_value->getDocuments()->first();
        $this->assertNotNull($document);
        $file = ExmentFile::where('uuid', $document->file_uuid)->first();
        $this->assertNotNull($file);
        $this->storedPaths[] = $file->path;

        $this->assertSame(admin_urls('files', $file->uuid), $response->json('url'));
        $this->assertSame('shot [1].png', $file->filename);
        $this->assertSame('![shot (1)](' . admin_urls('files', $file->uuid) . ')', $response->json('markdown'));
        // and that Markdown is shown as the picture
        $this->assertStringContainsString('<img', CommentMarkdown::toHtml($response->json('markdown')));
    }

    public function testOnlyAnImageIsTakenByTheCommentBox(): void
    {
        $custom_value = $this->createValue();

        // svg, and a text and a script named as a picture
        $files = [
            $this->upload('a.svg', static::SVG),
            $this->upload('a.png', 'this is not a picture'),
            $this->upload('a.png', '<script>alert(1)</script>'),
            $this->upload('a.png', static::SVG),
        ];
        foreach ($files as $file) {
            System::clearRequestSession();
            $this->post($this->url($custom_value->id, 'commentimage'), ['file' => $file])
                ->assertStatus(400)
                ->assertJson(['result' => false]);
        }

        // a picture larger than the limit
        config(['exment.comment_image_max_size_kb' => 1]);
        $large = (string) base64_decode(static::PNG_BASE64) . str_repeat("\0", 2048);
        System::clearRequestSession();
        $this->post($this->url($custom_value->id, 'commentimage'), ['file' => $this->upload('large.png', $large)])
            ->assertStatus(400);

        System::clearRequestSession();
        $this->post($this->url($custom_value->id, 'commentimage'), [])->assertStatus(400);

        $this->assertCount(0, $this->documentsOf(static::TABLE, $custom_value->id));
    }

    public function testAUserWhoCannotSeeTheDataCannotPreviewOrAttach(): void
    {
        // user2 has no permission to this data of custom_value_edit
        $table = TestDefine::TESTDATA_TABLE_NAME_EDIT;
        $url = admin_urls('data', $table, 1);
        $before = count($this->documentsOf($table, 1));

        $this->app['auth']->forgetGuards();
        System::clearRequestSession();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));

        // as comment_markdown.js sends them
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $preview = $this->post($url . '/previewcomment', ['comment' => '# x'], $ajax)->assertStatus(403);
        $this->assertStringNotContainsString('<h1>x</h1>', (string) $preview->getContent());

        System::clearRequestSession();
        $this->post($url . '/commentimage', [
            'file' => $this->upload('a.png', (string) base64_decode(static::PNG_BASE64)),
        ], $ajax)->assertStatus(403);

        $this->assertSame($before, count($this->documentsOf($table, 1)));
    }

    // helpers ----------------------------------------------------

    protected function url(string|int ...$paths): string
    {
        return admin_urls('data', static::TABLE, ...$paths);
    }

    /**
     * @return array<int, mixed>
     */
    protected function documentsOf(string $table, int|string $id): array
    {
        return getModelName(SystemTableName::DOCUMENT)::withoutGlobalScopes()
            ->where('parent_type', $table)
            ->where('parent_id', $id)
            ->whereNull('deleted_at')
            ->get()
            ->all();
    }

    /**
     * A file as a browser sends it: its type is read from its content, which a fake file of
     * Laravel answers from its name.
     */
    protected function upload(string $name, string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'exm');
        file_put_contents($path, $content);
        $this->tempPaths[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function attach(string $name, string $content): ExmentFile
    {
        $custom_value = $this->createValue();
        $file = ExmentFile::storeAs(FileType::CUSTOM_VALUE_DOCUMENT, $content, static::TABLE, $name)
            ->saveCustomValue($custom_value->id, null, $custom_value->custom_table);
        $file->saveDocumentModel($custom_value, $name);
        $this->storedPaths[] = $file->path;

        return $file;
    }

    protected function addComment(CustomValue $custom_value, string $text): void
    {
        $comment = CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel();
        $comment->parent_id = $custom_value->id;
        $comment->parent_type = static::TABLE;
        $comment->setValue(['comment_detail' => $text]);
        $comment->save();
    }

    protected function createValue(): CustomValue
    {
        $custom_value = CustomTable::getEloquent(static::TABLE)->getValueModel();
        if (!$custom_value instanceof CustomValue) {
            $this->fail('no value model');
        }
        $custom_value->setValue(['text' => 'comment markdown test']);
        $custom_value->save();

        return $custom_value;
    }
}
