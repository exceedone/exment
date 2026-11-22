<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileNodeVersion;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\FileServer\Access;
use Exceedone\Exment\Services\FileServer\Library;
use Exceedone\Exment\Services\FileServer\Preview;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * File server: the PDF of an Office file made by a converter of its own on
 * the inner network (Gotenberg with LibreOffice, ISS01704).
 */
class FileOfficeConverterTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected const URL = 'http://office:3000';
    protected const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    /** @var string[] */
    protected array $locals = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Storage::fake(Define::DISKNAME_ADMIN);
        config(['exment.file_server_soffice_path' => null, 'exment.file_server_soffice_url' => static::URL, 'exment.file_server_soffice_timeout' => 30]);
    }

    protected function tearDown(): void
    {
        foreach ($this->locals as $local) {
            @unlink($local);
        }
        parent::tearDown();
    }

    protected function admin(): Library
    {
        return new Library(new Access(intval(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id), true));
    }

    protected function local(string $content): string
    {
        $path = Library::tempPath('test_');
        file_put_contents($path, $content);
        $this->locals[] = $path;
        return $path;
    }

    protected function upload(string $name = 'plan.docx', string $content = 'office bytes'): FileNodeVersion
    {
        $library = $this->admin();
        $ws = $library->createWorkspace(['name' => 'Office ' . uniqid()]);
        /** @var FileNode $node */
        $node = $library->storeFile($ws, null, $name, $this->local($content));
        /** @var FileNodeVersion $version */
        $version = $node->refresh()->current_version;
        // what the queue does
        Preview::makeRenditions($version);
        return $version->refresh();
    }

    public function testTheConverterMakesThePdfAndKeepsNothingOfTheName(): void
    {
        Http::fake([static::URL . '/forms/libreoffice/convert' => Http::response(static::PDF, 200, ['Content-Type' => 'application/pdf'])]);
        $this->assertTrue(Preview::canConvertOffice());

        $version = $this->upload('秘密の計画.docx');

        $this->assertSame(FileNodeVersion::RENDITION_DONE, $version->rendition_status);
        $this->assertNotNull($version->preview_path);
        $this->assertSame(static::PDF, Library::disk()->get(strval($version->preview_path)));
        // only the file, with a plain name, to the converter of the inner network
        Http::assertSent(function (Request $request) {
            $file = collect($request->data())->firstWhere('name', 'files');
            return $request->url() === static::URL . '/forms/libreoffice/convert'
                && $request->method() === 'POST'
                && is_array($file) && $file['filename'] === 'input.docx';
        });
        Http::assertSentCount(1);
    }

    public function testABusyConverterIsAskedAgain(): void
    {
        Http::fakeSequence(static::URL . '/*')
            ->push('queue full', 429)
            ->push(static::PDF, 200, ['Content-Type' => 'application/pdf']);

        $version = $this->upload('sheet.xlsx');

        $this->assertSame(FileNodeVersion::RENDITION_DONE, $version->rendition_status);
        Http::assertSentCount(2);
    }

    public function testAnAnswerThatIsNotAPdfIsAFailure(): void
    {
        Http::fake([static::URL . '/*' => Http::response('<html>error</html>', 200)]);
        $this->assertSame(FileNodeVersion::RENDITION_FAILED, $this->upload('slides.pptx')->rendition_status);

        Http::fake([static::URL . '/*' => Http::response('broken', 500)]);
        $this->assertSame(FileNodeVersion::RENDITION_FAILED, $this->upload('other.docx')->rendition_status);
    }

    public function testAFileTooLargeIsNotSent(): void
    {
        Http::fake();
        config(['exment.file_server_preview_max_bytes' => 10]);

        $version = $this->upload('big.docx', str_repeat('x', 100));

        $this->assertSame(FileNodeVersion::RENDITION_NONE, $version->rendition_status);
        Http::assertNothingSent();
    }

    public function testOnlyAnHttpAddressIsUsed(): void
    {
        Http::fake();
        foreach (['file:///etc/passwd', 'gopher://office:3000', 'http://office:3000/forms?x=1', 'office:3000', ''] as $url) {
            config(['exment.file_server_soffice_url' => $url]);
            $this->assertNull(Preview::sofficeUrl(), $url);
        }
        $this->assertFalse(Preview::canConvertOffice());
        $this->assertSame(FileNodeVersion::RENDITION_NONE, $this->upload('plan.docx')->rendition_status);
        Http::assertNothingSent();

        config(['exment.file_server_soffice_url' => 'http://office:3000/']);
        $this->assertSame('http://office:3000', Preview::sofficeUrl());
    }

    public function testTheUploadDoesNotConvertTheFirstViewDoesOnce(): void
    {
        Http::fake([static::URL . '/*' => Http::response(static::PDF, 200)]);
        $library = $this->admin();
        $ws = $library->createWorkspace(['name' => 'Lazy']);
        /** @var FileNode $node */
        $node = $library->storeFile($ws, null, 'plan.docx', $this->local('office bytes'));
        /** @var FileNodeVersion $version */
        $version = $node->refresh()->current_version;

        // the upload: nothing sent, the PDF not made yet
        $this->assertNull($version->rendition_status);
        Http::assertNothingSent();

        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $base = admin_urls('file_server', $ws->id, 'nodes', $node->id);
        $ajax = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        // the page waits for it, and asks
        $this->get($base, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('data-fs-preview-wait="' . $base . '/preview"', false);
        // the first ask converts
        $this->post($base . '/preview', [], $ajax)->assertOk()->assertJson(['status' => 'done', 'pdf_url' => $base . '/content?rendition=pdf']);
        Http::assertSentCount(1);
        $this->assertSame(FileNodeVersion::RENDITION_DONE, $version->refresh()->rendition_status);
        // asked again: kept, not sent again
        $this->post($base . '/preview', [], $ajax)->assertOk()->assertJson(['status' => 'done']);
        Http::assertSentCount(1);
    }

    public function testWhileOneViewerConvertsTheOthersWait(): void
    {
        Http::fake([static::URL . '/*' => Http::response(static::PDF, 200)]);
        $library = $this->admin();
        $ws = $library->createWorkspace(['name' => 'Two']);
        /** @var FileNode $node */
        $node = $library->storeFile($ws, null, 'plan.docx', $this->local('office bytes'));
        /** @var FileNodeVersion $version */
        $version = $node->refresh()->current_version;
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $url = admin_urls('file_server', $ws->id, 'nodes', $node->id, 'preview');
        $ajax = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

        // another viewer is converting it (holds the lock)
        $lock = \Illuminate\Support\Facades\Cache::lock('exment_file_server_convert_' . $version->id, 60);
        $this->assertTrue((bool) $lock->get());
        $this->post($url, [], $ajax)->assertOk()->assertJson(['status' => 'converting', 'pdf_url' => null]);
        Http::assertNothingSent();
        $this->assertNull($version->refresh()->rendition_status);

        // done there: the next ask converts once
        $lock->release();
        $this->post($url, [], $ajax)->assertOk()->assertJson(['status' => 'done']);
        Http::assertSentCount(1);
    }

    public function testTheUserAndPasswordAreSentAsBasicAuthNotInTheAddress(): void
    {
        Http::fake([static::URL . '/*' => Http::response(static::PDF, 200)]);
        config(['exment.file_server_soffice_url' => 'http://exment:p%40ss@office:3000']);
        $this->assertSame(static::URL, Preview::sofficeUrl());

        $this->assertSame(FileNodeVersion::RENDITION_DONE, $this->upload()->rendition_status);
        Http::assertSent(function (Request $request) {
            return $request->url() === static::URL . '/forms/libreoffice/convert'
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode('exment:p@ss'));
        });
    }

    public function testARedirectIsNotFollowed(): void
    {
        Http::fake([
            static::URL . '/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response(static::PDF, 200),
        ]);

        $this->assertSame(FileNodeVersion::RENDITION_FAILED, $this->upload()->rendition_status);
        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '169.254.169.254');
        });
    }

    public function testAnAnswerPastTheLimitIsNotTaken(): void
    {
        config(['exment.file_server_soffice_max_pdf_bytes' => 1048576]);
        Http::fake([static::URL . '/*' => Http::response(static::PDF . str_repeat('x', 1048576), 200)]);

        $this->assertSame(FileNodeVersion::RENDITION_FAILED, $this->upload()->rendition_status);
    }
}
