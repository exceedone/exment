<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileNodeVersion;
use Exceedone\Exment\Model\FilePermission;
use Exceedone\Exment\Model\FileWorkspace;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\FileServer\Access;
use Exceedone\Exment\Services\FileServer\FileServerException;
use Exceedone\Exment\Services\FileServer\Level;
use Exceedone\Exment\Services\FileServer\Library;
use Exceedone\Exment\Services\FileServer\Preview;
use Exceedone\Exment\Services\FileServer\Uploader;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * File server, phases 1 and 2 (ISS00293): workspaces, folders, permissions,
 * the trash, the upload in parts, versions, check out and the preview.
 */
class FileServerTest extends FeatureTestBase
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

    protected function workspace(?Library $owner = null): FileWorkspace
    {
        return ($owner ?? $this->admin())->createWorkspace(['name' => 'Team A', 'trash_days' => 30, 'version_limit' => 3]);
    }

    protected function addMember(FileWorkspace $ws, string $loginId, int $level): void
    {
        FilePermission::create([
            'workspace_id' => $ws->id, 'node_id' => null,
            'target_type' => FilePermission::TARGET_USER, 'target_id' => $this->userId($loginId), 'level' => $level,
        ]);
    }

    // ================================================================ phase 1

    public function testTheUserWhoMakesAWorkspaceManagesItAndOthersDoNotSeeIt(): void
    {
        // a user who is not a system administrator makes it (the site lets all users)
        config(['exment.file_server_workspace_create' => 'all']);
        $owner = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $ws = $this->workspace($owner);

        $this->assertSame(Level::MANAGE, $owner->access()->level($ws));
        $this->assertTrue($owner->access()->workspaces()->contains('id', $ws->id));

        $other = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertSame(Level::NONE, $other->access()->level($ws));
        $this->assertFalse($other->access()->workspaces()->contains('id', $ws->id));
        $this->expectException(FileServerException::class);
        $other->children($ws, null);
    }

    public function testAViewerSeesButCannotUpload(): void
    {
        $ws = $this->workspace();
        $this->admin()->storeFile($ws, null, 'a.txt', $this->local('hello'));
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::VIEW);

        $viewer = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertSame(['a.txt'], $viewer->children($ws, null)->pluck('name')->all());

        try {
            $viewer->createFolder($ws, null, 'x');
            $this->fail('a viewer made a folder');
        } catch (FileServerException $e) {
            $this->assertSame(403, $e->status());
        }
    }

    public function testFoldersFilesAndNames(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $docs = $lib->createFolder($ws, null, 'Docs');
        $file = $lib->storeFile($ws, $docs, 'plan.txt', $this->local('v1'), null);

        $this->assertSame($docs->id, $file->parent_id);
        $this->assertSame(2, intval($file->size));
        $this->assertTrue(Library::disk()->exists($file->current_version->path));

        // the same name, other case: the same folder
        try {
            $lib->createFolder($ws, null, 'docs');
            $this->fail('two folders with the same name');
        } catch (FileServerException $e) {
            $this->assertStringContainsString('docs', $e->getMessage());
        }
        foreach (['a/b', 'a:b', '..', '', str_repeat('x', 256)] as $bad) {
            try {
                $lib->createFolder($ws, null, $bad);
                $this->fail('the name was taken: ' . $bad);
            } catch (FileServerException $e) {
                $this->assertSame(422, $e->status());
            }
        }

        // an upload of a folder: its folders are made
        $deep = $lib->storeFile($ws, null, 'c.txt', $this->local('c'), null, 'Up/Inner');
        $path = array_map(function (FileNode $n) {
            return $n->name;
        }, $deep->ancestorsAndSelf());
        $this->assertSame(['Up', 'Inner', 'c.txt'], $path);
    }

    public function testMoveCopyAndTheRulesOfAMove(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $a = $lib->createFolder($ws, null, 'A');
        $b = $lib->createFolder($ws, $a, 'B');
        $file = $lib->storeFile($ws, $b, 'f.txt', $this->local('data'));

        $lib->move($ws, $file, $a);
        $this->assertSame($a->id, $file->refresh()->parent_id);

        try {
            $lib->move($ws, $a, $b);
            $this->fail('a folder was moved into its own folder');
        } catch (FileServerException $e) {
            $this->assertSame(exmtrans('file_server.error_move_into_itself'), $e->getMessage());
        }

        $copy = $lib->copy($ws, $a, null);
        $this->assertSame('A (2)', $copy->name);
        $names = FileNode::query()->whereIn('id', $copy->descendantIds())->pluck('name')->sort()->values()->all();
        $this->assertSame(['B', 'f.txt'], $names);
        // the copy has its own bytes
        $copied = FileNode::query()->whereIn('id', $copy->descendantIds())->where('name', 'f.txt')->firstOrFail();
        $this->assertNotSame($file->current_version->path, $copied->current_version->path);
        $this->assertSame('data', Library::disk()->get($copied->current_version->path));
    }

    public function testAFolderWithItsOwnPermissions(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::EDIT);
        $secret = $lib->createFolder($ws, null, 'Secret');
        $inner = $lib->storeFile($ws, $secret, 's.txt', $this->local('s'));
        $lib->createFolder($ws, null, 'Open');

        $user = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertSame(['Open', 'Secret'], $user->children($ws, null)->pluck('name')->all());

        // stop inheriting, then leave only the admin
        $lib->breakInheritance($ws, $secret);
        $this->assertTrue($secret->refresh()->has_unique_permissions);
        $lib->setPermissions($ws, $secret, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'level' => Level::MANAGE]]);

        $user = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertSame(['Open'], $user->children($ws, null)->pluck('name')->all());
        // the file in it takes the permissions of the folder
        $this->assertSame(Level::NONE, $user->access()->level($ws, $inner));

        // inherit again
        $lib->restoreInheritance($ws, $secret);
        $user = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertSame(Level::EDIT, $user->access()->level($ws, $inner));
    }

    public function testAPermissionGivenToAnOrganization(): void
    {
        $loginId = TestDefine::TESTDATA_USER_LOGINID_DEV_USERB;
        $user = LoginUser::findOrFail($loginId)->base_user;
        $orgIds = array_map('intval', array_values((array)$user->getOrganizationIdsForQuery()));
        if (count($orgIds) === 0) {
            $this->markTestSkipped('the user is in no organization');
        }
        $ws = $this->workspace();
        FilePermission::create([
            'workspace_id' => $ws->id, 'node_id' => null,
            'target_type' => FilePermission::TARGET_ORGANIZATION, 'target_id' => $orgIds[0], 'level' => Level::UPLOAD,
        ]);
        $lib = $this->libraryFor($loginId);
        $this->assertSame(Level::UPLOAD, $lib->access()->level($ws));
        $folder = $lib->createFolder($ws, null, 'From the organization');
        $this->assertSame($this->userId($loginId), intval($folder->created_user_id ?? $this->userId($loginId)));
    }

    public function testAtLeastOneManagerStays(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $this->expectException(FileServerException::class);
        $lib->setPermissions($ws, null, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'level' => Level::EDIT]]);
    }

    public function testTrashRestoreAndPurge(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $folder = $lib->createFolder($ws, null, 'Old');
        $file = $lib->storeFile($ws, $folder, 'o.txt', $this->local('old'));
        $path = $file->current_version->path;

        $lib->trash($ws, $folder);
        $this->assertSame([], $lib->children($ws, null)->pluck('name')->all());
        $this->assertSame(['Old'], $lib->trashItems($ws)->pluck('name')->all());
        $this->assertTrue($file->refresh()->isTrashed());

        $lib->restore($ws, $folder->refresh());
        $this->assertFalse($file->refresh()->isTrashed());
        $this->assertSame(['Old'], $lib->children($ws, null)->pluck('name')->all());

        // a file whose folder is in the trash is restored to the top
        $lib->trash($ws, $file);
        $lib->trash($ws, $folder->refresh());
        $lib->restore($ws, $file->refresh());
        $this->assertNull($file->refresh()->parent_id);

        // removed for good: the bytes too
        $lib->trash($ws, $file);
        $lib->purge($ws, $file->refresh());
        $this->assertNull(FileNode::find($file->id));
        $this->assertFalse(Library::disk()->exists($path));
    }

    public function testTheTrashIsEmptiedAfterTheDaysOfTheWorkspace(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $file = $lib->storeFile($ws, null, 'x.txt', $this->local('x'));
        $lib->trash($ws, $file);
        FileNode::query()->where('id', $file->id)->update(['trashed_at' => now()->subDays(31)]);

        $this->assertSame(1, Library::purgeExpired());
        $this->assertNull(FileNode::find($file->id));
    }

    public function testTheSpaceOfTheWorkspace(): void
    {
        $lib = $this->admin();
        $ws = $lib->createWorkspace(['name' => 'Small', 'quota_bytes' => 10]);
        $lib->storeFile($ws, null, 'a.txt', $this->local('12345'));
        try {
            $lib->storeFile($ws, null, 'b.txt', $this->local('1234567'));
            $this->fail('the space was not checked');
        } catch (FileServerException $e) {
            $this->assertSame(413, $e->status());
        }
    }

    public function testTheUploadInParts(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $uploader = new Uploader($lib);
        $session = $uploader->init($ws, null, 'big.bin', 10, 'Parts');

        $this->assertSame(4, $uploader->append($session, 0, $this->local('abcd')));
        // the same part again: refused, and the browser goes on from 4
        try {
            $uploader->append($session->refresh(), 0, $this->local('abcd'));
            $this->fail('a part was added twice');
        } catch (FileServerException $e) {
            $this->assertSame(409, $e->status());
        }
        $this->assertSame(10, $uploader->append($session->refresh(), 4, $this->local('efghij')));

        $node = $uploader->complete($ws, $session->refresh());
        $this->assertSame('big.bin', $node->name);
        $this->assertSame('Parts', $node->parent->name);
        $this->assertSame('abcdefghij', Library::disk()->get($node->current_version->path));
        $this->assertNull(\Exceedone\Exment\Model\FileUploadSession::find($session->id));
    }

    public function testAnUploadOfAnotherUserCannotBeUsed(): void
    {
        $ws = $this->workspace();
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::UPLOAD);
        $session = (new Uploader($this->admin()))->init($ws, null, 'a.bin', 3);
        $this->expectException(FileServerException::class);
        (new Uploader($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)))->find($ws, $session->id);
    }

    // ================================================================ phase 2

    public function testVersionsRestoreAndTheLimit(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace(); // keeps 3 versions
        $file = $lib->storeFile($ws, null, 'doc.txt', $this->local('one'));
        $lib->storeFile($ws, null, 'doc.txt', $this->local('two'), 'second');
        $file->refresh();
        $this->assertSame(2, $file->current_version->version_no);
        $this->assertSame('second', $file->current_version->comment);

        $first = FileNodeVersion::query()->where('node_id', $file->id)->where('version_no', 1)->firstOrFail();
        $lib->restoreVersion($ws, $file, $first);
        $file->refresh();
        $this->assertSame(3, $file->current_version->version_no);
        $this->assertSame('one', Library::disk()->get($file->current_version->path));

        $firstPath = $first->path;
        $lib->addVersion($ws, $file, $this->local('four'));
        $this->assertSame([4, 3, 2], $file->versions()->pluck('version_no')->all());
        $this->assertFalse(Library::disk()->exists($firstPath));
    }

    public function testAnUploaderCannotOverwriteAFileWithTheSameName(): void
    {
        $ws = $this->workspace();
        $this->admin()->storeFile($ws, null, 'same.txt', $this->local('admin'));
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::UPLOAD);
        $this->expectException(FileServerException::class);
        $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->storeFile($ws, null, 'same.txt', $this->local('user'));
    }

    public function testCheckOutLocksTheFileForTheOthers(): void
    {
        $ws = $this->workspace();
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::EDIT);
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER2, Level::EDIT);
        $user1 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $file = $user1->storeFile($ws, null, 'lock.txt', $this->local('1'));

        $user1->checkout($ws, $file);
        foreach ([
            function () use ($user2, $ws, $file) {
                $user2->addVersion($ws, $file->refresh(), $this->local('2'));
            },
            function () use ($user2, $ws, $file) {
                $user2->rename($ws, $file->refresh(), 'other.txt');
            },
            function () use ($user2, $ws, $file) {
                $user2->trash($ws, $file->refresh());
            },
            function () use ($user2, $ws, $file) {
                $user2->discardCheckout($ws, $file->refresh());
            },
        ] as $try) {
            try {
                $try();
                $this->fail('the lock did not hold');
            } catch (FileServerException $e) {
                $this->assertContains($e->status(), [403, 423]);
            }
        }

        // check in with a new version
        $user1->checkin($ws, $file->refresh(), $this->local('edited'), 'done');
        $file->refresh();
        $this->assertNull($file->checked_out_user_id);
        $this->assertSame(2, $file->current_version->version_no);

        // the manager can unlock what another user locked
        $user1->checkout($ws, $file);
        $this->admin()->discardCheckout($ws, $file->refresh());
        $this->assertNull($file->refresh()->checked_out_user_id);
    }

    public function testTheKindsOfPreview(): void
    {
        $this->assertSame(Preview::KIND_OFFICE, Preview::kind('application/octet-stream', 'a.xlsx'));
        $this->assertSame(Preview::KIND_PDF, Preview::kind('application/pdf', 'a.pdf'));
        $this->assertSame(Preview::KIND_IMAGE, Preview::kind('image/png', 'a.png'));
        $this->assertSame(Preview::KIND_TEXT, Preview::kind('image/svg+xml', 'a.svg'));
        $this->assertSame(Preview::KIND_NONE, Preview::kind('application/zip', 'a.zip'));
        $this->assertTrue(Preview::isInlineSafe('application/pdf'));
        $this->assertFalse(Preview::isInlineSafe('text/html'));
        $this->assertFalse(Preview::isInlineSafe('image/svg+xml'));
    }

    public function testTheThumbnailOfAnImage(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('no GD');
        }
        $img = imagecreatetruecolor(800, 400);
        $png = Library::tempPath('png_');
        imagepng($img, $png);
        $this->locals[] = $png;

        $lib = $this->admin();
        $ws = $this->workspace();
        $file = $lib->storeFile($ws, null, 'photo.png', $png);
        $version = $file->current_version;
        Preview::makeRenditions($version);
        $version->refresh();
        $this->assertSame(FileNodeVersion::RENDITION_DONE, $version->rendition_status);
        $this->assertTrue(Library::disk()->exists(strval($version->thumbnail_path)));
    }

    public function testAnOpenerAddsALinkToTheFile(): void
    {
        Preview::registerOpener('test', function (FileNode $node) {
            return $node->extension() === 'docx' ? ['label' => 'Edit', 'url' => 'https://example.test/' . $node->id] : null;
        });
        $lib = $this->admin();
        $ws = $this->workspace();
        $doc = $lib->storeFile($ws, null, 'a.docx', $this->local('d'));
        $txt = $lib->storeFile($ws, null, 'a.txt', $this->local('t'));
        // other openers (an installed plugin) may be there too
        $this->assertContains('Edit', array_column(Preview::openers($doc), 'label'));
        $this->assertNotContains('Edit', array_column(Preview::openers($txt), 'label'));
    }

    // ================================================================ the pages

    public function testThePagesAndTheDownloads(): void
    {
        $ws = $this->workspace();
        $lib = $this->admin();
        $folder = $lib->createFolder($ws, null, 'Pages');
        $file = $lib->storeFile($ws, $folder, 'note.txt', $this->local('page body'));
        $html = $lib->storeFile($ws, $folder, 'x.html', $this->local('<script>alert(1)</script>'));
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::VIEW);

        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];

        $this->get(admin_url('file_server'), $headers)->assertOk()->assertSee('Team A');
        $this->get(admin_urls('file_server', $ws->id), $headers)->assertOk()->assertSee('Pages');
        $this->get(admin_urls('file_server', $ws->id, 'folder', $folder->id), $headers)->assertOk()->assertSee('note.txt');
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id), $headers)->assertOk()->assertSee('page body');

        $download = $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id, 'download'));
        $download->assertOk();
        $this->assertSame('page body', $download->streamedContent());
        $this->assertStringContainsString('attachment', strval($download->headers->get('Content-Disposition')));

        // html is never shown in the page
        $content = $this->get(admin_urls('file_server', $ws->id, 'nodes', $html->id, 'content'));
        $this->assertStringContainsString('attachment', strval($content->headers->get('Content-Disposition')));
        $this->assertSame('application/octet-stream', $content->headers->get('Content-Type'));

        // a viewer may not upload
        $upload = $this->post(admin_urls('file_server', $ws->id, 'upload'), [
            'parent_id' => $folder->id,
            'files' => [UploadedFile::fake()->createWithContent('new.txt', 'new')],
        ], $headers + ['Accept' => 'application/json']);
        $upload->assertStatus(403);
    }

    public function testUploadAndFolderFromThePage(): void
    {
        $ws = $this->workspace();
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::UPLOAD);
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

        $this->post(admin_urls('file_server', $ws->id, 'folders'), ['name' => 'Made'], $headers)
            ->assertOk()->assertJson(['result' => true]);
        $folder = FileNode::query()->where('workspace_id', $ws->id)->where('name', 'Made')->firstOrFail();

        $this->post(admin_urls('file_server', $ws->id, 'upload'), [
            'parent_id' => $folder->id,
            'files' => [UploadedFile::fake()->createWithContent('one.txt', '1'), UploadedFile::fake()->createWithContent('two.txt', '2')],
            'paths' => ['', 'Sub'],
        ], $headers)->assertOk()->assertJson(['result' => true]);

        $this->assertSame(['Sub', 'one.txt'], $this->admin()->children($ws, $folder)->pluck('name')->all());
    }

    public function testWhatAnEditorUsesInfoAndASaveFromAnOldVersion(): void
    {
        $ws = $this->workspace();
        $file = $this->admin()->storeFile($ws, null, 'report.docx', $this->local('v1'));
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::EDIT);
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $base = admin_urls('file_server', $ws->id, 'nodes', $file->id);

        $info = $this->get($base . '/info', $headers)->assertOk()->json();
        $this->assertSame('report.docx', $info['name']);
        $this->assertSame('docx', $info['extension']);
        $this->assertTrue($info['can_edit']);
        $this->assertSame($file->current_version_id, $info['version']);

        // a save from the version that is current: a new version
        $saved = $this->post($base . '/version', [
            'file' => UploadedFile::fake()->createWithContent('report.docx', 'v2'),
            'base_version' => $info['version'],
        ], $headers)->assertOk()->json();
        $this->assertSame(2, $saved['version_no']);

        // a save from the old version: refused, nothing lost
        $this->post($base . '/version', [
            'file' => UploadedFile::fake()->createWithContent('report.docx', 'v2 by another'),
            'base_version' => $info['version'],
        ], $headers)->assertStatus(409);
        $this->assertSame('v2', Library::disk()->get($file->refresh()->current_version->path));
    }

    public function testTheMenuAndThePageOfAUserWithNoWorkspace(): void
    {
        // the default: the system administrators make the workspaces
        config(['exment.file_server_workspace_create' => 'admin']);
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        // a user who cannot make one: whom to ask
        $this->get(admin_url('file_server'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee(exmtrans('file_server.no_workspaces'))->assertDontSee(exmtrans('file_server.no_workspaces_admin'));
    }
}
