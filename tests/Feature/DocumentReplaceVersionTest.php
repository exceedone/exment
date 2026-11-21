<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\DocumentVersion;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DocumentVersionService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Replacing an attachment of a record: the file it had is kept as an older
 * version, the list still has one row, the oldest versions beyond the limit
 * are deleted, and deleting the attachment deletes its versions.
 */
class DocumentReplaceVersionTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

    /** @var string[] */
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

    public function testReplaceKeepsTheOlderFileAsAVersion(): void
    {
        [$custom_value, $document] = $this->attach('docver-v1.txt', 'body one');
        $first_uuid = strval($document->getValue('file_uuid', true));

        $this->replace($custom_value, $document, 'docver-v2.txt', 'body two')
            ->assertOk()->assertJson(['result' => true]);

        $document = $this->reloadDocument($document);
        $this->assertSame('docver-v2.txt', $document->getValue('document_name'));
        $new_uuid = strval($document->getValue('file_uuid', true));
        $this->assertNotSame($first_uuid, $new_uuid);
        $this->rememberFile($new_uuid);
        $this->assertSame('body two', Storage::disk(config('admin.upload.disk'))->get(ExmentFile::getData($new_uuid)->path));

        // still one attachment in the list
        $this->assertSame(1, $this->documentCount($custom_value));

        // the first file is version 1, kept with its record as parent (downloads are checked as before)
        $versions = DocumentVersionService::versions($document->id);
        $this->assertCount(1, $versions);
        $this->assertSame(1, $versions[0]->version_no);
        $this->assertSame($first_uuid, $versions[0]->file_uuid);
        $this->assertSame('docver-v1.txt', $versions[0]->document_name);
        $old = ExmentFile::getData($first_uuid);
        $this->assertNotNull($old);
        $this->assertSame(strval($custom_value->id), strval($old->parent_id));
        $this->assertSame('body one', Storage::disk(config('admin.upload.disk'))->get($old->path));

        // the list shows "version 2"; the versions open from it
        $this->assertSame([$document->id => 2], DocumentVersionService::currentNumbers([$document->id]));
        System::clearRequestSession();
        $this->get($this->url($custom_value->id, 'documents', $document->id, 'versions'))
            ->assertOk()
            ->assertJson(['result' => true])
            ->assertSee('docver-v1.txt', false)
            ->assertSee(admin_urls('files', $first_uuid), false);
    }

    public function testOnlyTheLimitOfOlderVersionsIsKept(): void
    {
        config(['exment.document_version_limit' => 2]);
        [$custom_value, $document] = $this->attach('docver-limit-1.txt', '1');
        $uuids = [strval($document->getValue('file_uuid', true))];
        foreach ([2, 3, 4] as $n) {
            $this->replace($custom_value, $document, "docver-limit-{$n}.txt", strval($n))->assertOk();
            $document = $this->reloadDocument($document);
            $uuids[] = strval($document->getValue('file_uuid', true));
        }
        $this->rememberFile(end($uuids));

        $versions = DocumentVersionService::versions($document->id);
        $this->assertSame([3, 2], $versions->pluck('version_no')->all());
        // the oldest file is gone, the file and its row
        $this->assertNull(ExmentFile::getData($uuids[0]));
        $this->assertNotNull(ExmentFile::getData($uuids[1]));
        $this->assertSame([$document->id => 4], DocumentVersionService::currentNumbers([$document->id]));
    }

    public function testDeletingTheAttachmentDeletesItsVersions(): void
    {
        [$custom_value, $document] = $this->attach('docver-del-1.txt', 'a');
        $first_uuid = strval($document->getValue('file_uuid', true));
        $this->replace($custom_value, $document, 'docver-del-2.txt', 'b')->assertOk();
        $document = $this->reloadDocument($document);
        $current_uuid = strval($document->getValue('file_uuid', true));

        // the trash icon of the list
        System::clearRequestSession();
        $this->delete(admin_urls('files', $current_uuid))->assertOk();

        $this->assertSame(0, DocumentVersion::where('document_id', $document->id)->count());
        $this->assertNull(ExmentFile::getData($first_uuid));
        $this->assertSame(0, $this->documentCount($custom_value));
    }

    public function testAUserWhoMayOnlyViewCannotReplace(): void
    {
        // all users may view this table's data, not edit it
        $table = TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL;
        [$custom_value, $document] = $this->attach('docver-deny.txt', 'kept', $table);
        $uuid = strval($document->getValue('file_uuid', true));
        $this->rememberFile($uuid);

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        $response = $this->replace($custom_value, $document, 'docver-deny-2.txt', 'changed', $table);
        $this->assertFalse($response->getStatusCode() === 200 && $response->json('result') === true, 'a viewer replaced the attachment');

        $this->assertSame($uuid, strval($this->reloadDocument($document)->getValue('file_uuid', true)));
        $this->assertSame(0, DocumentVersion::where('document_id', $document->id)->count());
    }

    public function testAnAttachmentOfAnotherRecordIsNotFound(): void
    {
        [$custom_value] = $this->attach('docver-a.txt', 'a');
        [, $other_document] = $this->attach('docver-b.txt', 'b');
        $this->rememberFile(strval($other_document->getValue('file_uuid', true)));

        System::clearRequestSession();
        $this->replace($custom_value, $other_document, 'docver-c.txt', 'c')->assertNotFound();
        System::clearRequestSession();
        $this->get($this->url($custom_value->id, 'documents', $other_document->id, 'versions'))->assertNotFound();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A new record with one attachment, sent as the screen sends it.
     *
     * @return array{0: CustomValue, 1: CustomValue}
     */
    protected function attach(string $name, string $body, string $table = self::TABLE): array
    {
        $custom_value = CustomTable::getEloquent($table)->getValueModel();
        $custom_value->setValue(['text' => 'docver ' . $name])->save();

        System::clearRequestSession();
        $this->post(admin_urls('data', $table, $custom_value->id, 'fileupload'), [
            'file_data' => [UploadedFile::fake()->createWithContent($name, $body)],
        ])->assertOk()->assertJson(['result' => true]);

        $document = CustomTable::getEloquent(SystemTableName::DOCUMENT)->getValueModel()->query()
            ->where('parent_type', $table)
            ->where('parent_id', $custom_value->id)
            ->first();
        $this->assertInstanceOf(CustomValue::class, $document);

        return [$custom_value, $document];
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function replace(CustomValue $custom_value, CustomValue $document, string $name, string $body, string $table = self::TABLE)
    {
        System::clearRequestSession();
        return $this->post(admin_urls('data', $table, $custom_value->id, 'documents', $document->id, 'replace'), [
            'file_data' => UploadedFile::fake()->createWithContent($name, $body),
        ]);
    }

    protected function reloadDocument(CustomValue $document): CustomValue
    {
        System::clearRequestSession();
        $fresh = CustomTable::getEloquent(SystemTableName::DOCUMENT)->getValueModel($document->id);
        $this->assertInstanceOf(CustomValue::class, $fresh);
        return $fresh;
    }

    protected function documentCount(CustomValue $custom_value): int
    {
        return CustomTable::getEloquent(SystemTableName::DOCUMENT)->getValueModel()->query()
            ->where('parent_type', static::TABLE)
            ->where('parent_id', $custom_value->id)
            ->count();
    }

    protected function rememberFile(string $uuid): void
    {
        $file = ExmentFile::getData($uuid);
        if ($file) {
            $this->storedPaths[] = $file->path;
        }
    }

    protected function url(string|int ...$paths): string
    {
        return admin_urls('data', static::TABLE, ...$paths);
    }
}
