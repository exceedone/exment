<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Services\DocumentVersionService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The page of a record with a replaced attachment: "Version n" and the box its
 * older versions open in (#1866, #1871).
 *
 * These tests do not run JavaScript, so they check what the script needs: the
 * link to the versions, and a box that is hidden and has no class that would
 * force it to show. A class like d-block (display: block !important) made the
 * empty box count as open, so a click closed it instead of loading the versions.
 */
class KDocumentVersionTest extends ExmentKitTestCase
{
    use DatabaseTransactions;

    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

    /** @var string[] */
    protected array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            Storage::disk(config('admin.upload.disk'))->delete($path);
        }
        parent::tearDown();
    }

    public function testAReplacedAttachmentOpensItsOlderVersions(): void
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $record = $table->getValueModel();
        $record->setValue(['text' => 'browser docver'])->save();

        $file = ExmentFile::storeAs(FileType::CUSTOM_VALUE_DOCUMENT, 'one', $table->table_name, 'browser-docver.txt')
            ->saveCustomValue($record->id, null, $table);
        $this->paths[] = $file->path;
        $document = $file->saveDocumentModel($record, 'browser-docver.txt');
        $tmp = tempnam(sys_get_temp_dir(), 'dv');
        file_put_contents($tmp, 'two');
        DocumentVersionService::replace($record, $document, new UploadedFile($tmp, 'browser-docver.txt', null, null, true));
        $document = getModelName(SystemTableName::DOCUMENT)::find($document->id);
        $this->paths[] = ExmentFile::getData(strval($document->getValue('file_uuid', true)))->path;

        $versions_url = admin_urls('data', static::TABLE, $record->id, 'documents', $document->id, 'versions');
        $this->visit(admin_urls('data', static::TABLE, $record->id))
            ->see(exmtrans('custom_value.document_version_no', ['no' => 2]))
            ->seeElement('[data-exment-document-versions][data-url="' . $versions_url . '"]')
            ->seeElement('[data-exment-document-replace]')
            ->seeElement('.exment-document-versions');

        // the box starts hidden, and nothing forces it to show
        $box = $this->crawler->filter('.exment-document-versions')->first();
        $classes = preg_split('/\s+/', strval($box->attr('class'))) ?: [];
        foreach (['d-block', 'd-inline', 'd-inline-block', 'd-flex', 'd-grid', 'show'] as $forcing) {
            $this->assertNotContains($forcing, $classes, "the versions box has the class {$forcing}, which keeps it shown");
        }
        $this->assertStringContainsString('display:none', str_replace(' ', '', strval($box->attr('style'))));

        // what the click loads into it
        $this->get($versions_url, ['X-Requested-With' => 'XMLHttpRequest'])
            ->seeStatusCode(200)
            ->seeJson(['result' => true]);
        foreach (DocumentVersionService::versions($document->id) as $version) {
            $older = ExmentFile::getData($version->file_uuid);
            if ($older) {
                $this->paths[] = $older->path;
            }
        }
        $html = strval(json_decode(strval($this->response->getContent()), true)['html'] ?? '');
        $this->assertStringContainsString(exmtrans('custom_value.document_version_no', ['no' => 1]), $html);
        $this->assertStringContainsString('browser-docver.txt', $html);
        @unlink($tmp);
    }
}
