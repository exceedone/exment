<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\MarkdownFileImage;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * "![alt](file url of this Exment)" in the body of an editor column and in a comment is
 * shown as the picture, and the picture is sent only to the users who can see the data the
 * file is attached to.
 */
class MarkdownFileImageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** a 1x1 PNG */
    protected const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    /**
     * Files the tests put on the upload disk, which the transaction does not undo.
     *
     * @var array<string>
     */
    protected array $storedPaths = [];

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
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testAFileUrlOfThisExmentBecomesAnImage(): void
    {
        $uuid = $this->attach('figure.png', (string) base64_decode(static::PNG_BASE64))->uuid;
        $img = '<img src="' . admin_urls('files', $uuid) . '" alt="fig" style="max-width:100%;height:auto;">';
        $adminPath = rtrim(strval(parse_url(admin_url(''), PHP_URL_PATH)), '/');

        // the "url" the document api answers, the path from the root, and the short form
        $this->assertSame("a {$img} b", MarkdownFileImage::toHtml('a ![fig](' . admin_urls('files', $uuid) . ') b'));
        $this->assertSame($img, MarkdownFileImage::toHtml("![fig]({$adminPath}/files/{$uuid})"));
        $this->assertSame($img, MarkdownFileImage::toHtml("![fig](/files/{$uuid})"));
        $this->assertSame($img, MarkdownFileImage::toHtml("![fig](files/{$uuid})"));
        $this->assertSame($img, MarkdownFileImage::toHtml('![fig](/files/' . strtoupper($uuid) . ')'));
    }

    public function testOtherUrlsAreLeftAsText(): void
    {
        $uuid = $this->attach('figure.png', (string) base64_decode(static::PNG_BASE64))->uuid;

        foreach ([
            // another site, even with the same path
            "![x](https://evil.example.com/admin/files/{$uuid})",
            "![x](//evil.example.com/files/{$uuid})",
            "![x](https://evil.example.com/a.png)",
            "![x](javascript:alert(1))",
            "![x](/xfiles/{$uuid})",
            "![x](/files/{$uuid}?a=1)",
            // no such file
            '![x](/files/00000000-0000-0000-0000-000000000000)',
            // a link, not an image
            "[x](/files/{$uuid})",
            'plain text',
        ] as $text) {
            $this->assertSame($text, MarkdownFileImage::toHtml($text), $text);
        }

        $this->assertNull(MarkdownFileImage::toHtml(null));
    }

    public function testOnlyPngJpegGifAndWebpAreShown(): void
    {
        foreach (['a.png', 'a.PNG', 'a.jpg', 'a.jpeg', 'a.gif', 'a.webp'] as $name) {
            $uuid = $this->attach($name, (string) base64_decode(static::PNG_BASE64))->uuid;
            $this->assertStringStartsWith('<img ', (string) MarkdownFileImage::toHtml("![x](/files/{$uuid})"), $name);
        }

        // svg can hold a script; the others are no pictures
        foreach (['a.svg' => static::SVG, 'a.txt' => 'test', 'a.html' => '<script>alert(1)</script>', 'a.bmp' => 'BM', 'noext' => 'x'] as $name => $content) {
            $uuid = $this->attach($name, $content)->uuid;
            $text = "![x](/files/{$uuid})";
            $this->assertSame($text, MarkdownFileImage::toHtml($text), $name);
        }
    }

    public function testTheAltCannotBreakOutOfTheTag(): void
    {
        $uuid = $this->attach('figure.png', (string) base64_decode(static::PNG_BASE64))->uuid;

        // as a comment has it: escaped before it is handed over
        $html = (string) MarkdownFileImage::toHtml(replaceBreakEsc('![" onerror="alert(1)" x="<b>](/files/' . $uuid . ')'));

        $this->assertStringNotContainsString('onerror="', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertSame(1, substr_count($html, '<'));
        $this->assertStringContainsString('alt="&quot; onerror=&quot;alert(1)&quot; x=&quot;', $html);
    }

    public function testTheBodyOfAnEditorColumnShowsTheImage(): void
    {
        $uuid = $this->attach('figure.png', (string) base64_decode(static::PNG_BASE64))->uuid;
        $svg = $this->attach('figure.svg', static::SVG)->uuid;

        $body = "<p>before</p>\n![図 1](/files/{$uuid})\n![svg](/files/{$svg})\n<script>alert(1)</script>";
        $html = $this->editorHtml($body);

        $this->assertStringContainsString('<div class="show-tinymce">', $html);
        $this->assertMatchesRegularExpression('/<img [^>]*src="' . preg_quote(admin_urls('files', $uuid), '/') . '"/', $html);
        $this->assertStringContainsString('alt="図 1"', $html);
        // the svg stays as text, and html_clean() still works on the whole
        $this->assertStringContainsString("![svg](/files/{$svg})", $html);
        $this->assertStringNotContainsString('<script', $html);

        // in a list the body is plain, omitted text: no picture
        $grid = $this->editorHtml($body, ['grid_column' => true]);
        $this->assertStringNotContainsString('<img', $grid);
    }

    public function testACommentShowsTheImageOnTheDataScreen(): void
    {
        $custom_value = $this->createValue();
        $uuid = $this->attach('figure.png', (string) base64_decode(static::PNG_BASE64), $custom_value)->uuid;
        $comment = CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel();
        $comment->parent_id = $custom_value->id;
        $comment->parent_type = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $comment->setValue(['comment_detail' => "see the figure
![図](" . admin_urls('files', $uuid) . ")
<script>alert('c')</script>"]);
        $comment->save();

        System::clearRequestSession();
        $html = (string) $this->get(admin_urls('data', TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL, $custom_value->id))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<img [^>]*src="' . preg_quote(admin_urls('files', $uuid), '/') . '"/', $html);
        // the rest of the comment keeps its line break, and its html is text
        $this->assertStringContainsString('see the figure<br />', $html);
        $this->assertStringNotContainsString("<script>alert('c')</script>", $html);
    }

    public function testTheImageIsSentOnlyToUsersWhoCanSeeTheData(): void
    {
        // user2 has no permission to this data of custom_value_edit
        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT)->getValueModel(1);
        $png = (string) base64_decode(static::PNG_BASE64);
        $url = admin_urls('files', $this->attach('figure.png', $png, $custom_value)->uuid);

        // a user who can see the data
        $this->forgetUser();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $response = $this->get($url)->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame($png, $this->getDownloadedContent($response));

        // a user who cannot
        $this->forgetUser();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->get($url)->assertStatus(403);

        // not logged in: sent to the login page
        $this->forgetUser();
        $this->get($url)->assertRedirect(admin_url('auth/login'));
    }

    // helpers ----------------------------------------------------

    protected function forgetUser(): void
    {
        $this->app['auth']->forgetGuards();
        System::clearRequestSession();
    }

    /**
     * Attach a file to a data, as POST api/document/{tableKey}/{id} does.
     */
    protected function attach(string $name, string $content, ?CustomValue $custom_value = null): ExmentFile
    {
        $custom_value = $custom_value ?? $this->createValue();
        $custom_table = $custom_value->custom_table;

        $file = ExmentFile::storeAs(FileType::CUSTOM_VALUE_DOCUMENT, $content, $custom_table->table_name, $name)
            ->saveCustomValue($custom_value->id, null, $custom_table);
        $file->saveDocumentModel($custom_value, $name);
        $this->storedPaths[] = $file->path;

        return $file;
    }

    protected function createValue(): CustomValue
    {
        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->getValueModel();
        if (!$custom_value instanceof CustomValue) {
            $this->fail('no value model');
        }
        $custom_value->setValue(['text' => 'markdown file image test']);
        $custom_value->save();

        return $custom_value;
    }

    /**
     * The html an editor column shows for a body.
     *
     * @param array<string, mixed> $options
     */
    protected function editorHtml(string $body, array $options = []): string
    {
        $custom_column = CustomColumn::getEloquent('editor', TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS);
        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS)->getValueModel();
        $custom_value->setValue(['editor' => $body]);

        return (string) $custom_column->column_item->options($options)->setCustomValue($custom_value)->html();
    }
}
