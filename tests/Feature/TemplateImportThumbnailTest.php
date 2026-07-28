<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Feature Test: Template Import with Thumbnail — Bug Detection
 *
 * ■ Bug Overview
 *   When a template ZIP that contains a thumbnail image is imported and then
 *   the template screen is viewed, an exception occurs.
 *
 * ■ Root Causes
 *
 *   Bug A (PRIMARY) — isDriverLocal() → getAdapter() crash
 *     DiskServiceItem::isDriverLocal() calls $this->disk()->getAdapter(), which
 *     does NOT exist on Flysystem v3 (Laravel 9+). The call throws a
 *     BadMethodCallException (\Error subtype). getUserTemplates() only catches
 *     \Exception, so the error propagates → 500 on the template screen.
 *     This code path is only reached when a template HAS a thumbnail.
 *
 *   Bug B — dirFullPath(null) produces wrong path
 *     TemplateDiskService instantiated with no argument → diskItem.dirName = null
 *     → dirFullPath() returns disk->path(null) → file_get_contents on wrong path
 *     → thumbnail_file is empty string or false.
 *
 *   Bug C — MIME type hard-coded as image/png
 *     TemplateController::searchTemplate() always uses 'data:image/png;base64,'
 *     regardless of the actual thumbnail format. JPEG/GIF thumbnails are
 *     displayed incorrectly.
 *
 * ■ Test Strategy
 *   - Group 1: Upload ZIP via POST to admin/template/import (controller level)
 *   - Group 2: searchTemplate AJAX endpoint — primary user-visible bug surface
 *   - Group 3: Thumbnail content and MIME type validation in the AJAX response
 *   - Group 4: Disk state after upload (thumbnail file persisted correctly)
 *   - Group 5: Direct TemplateImporter::getUserTemplates() (unit-style, exposes Bug A cleanest)
 *   - Group 6: Template deletion cleans up thumbnail from disk
 *
 * ■ Test data
 *   Template ZIPs are built in-memory. No file fixtures required.
 */
class TemplateImportThumbnailTest extends FeatureTestBase
{
    use DatabaseTransactions;

    // ─── Constants ────────────────────────────────────────────────────────────

    private const TEMPLATE_NAME      = 'test_thumbnail_template';
    private const TEMPLATE_VIEW_NAME = 'Test Thumbnail Template';

    /**
     * Minimal 1×1 transparent PNG (67 bytes).
     * Used as test thumbnail content for PNG tests.
     */
    private const MINIMAL_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

    /**
     * Minimal JPEG (JFIF header, 107 bytes).
     * Used as test thumbnail content for JPEG tests.
     */
    private const MINIMAL_JPEG_B64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFgABAQEAAAAAAAAAAAAAAAAABgUEA//EAB8QAAICAQUBAAAAAAAAAAAAAAECAwQREiExQf/EABQBAQAAAAAAAAAAAAAAAAAAAAD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCw6dWvNYQSXJJYIlYkRREOFU+gBn6AAAAASUVORK5CYII=';

