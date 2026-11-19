<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * FileController: download and delete of stored files (by uuid, as a record attachment, through
 * the web api), the temporary uploads of the editors (admin screens and public forms), the
 * images of the login page and the favicon, and a public form's own files. The stored files are
 * outside the database transaction, so tearDown() removes what the tests put on the disks.
 */
class FileRoutesCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /**
     * @var array<int, array{0: string, 1: string}> disk name and path of the files the test stored
     */
    protected $storedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        foreach ($this->storedFiles as [$disk, $path]) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
        }
        System::clearCache();

        parent::tearDown();
    }

    public function testDownloadAndDeleteAFileByUuid(): void
    {
        $this->loginAsAdmin();
        $file = $this->store(FileType::CUSTOM_VALUE_DOCUMENT, TestDefine::FILE_TESTSTRING, 'claude_coverage', 'coverage.txt');

        $response = $this->get(admin_url("files/{$file->uuid}"))->assertOk();
        $this->assertSame(TestDefine::FILE_TESTSTRING, $this->downloadedContent($response));
        // never inline unless configured (an svg shown inline is an xss)
        $this->assertSame("attachment; filename*=UTF-8''coverage.txt", $response->headers->get('Content-Disposition'));

        $this->delete(admin_url("files/{$file->uuid}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);
        $this->assertDatabaseMissing('files', ['uuid' => $file->uuid]);
        $this->assertFalse(Storage::disk(config('admin.upload.disk'))->exists($file->path));

        // gone: the download answers not found
        $this->get(admin_url("files/{$file->uuid}"))->assertNotFound();
    }

    public function testDownloadAndDeleteARecordAttachment(): void
    {
        $this->loginAsAdmin();
        [$custom_table, , $file] = $this->recordWithAttachment();
        $path = "files/{$custom_table->table_name}/{$file->local_filename}";

        $response = $this->get(admin_url($path))->assertOk();
        $this->assertSame(TestDefine::FILE_TESTSTRING, $this->downloadedContent($response));
        $this->assertSame("attachment; filename*=UTF-8''attachment.txt", $response->headers->get('Content-Disposition'));

        $this->delete(admin_url($path))
            ->assertOk()
            ->assertJson(['status' => true]);
        $this->assertDatabaseMissing('files', ['uuid' => $file->uuid]);
        $this->assertFalse(Storage::disk(config('admin.upload.disk'))->exists($file->path));
    }

    public function testWebApiDeleteOfAnAttachmentClearsTheColumn(): void
    {
        $this->loginAsAdmin();
        [$custom_table, $custom_value, $file] = $this->recordWithAttachment();

        $this->delete(admin_url("webapi/files/{$custom_table->table_name}/{$file->local_filename}"))
            ->assertNoContent();
        $this->assertDatabaseMissing('files', ['uuid' => $file->uuid]);
        $this->assertFalse(Storage::disk(config('admin.upload.disk'))->exists($file->path));

        // unlike the screen's delete, the api takes the file out of the record as well (read it
        // again: exment keeps the records it read in a static request cache)
        System::clearRequestSession();
        $saved = $custom_table->getValueModel($custom_value->id);
        if (!$saved instanceof CustomValue) {
            $this->fail('the record must stay');
        }
        $this->assertEmpty($saved->getValue('file'));
    }

    public function testTemporaryUploadsOfTheAdminScreens(): void
    {
        $this->loginAsAdmin();

        $response = $this->post(admin_url('tmpfiles'), [
            'file' => UploadedFile::fake()->createWithContent('memo.txt', TestDefine::FILE2_TESTSTRING),
        ])->assertOk();
        $location = $this->uploadedLocation($response, admin_url('tmpfiles/'));
        $download = $this->get($location)->assertOk();
        $this->assertSame(TestDefine::FILE2_TESTSTRING, $this->downloadedContent($download));
        $this->assertSame('attachment; filename*=UTF-8\'\'' . basename($location), $download->headers->get('Content-Disposition'));

        $png = $this->png();
        $response = $this->post(admin_url('tmpimages'), [
            'file' => UploadedFile::fake()->createWithContent('picture.png', $png),
        ])->assertOk();
        $location = $this->uploadedLocation($response, admin_url('tmpfiles/'));
        $this->assertSame($png, $this->downloadedContent($this->get($location)->assertOk()));

        // the image upload takes images only
        $response = $this->post(admin_url('tmpimages'), [
            'file' => UploadedFile::fake()->createWithContent('memo.txt', TestDefine::FILE2_TESTSTRING),
        ])->assertStatus(400);
        $this->assertCount(1, (array) $response->json());
    }

    public function testPublicFormServesItsFilesAndTemporaryImages(): void
    {
        // a visitor of the public form, not logged in
        $public_form = $this->publicForm();
        $png = $this->png();

        // the header logo of a public form is a PUBLIC_FORM file the form links to through its own route
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $logo = $this->store(FileType::PUBLIC_FORM, $png, "publicform/{$custom_table->table_name}", 'logo.png');
        $url = ExmentFile::getUrl($logo->uuid, ['asPublicForm' => true, 'publicFormKey' => $public_form->uuid]);
        $this->assertNotNull($url);
        $response = $this->get((string) $url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($png, $this->downloadedContent($response));

        // an image put into an editor field of the form: uploaded, then read back through the form
        $response = $this->post($public_form->getUrl('tmpimages'), [
            'file' => UploadedFile::fake()->createWithContent('picture.png', $png),
        ])->assertOk();
        $location = $this->uploadedLocation($response, $public_form->getUrl('tmpfiles') . '/');
        $this->assertSame($png, $this->downloadedContent($this->get($location)->assertOk()));
    }

    public function testLoginPageImagesAndFavicon(): void
    {
        // the login page shows them before anybody is logged in
        $png = $this->png();
        foreach ([
            'site_favicon' => 'favicon',
            'login_page_image' => 'auth/file/background',
            'site_logo' => 'auth/file/header',
        ] as $system_name => $uri) {
            // what the system setting screens save: a SYSTEM file and its path in the setting. Set
            // directly so that a file a setting may already point at is not deleted from the disk
            // (the setter removes the old file, and the disk is outside the transaction).
            $file = $this->store(FileType::SYSTEM, $png, 'system', "{$system_name}.png");
            $system = System::firstOrNew(['system_name' => $system_name]);
            $system->system_value = $file->path;
            $system->save();

            $response = $this->get(admin_url($uri))->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->assertSame($png, $this->downloadedContent($response), $uri);
        }
    }

    protected function loginAsAdmin(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * @param mixed $content
     */
    protected function store(string $file_type, $content, string $dirname, string $name): ExmentFile
    {
        $file = ExmentFile::storeAs($file_type, $content, $dirname, $name);
        $this->storedFiles[] = [strval(config('admin.upload.disk')), $file->path];

        return $file;
    }

    /**
     * A record of all_columns_table_fortest whose file column holds a stored file, the way
     * CustomValueDeleteTest builds one.
     *
     * @return array{0: CustomTable, 1: CustomValue, 2: ExmentFile}
     */
    protected function recordWithAttachment(): array
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        if (!$custom_table instanceof CustomTable) {
            $this->fail('the test data must have ' . TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        }
        $custom_column = CustomColumn::getEloquent('file', $custom_table);

        $file = $this->store(FileType::CUSTOM_VALUE_COLUMN, TestDefine::FILE_TESTSTRING, $custom_table->table_name, 'attachment.txt');
        $custom_value = $custom_table->getValueModel();
        $custom_value->setValue('file', $file->path)
            ->setValue('text', 'claude file coverage ' . short_uuid());
        $custom_value->save();
        $file->saveCustomValue($custom_value->id, $custom_column, $custom_table);

        return [$custom_table, $custom_value, $file];
    }

    protected function publicForm(): PublicForm
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $custom_form = CustomForm::getDefault($custom_table);
        $public_form = PublicForm::where('custom_form_id', $custom_form->id)->first();
        if (!$public_form instanceof PublicForm) {
            $this->fail('the test data must have a public form for ' . TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        }

        return $public_form;
    }

    protected function png(): string
    {
        return (string) base64_decode(TestDefine::FILE_USER_BASE64);
    }

    /**
     * The location an upload answers, which must be under $prefix; the file is removed afterwards.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function uploadedLocation(TestResponse $response, string $prefix): string
    {
        $json = json_decode((string) $response->getContent(), true);
        $location = is_array($json) ? ($json['location'] ?? null) : null;
        if (!is_string($location)) {
            $this->fail('the upload must answer the location of the stored file: ' . $response->getContent());
        }
        $this->assertTrue(str_starts_with($location, $prefix), "the location {$location} must be under {$prefix}");
        $this->storedFiles[] = [Define::DISKNAME_TEMP_UPLOAD, basename($location)];

        return $location;
    }

    /**
     * The bytes a download sends: a local disk answers the file itself, other disks stream it.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function downloadedContent(TestResponse $response): string
    {
        $base = $response->baseResponse;
        if ($base instanceof BinaryFileResponse) {
            return (string) file_get_contents($base->getFile()->getPathname());
        }

        return (string) $response->streamedContent();
    }
}
