<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\FileDataLink;
use Exceedone\Exment\Model\FileMetadataField;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileNodeText;
use Exceedone\Exment\Model\FilePermission;
use Exceedone\Exment\Model\FileWorkspace;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\FileServer\Access;
use Exceedone\Exment\Services\FileServer\DataLinks;
use Exceedone\Exment\Services\FileServer\FileSearch;
use Exceedone\Exment\Services\FileServer\FileServerException;
use Exceedone\Exment\Services\FileServer\Level;
use Exceedone\Exment\Services\FileServer\Library;
use Exceedone\Exment\Services\FileServer\Metadata;
use Exceedone\Exment\Services\FileServer\TextExtractor;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Storage;

/**
 * File server, phase 4 (ISS00378): the columns (metadata), the links to the
 * data and the search.
 */
class FileMetadataTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    /** @var string[] */
    protected array $locals = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Storage::fake(Define::DISKNAME_ADMIN);
        config(['exment.file_server_meili' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->locals as $local) {
            @unlink($local);
        }
        parent::tearDown();
    }

    protected function userId(string $loginId): int
    {
        return intval(LoginUser::findOrFail($loginId)->base_user_id);
    }

    protected function libraryFor(string $loginId, bool $admin = false): Library
    {
        return new Library(new Access($this->userId($loginId), $admin));
    }

    protected function admin(): Library
    {
        return $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_ADMIN, true);
    }

    protected function local(string $content): string
    {
        $path = Library::tempPath('test_');
        file_put_contents($path, $content);
        $this->locals[] = $path;
        return $path;
    }

    protected function addMember(FileWorkspace $ws, string $loginId, int $level): void
    {
        FilePermission::create([
            'workspace_id' => $ws->id, 'node_id' => null,
            'target_type' => FilePermission::TARGET_USER, 'target_id' => $this->userId($loginId), 'level' => $level,
        ]);
    }

    /**
     * A workspace with the columns: customer (text), kind (select), amount (number), signed (date), tags (multiselect).
     */
    protected function workspaceWithFields(): FileWorkspace
    {
        $lib = $this->admin();
        $ws = $lib->createWorkspace(['name' => 'Contracts']);
        (new Metadata($lib))->saveFields($ws, [
            ['name' => 'customer', 'label' => '顧客', 'field_type' => 'text', 'required' => 1],
            ['name' => 'kind', 'label' => '種類', 'field_type' => 'select', 'options' => "契約書\n見積書\n請求書", 'default_value' => '見積書'],
            ['name' => 'amount', 'label' => '金額', 'field_type' => 'number'],
            ['name' => 'signed', 'label' => '締結日', 'field_type' => 'date', 'show_in_list' => 0],
            ['name' => 'tags', 'label' => 'タグ', 'field_type' => 'multiselect', 'options' => "重要\n社外秘"],
        ]);
        return $ws;
    }

    /**
     * Take the text of a file now (the job waits for the commit, and the tests never commit).
     */
    protected function withText(FileNode $node): FileNode
    {
        $node->refresh();
        FileSearch::makeText($node->current_version);
        return $node;
    }

    protected function docx(string $text): string
    {
        $path = Library::tempPath('test_docx_') . '.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="w"><w:body><w:p><w:r><w:t>' . htmlspecialchars($text) . '</w:t></w:r></w:p><w:p><w:r><w:t>二行目</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $this->locals[] = $path;
        return $path;
    }

    // ================================================================ the columns

    public function testTheColumnsAndTheirValues(): void
    {
        $ws = $this->workspaceWithFields();
        $lib = $this->admin();
        $this->assertSame(['customer', 'kind', 'amount', 'signed', 'tags'], Metadata::fields($ws)->pluck('name')->all());

        // a new file takes the defaults
        $file = $lib->storeFile($ws, null, 'a.txt', $this->local('a'));
        $this->assertSame('見積書', $file->metadata['kind'] ?? null);

        $meta = new Metadata($lib);
        $meta->setValues($ws, $file, ['customer' => 'A 社', 'kind' => '契約書', 'amount' => '1,200', 'signed' => '2026/11/2', 'tags' => ['重要']]);
        $file->refresh();
        $this->assertEquals(['customer' => 'A 社', 'kind' => '契約書', 'amount' => 1200, 'signed' => '2026-11-02', 'tags' => ['重要']], $file->metadata);
        $this->assertSame(1200, $file->metadata['amount']);

        // the values are kept as written (the search of the database finds them)
        $raw = \DB::table('file_nodes')->where('id', $file->id)->value('metadata');
        $this->assertStringContainsString('A 社', strval($raw));

        // not a choice, not a number, a required column emptied
        foreach ([['kind' => '注文書'], ['amount' => 'abc'], ['tags' => ['なし']], ['customer' => '']] as $bad) {
            try {
                $meta->setValues($ws, $file, $bad);
                $this->fail('saved: ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
            } catch (FileServerException $e) {
                $this->assertSame(422, $e->status());
            }
        }

        // a select needs its choices; a key is checked
        try {
            $meta->saveFields($ws, [['name' => 'x', 'label' => 'X', 'field_type' => 'select', 'options' => '']]);
            $this->fail('a select without choices');
        } catch (FileServerException $e) {
            $this->assertStringContainsString('X', $e->getMessage());
        }
        try {
            $meta->saveFields($ws, [['name' => 'Bad Name', 'label' => 'B']]);
            $this->fail('a bad key');
        } catch (FileServerException $e) {
            $this->assertStringContainsString('bad name', $e->getMessage());
        }

        // a column taken away: its values stay in the file
        $meta->saveFields($ws, [['name' => 'customer', 'label' => '顧客', 'field_type' => 'text']]);
        $this->assertSame(['customer'], Metadata::fields($ws)->pluck('name')->all());
        $file->refresh();
        $this->assertSame('契約書', $file->metadata['kind']);
    }

    public function testWhoMayPutTheColumnsAndTheValues(): void
    {
        $ws = $this->workspaceWithFields();
        $file = $this->admin()->storeFile($ws, null, 'b.txt', $this->local('b'));
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::VIEW);
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER2, Level::EDIT);

        $viewer = new Metadata($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1));
        try {
            $viewer->setValues($ws, $file, ['customer' => 'B 社']);
            $this->fail('a viewer put a value');
        } catch (FileServerException $e) {
            $this->assertSame(403, $e->status());
        }

        $editor = new Metadata($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $editor->setValues($ws, $file, ['customer' => 'B 社']);
        $this->assertSame('B 社', $file->refresh()->metadata['customer']);
        try {
            $editor->saveFields($ws, [['name' => 'x', 'label' => 'X']]);
            $this->fail('an editor changed the columns');
        } catch (FileServerException $e) {
            $this->assertSame(403, $e->status());
        }
    }

    public function testNarrowSortAndGroupAList(): void
    {
        $ws = $this->workspaceWithFields();
        $lib = $this->admin();
        $meta = new Metadata($lib);
        $folder = $lib->createFolder($ws, null, 'Sub');
        $a = $lib->storeFile($ws, null, 'a.txt', $this->local('a'));
        $b = $lib->storeFile($ws, null, 'b.txt', $this->local('b'));
        $c = $lib->storeFile($ws, null, 'c.txt', $this->local('c'));
        $meta->setValues($ws, $a, ['customer' => 'A 社', 'kind' => '契約書', 'amount' => 300, 'tags' => ['重要']]);
        $meta->setValues($ws, $b, ['customer' => 'B 社', 'kind' => '請求書', 'amount' => 100]);
        $meta->setValues($ws, $c, ['customer' => 'A 社 東京', 'kind' => null, 'amount' => 200, 'tags' => ['重要', '社外秘']]);

        $fields = Metadata::fields($ws);
        $children = $lib->children($ws, null);
        $names = function (iterable $nodes): array {
            $list = [];
            foreach ($nodes as $node) {
                $list[] = $node->name;
            }
            return $list;
        };

        // the folders stay; the words are contained
        $this->assertSame(['Sub', 'a.txt', 'c.txt'], $names(Metadata::filter($children, $fields, ['customer' => 'a 社'])));
        $this->assertSame(['Sub', 'a.txt'], $names(Metadata::filter($children, $fields, ['customer' => 'A 社', 'kind' => '契約書'])));
        $this->assertSame(['Sub', 'c.txt'], $names(Metadata::filter($children, $fields, ['kind' => '__none__'])));
        $this->assertSame(['Sub', 'a.txt', 'c.txt'], $names(Metadata::filter($children, $fields, ['tags' => '重要'])));

        $amount = $fields->firstWhere('name', 'amount');
        $this->assertSame(['Sub', 'b.txt', 'c.txt', 'a.txt'], $names(Metadata::sort($children, $amount, 'asc')));
        $this->assertSame(['Sub', 'a.txt', 'c.txt', 'b.txt'], $names(Metadata::sort($children, $amount, 'desc')));

        $groups = Metadata::group($children, $fields->firstWhere('name', 'kind'));
        $this->assertSame([exmtrans('file_server.folders'), '契約書', '請求書', exmtrans('file_server.no_value')], array_column($groups, 'label'));
        $last = $groups[count($groups) - 1];
        $this->assertSame(['c.txt'], $names($last['nodes']));

        // the page
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->get(admin_urls('file_server', $ws->id) . '?' . http_build_query(['meta' => ['kind' => '請求書'], 'group' => 'kind', 'sort' => 'meta:amount']), $headers)
            ->assertOk()->assertSee('b.txt')->assertDontSee('a.txt')->assertSee('B 社');
    }

    // ================================================================ the links to the data

    public function testLinksToTheData(): void
    {
        $ws = $this->workspaceWithFields();
        $lib = $this->admin();
        $file = $lib->storeFile($ws, null, 'contract.txt', $this->local('c'));
        $secret = $lib->storeFile($ws, null, 'secret.txt', $this->local('s'));
        $lib->breakInheritance($ws, $secret);
        $lib->setPermissions($ws, $secret, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'level' => Level::MANAGE]]);
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::EDIT);

        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $value = $table->getValueModel()->query()->orderBy('id')->first();
        $this->assertNotNull($value);

        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $links = new DataLinks($lib);
        $links->add($ws, $file, $table->table_name, intval($value->id));
        $links->add($ws, $secret, $table->table_name, intval($value->id));
        // twice is once
        $links->add($ws, $file, $table->table_name, intval($value->id));
        $this->assertSame(2, FileDataLink::query()->where('data_id', $value->id)->where('table_name', $table->table_name)->count());
        $this->assertSame(1, count(DataLinks::dataOf($file)));
        $this->assertNotEmpty(DataLinks::lookup($table->table_name, strval($value->id)));

        // a data that is not there
        try {
            $links->add($ws, $file, $table->table_name, 999999);
            $this->fail('linked to nothing');
        } catch (FileServerException $e) {
            $this->assertSame(404, $e->status());
        }

        // the user sees only the files they may see
        $user = new DataLinks($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->assertSame(['contract.txt'], $user->nodesOf($table->table_name, intval($value->id))->pluck('name')->all());

        // the screen of the data shows the files
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->get(admin_urls('data', $table->table_name, $value->id), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('contract.txt')->assertDontSee('secret.txt');

        // removed with the file for good
        $lib->trash($ws, $file);
        $this->assertSame([], $user->nodesOf($table->table_name, intval($value->id))->pluck('name')->all());
        $lib->purge($ws, $file->refresh());
        $this->assertSame(0, FileDataLink::query()->where('node_id', $file->id)->count());
    }

    // ================================================================ the text and the search

    public function testTheTextOfTheFiles(): void
    {
        $this->assertSame(TextExtractor::METHOD_OFFICE, TextExtractor::method('a.DOCX'));
        $this->assertNull(TextExtractor::method('a.exe'));

        [$text, $method] = TextExtractor::extract($this->docx('契約金額は百万円'), 'x.docx');
        $this->assertSame(TextExtractor::METHOD_OFFICE, $method);
        $this->assertStringContainsString('契約金額は百万円', $text);
        $this->assertStringContainsString('二行目', $text);

        // Shift_JIS text
        [$text] = TextExtractor::extract($this->local(mb_convert_encoding('見積もりの内容', 'SJIS-win', 'UTF-8')), 'a.txt');
        $this->assertSame('見積もりの内容', $text);

        // html: no tags, no script
        [$text] = TextExtractor::extract($this->local('<html><script>var x=1;</script><p>本文&amp;です</p></html>'), 'a.html');
        $this->assertSame('本文&です', $text);

        // the text of a stored file (the sync queue of the tests)
        $lib = $this->admin();
        $ws = $lib->createWorkspace(['name' => 'Texts']);
        $file = $this->withText($lib->storeFile($ws, null, 'memo.docx', $this->docx('打ち合わせメモ')));
        $row = FileNodeText::query()->where('node_id', $file->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(FileNodeText::STATUS_DONE, $row->status);
        $this->assertStringContainsString('打ち合わせメモ', strval($row->content));

        $bin = $lib->storeFile($ws, null, 'tool.exe', $this->local('MZ'));
        $this->assertSame(FileNodeText::STATUS_NONE, FileNodeText::query()->where('node_id', $bin->id)->value('status'));
    }

    public function testTheSearch(): void
    {
        $ws = $this->workspaceWithFields();
        $lib = $this->admin();
        $folder = $lib->createFolder($ws, null, '営業');
        $contract = $this->withText($lib->storeFile($ws, $folder, '契約書_2026.docx', $this->docx('秘密保持の条項があります')));
        (new Metadata($lib))->setValues($ws, $contract, ['customer' => 'エクシード商事']);
        $memo = $this->withText($lib->storeFile($ws, null, 'memo.txt', $this->local('来週の予定: 秘密保持契約の確認')));
        $hidden = $this->withText($lib->storeFile($ws, null, 'hidden.txt', $this->local('秘密保持 だが見えない')));
        $lib->breakInheritance($ws, $hidden);
        $lib->setPermissions($ws, $hidden, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'level' => Level::MANAGE]]);
        $other = $lib->createWorkspace(['name' => 'Other']);
        $this->withText($lib->storeFile($other, null, 'other.txt', $this->local('秘密保持 ほかの場所')));
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::VIEW);

        $search = new FileSearch($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $names = function (array $result) {
            return array_map(function ($row) {
                return $row['node']->name;
            }, $result['rows']);
        };

        // the text in the files (only those the user may see)
        $result = $search->search('秘密保持');
        $this->assertSame('database', $result['engine']);
        $this->assertEqualsCanonicalizing(['契約書_2026.docx', 'memo.txt'], $names($result));
        $row = collect($result['rows'])->first(function ($r) {
            return $r['node']->name === 'memo.txt';
        });
        $this->assertTrue($row['in_content']);
        $this->assertStringContainsString('秘密保持', $row['snippet']);

        // every word; a name; a value of a column; one workspace
        $this->assertSame(['memo.txt'], $names($search->search('秘密保持 来週')));
        $this->assertSame(['契約書_2026.docx'], $names($search->search('2026')));
        $this->assertSame(['契約書_2026.docx'], $names($search->search('エクシード')));
        $this->assertSame([], $names($search->search('秘密保持', $other->id)));
        $this->assertSame([], $names($search->search('%')));

        // the trash is not searched
        $lib->trash($ws, $memo);
        $this->assertSame(['契約書_2026.docx'], $names($search->search('秘密保持')));

        // the page
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->get(admin_urls('file_server', 'search') . '?q=' . urlencode('秘密保持'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('契約書_2026.docx')->assertDontSee('hidden.txt')->assertDontSee('other.txt');
    }

    public function testTheSettingsAndTheFilePages(): void
    {
        $ws = $this->workspaceWithFields();
        $lib = $this->admin();
        $file = $lib->storeFile($ws, null, 'page.txt', $this->local('p'));
        (new Metadata($lib))->setValues($ws, $file, ['customer' => 'ページ社']);

        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->get(admin_urls('file_server', $ws->id, 'settings'), $headers)->assertOk()->assertSee('締結日');
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id), $headers)->assertOk()->assertSee('ページ社')->assertSee(exmtrans('file_server.data_links'));

        $this->post(admin_urls('file_server', $ws->id, 'metadata_fields'), ['fields' => json_encode([['name' => 'customer', 'label' => '取引先', 'field_type' => 'text']])], $headers)
            ->assertOk()->assertJson(['result' => true]);
        $this->assertSame('取引先', FileMetadataField::query()->where('workspace_id', $ws->id)->value('label'));

        $this->post(admin_urls('file_server', $ws->id, 'nodes', $file->id, 'metadata'), ['meta' => ['customer' => '新しい社']], $headers)
            ->assertOk()->assertJson(['result' => true]);
        $this->assertSame('新しい社', FileNode::find($file->id)->metadata['customer']);
    }
}