    // ─── Setup ────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        // Remove any templates uploaded to disk during tests so they don't
        // bleed into subsequent test runs (disk changes are NOT rolled back
        // by DatabaseTransactions).
        $this->cleanupTestTemplatesFromDisk();
        parent::tearDown();
    }

    // =========================================================================
    // Group 1: Upload ZIP — controller POST to admin/template/import
    // =========================================================================

    /**
     * Uploading a template ZIP without a thumbnail must not throw an exception.
     * (Baseline — passes before and after fix.)
     */
    public function testUploadZipWithoutThumbnail_succeeds(): void
    {
        $zip = $this->buildTemplateZip(self::TEMPLATE_NAME, false);

        $response = $this->post(admin_urls('template', 'import'), [
            'upload_template' => $zip,
        ]);

        // Must redirect back (toastr save_succeeded), not a 500.
        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
    }

    /**
     * Uploading a template ZIP WITH a PNG thumbnail must not throw an exception.
     *
     * [Current status: FAIL due to Bug A]
     *   isDriverLocal() calls getAdapter() which does not exist in Flysystem v3.
     *   This throws a \BadMethodCallException (\Error) inside getUserTemplates(),
     *   which is not caught by catch(\Exception), so it propagates and causes a 500.
     *
     * [After fix: PASS]
     */
    public function testUploadZipWithPngThumbnail_noException(): void
    {
        $zip = $this->buildTemplateZip(self::TEMPLATE_NAME, true, 'png');

        $response = $this->post(admin_urls('template', 'import'), [
            'upload_template' => $zip,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
    }

    /**
     * Uploading a template ZIP WITH a JPEG thumbnail must not throw an exception.
     *
     * [Current status: FAIL due to Bug A]
     * [After fix: PASS]
     */
    public function testUploadZipWithJpegThumbnail_noException(): void
    {
        $zip = $this->buildTemplateZip(self::TEMPLATE_NAME, true, 'jpg');

        $response = $this->post(admin_urls('template', 'import'), [
            'upload_template' => $zip,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
    }

    // =========================================================================
    // Group 2: searchTemplate AJAX endpoint — main user-visible bug surface
    // =========================================================================

    /**
     * searchTemplate returns HTTP 200 when no user templates exist.
     * (Baseline — passes before and after fix.)
     */
    public function testSearchTemplate_withNoTemplates_returns200(): void
    {
        $response = $this->post(admin_urls('api', 'template', 'search'), [
            'name'   => 'template',
            'column' => 'template',
        ]);

        $response->assertStatus(200);
    }

    /**
     * After uploading a template WITHOUT a thumbnail, searchTemplate returns 200.
     * (Baseline — passes before and after fix.)
     */
    public function testSearchTemplate_afterUploadWithoutThumbnail_returns200(): void
    {
        $this->uploadTemplateZip(self::TEMPLATE_NAME, false);

        $response = $this->post(admin_urls('api', 'template', 'search'), [
            'name'   => 'template',
            'column' => 'template',
        ]);

        $response->assertStatus(200);
    }

    /**
     * After uploading a template WITH a PNG thumbnail, searchTemplate must return 200.
     *
     * [Current status: FAIL due to Bug A]
     *   getAdapter() does not exist on Flysystem v3 → \BadMethodCallException is
     *   thrown inside getUserTemplates() → AJAX endpoint returns 500.
     *
     * [After fix: PASS]
     */
    public function testSearchTemplate_afterUploadWithPngThumbnail_returns200(): void
    {
        $this->uploadTemplateZip(self::TEMPLATE_NAME, true, 'png');

        $response = $this->post(admin_urls('api', 'template', 'search'), [
            'name'   => 'template',
            'column' => 'template',
        ]);

        $response->assertStatus(200);
    }

    /**
     * After uploading a template WITH a JPEG thumbnail, searchTemplate must return 200.
     *
     * [Current status: FAIL due to Bug A]
     * [After fix: PASS]
     */
    public function testSearchTemplate_afterUploadWithJpegThumbnail_returns200(): void
    {
        $this->uploadTemplateZip(self::TEMPLATE_NAME, true, 'jpg');

        $response = $this->post(admin_urls('api', 'template', 'search'), [
            'name'   => 'template',
            'column' => 'template',
        ]);

        $response->assertStatus(200);
    }

    // =========================================================================
    // Group 3: Thumbnail content and MIME type in the AJAX response
    // =========================================================================

    /**
     * After uploading a template with a PNG thumbnail, the searchTemplate response
     * must contain a data URI with the uploaded thumbnail image.
     *
     * [Current status: FAIL due to Bug B]
     *   dirFullPath(null) produces a wrong path → file_get_contents fails
     *   → thumbnail_file is empty/false → data URI has no meaningful content.
     *
     * [After fix: PASS]
     */
    public function testSearchTemplate_pngThumbnailDataUriAppearsInResponse(): void
    {
        $this->uploadTemplateZip(self::TEMPLATE_NAME, true, 'png');

        $response = $this->post(admin_urls('api', 'template', 'search'), [
            'name'   => 'template',
            'column' => 'template',
        ]);

        $response->assertStatus(200);

        $content = (string)$response->getContent();
        // The response HTML must contain a non-empty base64 data URI for the thumbnail.
        $this->assertStringContainsString(
            'data:image/png;base64,',
            $content,
            'BUG B: The thumbnail data URI is missing from the searchTemplate response. '
                . 'Fix: ensure dirFullPath() resolves to the correct path so file_get_contents() succeeds.'
        );
        // Verify there is actual base64 content after the prefix (not just the prefix alone).
        $this->assertMatchRegex(
            '/data:image\/png;base64,[A-Za-z0-9+\/]{20,}/',
            $content,
            'BUG B: The thumbnail data URI is present but has no base64 content (file read failed).'
        );
    }

    // =========================================================================
    // Group 4: Disk state after upload
    // =========================================================================

    /**
     * After uploading a template ZIP with a thumbnail, the thumbnail file must
     * be stored on the template disk.
     *
     * [Current status: FAIL due to Bug B]
     *   uploadTemplate() builds an incorrect copy path because dirFullPath(null)
     *   resolves to the wrong directory → thumbnail is not copied to disk.
     *
     * [After fix: PASS]
     */
    public function testUploadZipWithThumbnail_thumbnailExistsOnDisk(): void
    {
        $this->uploadTemplateZip(self::TEMPLATE_NAME, true, 'png');

        $disk = Storage::disk(\Exceedone\Exment\Model\Define::DISKNAME_TEMPLATE_SYNC);

        $found = false;
        foreach ($disk->files(self::TEMPLATE_NAME) as $file) {
            if (str_starts_with(basename($file), 'thumbnail')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            'BUG B: After uploading a template ZIP with a thumbnail, the thumbnail file was not '
                . 'found on the template disk. Fix: correct the path handling in uploadTemplate().'
        );
    }

    /**
     * After uploading without a thumbnail, no thumbnail file must appear on disk.
     * (Baseline — passes before and after fix.)
     */
    public function testUploadZipWithoutThumbnail_noThumbnailOnDisk(): void
    {
        $this->uploadTemplateZip(self::TEMPLATE_NAME, false);

        $disk = Storage::disk(\Exceedone\Exment\Model\Define::DISKNAME_TEMPLATE_SYNC);

        $found = false;
        foreach ($disk->files(self::TEMPLATE_NAME) as $file) {
            if (str_starts_with(basename($file), 'thumbnail')) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, 'No thumbnail file should be present when the ZIP had no thumbnail.');
    }

    // =========================================================================
    // Group 5: Direct TemplateImporter::getUserTemplates() — exposes Bug A cleanest
    // =========================================================================

    /**
     * getUserTemplates() must not throw any exception when a user template with a
     * thumbnail exists on the template disk.
     *
     * [Current status: FAIL due to Bug A]
     *   DiskServiceItem::isDriverLocal() calls $disk->getAdapter(), which throws
     *   \BadMethodCallException (\Error) in Flysystem v3. Because getUserTemplates()
     *   only catches \Exception, this error is uncaught and crashes the caller.
     *
     * [After fix: PASS]
     *   Fix: replace getAdapter() instanceof with a driver-name check via
     *   config('filesystems.disks.*.driver') or wrap with try/catch(\Throwable).
     */
    public function testGetUserTemplates_noExceptionWhenThumbnailExists(): void
    {
        // First upload a template with a thumbnail so getUserTemplates() will
        // exercise the isDriverLocal() code path.
        $this->uploadTemplateZip(self::TEMPLATE_NAME, true, 'png');

        $importer = new TemplateImporter();

        $threw = false;
        $errorMessage = '';
        try {
            $templates = $importer->getTemplates();
        } catch (\Throwable $e) {
            $threw = true;
            $errorMessage = get_class($e) . ': ' . $e->getMessage();
        }

        $this->assertFalse(
            $threw,
            'BUG A: TemplateImporter::getUserTemplates() threw an exception when processing a '
                . "template with a thumbnail: {$errorMessage}. "
                . 'Fix: replace getAdapter() call in DiskServiceItem::isDriverLocal() — '
                . 'the method does not exist in Flysystem v3 (Laravel 9+).'
        );
    }

    /**
     * getUserTemplates() must return the template entry including a non-empty
     * thumbnail_file field when a thumbnail exists.
     *
     * [Current status: FAIL due to Bug A (throws before reaching this assertion)
     *  and Bug B (thumbnail_file is empty even if no throw)]
     * [After full fix: PASS]
     */
    public function testGetUserTemplates_returnsThumbnailFile_whenThumbnailExists(): void
    {
        $this->uploadTemplateZip(self::TEMPLATE_NAME, true, 'png');

        $importer = new TemplateImporter();

        try {
            $templates = $importer->getTemplates();
        } catch (\Throwable $e) {
            $this->fail(
                'BUG A: getUserTemplates() threw ' . get_class($e) . ' — cannot reach thumbnail assertion. '
                    . 'Fix isDriverLocal() first.'
            );
        }

        $entry = collect($templates)->first(fn ($t) => array_get($t, 'template_name') === self::TEMPLATE_NAME);

        $this->assertNotNull(
            $entry,
            'Template "' . self::TEMPLATE_NAME . '" was not returned by getUserTemplates().'
        );

        $this->assertNotEmpty(
            array_get($entry, 'thumbnail_file'),
            'BUG B: thumbnail_file is empty even though a thumbnail was uploaded. '
                . 'Fix: correct the path handling in dirFullPath() / getUserTemplates() so that '
                . 'file_get_contents() reads the correct file.'
        );
    }

    // =========================================================================
    // Group 6: Delete
    // =========================================================================

    /**
     * Deleting a template removes the entire template directory (including thumbnail)
     * from the disk.
     * (Baseline — passes before and after fix once Bug A/B are fixed.)
     */
    public function testDeleteTemplate_withThumbnail_removesFromDisk(): void
    {
        $this->uploadTemplateZip(self::TEMPLATE_NAME, true, 'png');

        $disk = Storage::disk(\Exceedone\Exment\Model\Define::DISKNAME_TEMPLATE_SYNC);

        // Confirm it exists before deletion.
        $this->assertTrue(
            $disk->exists(self::TEMPLATE_NAME),
            'Pre-condition: template directory must exist on disk before delete.'
        );

        $importer = new TemplateImporter();
        $importer->deleteTemplate(self::TEMPLATE_NAME);

        $this->assertFalse(
            $disk->exists(self::TEMPLATE_NAME),
            'After deleteTemplate(), the template directory should no longer exist on disk.'
        );
    }

    /**
     * Calling deleteTemplate() on a non-existent template name must not throw.
     * (Baseline — passes before and after fix.)
     */
    public function testDeleteTemplate_nonExistent_doesNotThrow(): void
    {
        $importer = new TemplateImporter();

        $threw = false;
        try {
            $importer->deleteTemplate('this_template_does_not_exist_' . time());
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertFalse($threw, 'deleteTemplate() on a non-existent template should not throw.');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build an in-memory template ZIP as an UploadedFile.
     *
     * @param string $templateName  Slug / template_name value stored in config.json.
     * @param bool   $withThumbnail Whether to include a thumbnail image in the ZIP.
     * @param string $extension     Image extension: 'png' or 'jpg'.
     * @return UploadedFile
     */
    private function buildTemplateZip(string $templateName, bool $withThumbnail, string $extension = 'png'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'exm_tmpl_') . '.zip';

        $config = [
            'template_name'      => $templateName,
            'template_view_name' => self::TEMPLATE_VIEW_NAME,
            'description'        => 'Auto-generated test template',
        ];

        $thumbnailName = null;
        $thumbnailBytes = null;

        if ($withThumbnail) {
            $thumbnailName  = "thumbnail.{$extension}";
            $thumbnailBytes = base64_decode(
                $extension === 'jpg' ? self::MINIMAL_JPEG_B64 : self::MINIMAL_PNG_B64
            );
            $config['thumbnail'] = $thumbnailName;
        }

        $zip = new ZipArchive();
        $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('config.json', (string)json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($withThumbnail && $thumbnailBytes !== false) {
            $zip->addFromString($thumbnailName, $thumbnailBytes);
        }

        $zip->close();

        $mimeType = $extension === 'jpg' ? 'image/jpeg' : 'image/png';

        return new UploadedFile(
            $tmpPath,
            "{$templateName}.zip",
            'application/zip',
            null,
            true   // test mode — skip SAPI upload checks
        );
    }

    /**
     * Helper: POST the ZIP to the import endpoint (exactly as a browser would).
     *
     * @param string $templateName
     * @param bool   $withThumbnail
     * @param string $extension
     * @return void
     */
    private function uploadTemplateZip(string $templateName, bool $withThumbnail, string $extension = 'png'): void
    {
        $zip = $this->buildTemplateZip($templateName, $withThumbnail, $extension);

        $this->post(admin_urls('template', 'import'), [
            'upload_template' => $zip,
        ]);
    }

    /**
     * Remove all test template directories from the template disk so that
     * tests do not bleed state between runs.
     */
    private function cleanupTestTemplatesFromDisk(): void
    {
        try {
            $disk = Storage::disk(\Exceedone\Exment\Model\Define::DISKNAME_TEMPLATE_SYNC);
            if ($disk->exists(self::TEMPLATE_NAME)) {
                $disk->deleteDirectory(self::TEMPLATE_NAME);
            }
        } catch (\Throwable $e) {
            // Best-effort cleanup; do not fail tearDown.
        }
    }
}
