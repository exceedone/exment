<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\FileAuditLog;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileNodeVersion;
use Exceedone\Exment\Model\FilePermission;
use Exceedone\Exment\Model\FileShareLink;
use Exceedone\Exment\Model\FileWorkspace;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Services\FileServer\Access;
use Exceedone\Exment\Services\FileServer\Audit;
use Exceedone\Exment\Services\FileServer\Collaboration;
use Exceedone\Exment\Services\FileServer\FileServerException;
use Exceedone\Exment\Services\FileServer\Governance;
use Exceedone\Exment\Services\FileServer\Level;
use Exceedone\Exment\Services\FileServer\Library;
use Exceedone\Exment\Services\FileServer\ShareLinks;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Storage;

/**
 * File server, phase 5 (ISS00379): the approvals, the legal hold, the
 * retention, who may download, the audit log, the follows and the comments.
 */
class FileGovernanceTest extends FeatureTestBase
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
        config([
            'exment.file_server_meili' => false,
            'exment.file_server_audit' => true,
            'exment.file_server_notify_mail' => false,
        ]);
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

    protected function libraryFor(string $loginId): Library
    {
        $this->be(LoginUser::findOrFail($loginId));
        return new Library(new Access($this->userId($loginId), false));
    }

    protected function local(string $content): string
    {
        $path = Library::tempPath('test_');
        file_put_contents($path, $content);
        $this->locals[] = $path;
        return $path;
    }

    /**
     * A workspace: dev-userB manages it, user1 may upload, user2 may see.
     */
    protected function workspace(): FileWorkspace
    {
        $manager = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $ws = $manager->createWorkspace(['name' => 'P5 ' . uniqid()]);
        foreach ([[TestDefine::TESTDATA_USER_LOGINID_USER1, Level::UPLOAD], [TestDefine::TESTDATA_USER_LOGINID_USER2, Level::VIEW]] as [$login, $level]) {
            FilePermission::create([
                'workspace_id' => $ws->id, 'node_id' => null,
                'target_type' => FilePermission::TARGET_USER, 'target_id' => $this->userId($login), 'level' => $level,
            ]);
        }
        return $ws->refresh();
    }

    protected function manager(): Library
    {
        return $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
    }

    /**
     * @return int[]
     */
    protected function childIds(Library $library, FileWorkspace $ws, ?FileNode $folder): array
    {
        return $library->children($ws, $folder)->pluck('id')->map('intval')->all();
    }

    public function testDraftsAreSeenByTheMakerAndTheManagersUntilApproved(): void
    {
        $ws = $this->workspace();
        $folder = $this->manager()->createFolder($ws, null, 'Contracts');
        Governance::setRules($this->manager(), $ws, $folder, ['requires_approval' => true]);

        $user1 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $file = $user1->storeFile($ws, $folder->refresh(), 'draft.txt', $this->local('draft'));
        $this->assertSame(FileNode::APPROVAL_DRAFT, $file->approval_status);
        $this->assertTrue($file->isDraft());

        $this->assertContains($file->id, $this->childIds($user1, $ws, $folder));
        $this->assertContains($file->id, $this->childIds($this->manager(), $ws, $folder));
        $user2 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertNotContains($file->id, $this->childIds($user2, $ws, $folder));
        try {
            $user2->findNode($ws, $file->id);
            $this->fail('a draft was found by another user');
        } catch (FileServerException $e) {
            $this->assertSame(403, $e->status());
        }
        // the maker may not approve their own file (they do not manage it)
        try {
            Governance::approve($user1, $ws, $file);
            $this->fail('the maker approved');
        } catch (FileServerException $e) {
            $this->assertSame(403, $e->status());
        }
        $this->assertCount(1, Governance::waiting($this->manager(), $ws));
        $this->assertCount(0, Governance::waiting($user2, $ws));

        // sent back: the maker still sees it, and a new version asks again
        Governance::reject($this->manager(), $ws, $file->refresh(), 'fix the date');
        $file->refresh();
        $this->assertSame(FileNode::APPROVAL_REJECTED, $file->approval_status);
        $this->assertSame('fix the date', $file->approval_comment);
        $this->assertNotContains($file->id, $this->childIds($user2, $ws, $folder));
        $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->addVersion($ws, $file, $this->local('fixed'));
        $this->assertSame(FileNode::APPROVAL_DRAFT, $file->refresh()->approval_status);

        Governance::approve($this->manager(), $ws, $file);
        $this->assertSame(FileNode::APPROVAL_APPROVED, $file->refresh()->approval_status);
        $this->assertFalse($file->isDraft());
        $this->assertContains($file->id, $this->childIds($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2), $ws, $folder));

        // a manager's own file does not wait
        $own = $this->manager()->storeFile($ws, $folder, 'by-manager.txt', $this->local('m'));
        $this->assertNull($own->approval_status);
    }

    public function testDraftsAreNotGivenByLinks(): void
    {
        $ws = $this->workspace();
        $folder = $this->manager()->createFolder($ws, null, 'Shared');
        Governance::setRules($this->manager(), $ws, $folder, ['requires_approval' => true]);
        $draft = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->storeFile($ws, $folder->refresh(), 'secret.txt', $this->local('s'));
        $open = $this->manager()->storeFile($ws, $folder, 'open.txt', $this->local('o'));

        $links = new ShareLinks($this->manager());
        $link = $links->create($ws, $folder, FileShareLink::KIND_SHARE, ['allow_download' => true, 'expires_at' => now()->addDays(3)->format('Y-m-d')]);
        $this->assertNull(ShareLinks::nodeInLink($link, $draft->id));
        $this->assertNotNull(ShareLinks::nodeInLink($link, $open->id));
        $names = ShareLinks::childrenInLink($folder)->pluck('name')->all();
        $this->assertSame(['open.txt'], $names);

        $zip = ShareLinks::linkLibrary()->zip($ws, [$folder]);
        $this->locals[] = $zip;
        $archive = new \ZipArchive();
        $archive->open($zip);
        $entries = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $entries[] = $archive->getNameIndex($i);
        }
        $archive->close();
        $this->assertContains('Shared/open.txt', $entries);
        $this->assertNotContains('Shared/secret.txt', $entries);

        // a request link into the folder: the files wait for the managers
        $request = $links->create($ws, $folder, FileShareLink::KIND_REQUEST, ['expires_at' => now()->addDays(3)->format('Y-m-d')]);
        $received = ShareLinks::receive($request, 'from-outside.txt', $this->local('x'));
        $this->assertSame(FileNode::APPROVAL_DRAFT, $received->approval_status);
        $this->assertNotContains($received->id, $this->childIds($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1), $ws, $folder));
        $this->assertContains($received->id, $this->childIds($this->manager(), $ws, $folder));
    }

    public function testLegalHoldStopsRemoving(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        $folder = $manager->createFolder($ws, null, 'Case');
        $file = $manager->storeFile($ws, $folder, 'evidence.txt', $this->local('v1'));

        Governance::setRules($manager, $ws, $file, ['legal_hold' => true]);
        foreach ([$file, $folder] as $node) {
            try {
                $manager->trash($ws, $node->refresh());
                $this->fail('a held node went to the trash');
            } catch (FileServerException $e) {
                $this->assertSame(423, $e->status());
            }
        }

        // the old versions are kept past the limit
        $ws->version_limit = 1;
        $ws->save();
        $manager->addVersion($ws, $file->refresh(), $this->local('v2'));
        $manager->addVersion($ws, $file->refresh(), $this->local('v3'));
        $this->assertSame(3, FileNodeVersion::query()->where('node_id', $file->id)->count());

        Governance::setRules($manager, $ws, $file->refresh(), ['legal_hold' => false]);
        $manager->trash($ws, $file->refresh());

        // a hold of the workspace: nothing goes, and what is in the trash stays
        Governance::setWorkspaceRules($manager, $ws, ['legal_hold' => true]);
        $other = $manager->storeFile($ws, null, 'other.txt', $this->local('o'));
        try {
            $manager->trash($ws, $other);
            $this->fail('trash in a held workspace');
        } catch (FileServerException $e) {
            $this->assertSame(423, $e->status());
        }
        try {
            $manager->purge($ws->refresh(), $file->refresh());
            $this->fail('purge in a held workspace');
        } catch (FileServerException $e) {
            $this->assertSame(423, $e->status());
        }
        try {
            $manager->deleteWorkspace($ws);
            $this->fail('a held workspace was removed');
        } catch (FileServerException $e) {
            $this->assertSame(423, $e->status());
        }
        $this->assertTrue(FileAuditLog::query()->where('workspace_id', $ws->id)->where('action', Audit::HOLD)->exists());
    }

    public function testRetention(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        Governance::setWorkspaceRules($manager, $ws, ['retention_days' => 30, 'retention_action' => Governance::RETENTION_DELETE]);
        $young = $manager->storeFile($ws, null, 'young.txt', $this->local('y'));
        $old = $manager->storeFile($ws, null, 'old.txt', $this->local('o'));
        FileNode::query()->where('id', $old->id)->update(['created_at' => now()->subDays(40)]);

        try {
            $manager->trash($ws->refresh(), $young);
            $this->fail('a file in its retention went to the trash');
        } catch (FileServerException $e) {
            $this->assertSame(423, $e->status());
        }
        $this->assertNotNull(Governance::retainedUntil($ws, $young));
        $this->assertNull(Governance::retainedUntil($ws, $old->refresh()));

        $this->assertGreaterThanOrEqual(1, Governance::applyRetention());
        $this->assertTrue($old->refresh()->isTrashed());
        $this->assertFalse($young->refresh()->isTrashed());
        $this->assertTrue(FileAuditLog::query()->where('node_id', $old->id)->where('action', Audit::RETENTION_DELETE)->exists());
    }

    public function testDownloadLevel(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        $file = $manager->storeFile($ws, null, 'report.txt', $this->local('r'));
        Governance::setWorkspaceRules($manager, $ws, ['download_level' => Level::UPLOAD]);
        $ws->refresh();

        $user2 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertFalse(Governance::canDownload($user2->access(), $ws, $file));
        $this->assertTrue(Governance::canDownload($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->access(), $ws, $file));

        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id, 'download'))->assertStatus(403);
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id))->assertOk();
    }

    public function testAuditLog(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        $file = $manager->storeFile($ws, null, 'a.txt', $this->local('a'));
        $manager->rename($ws, $file, 'b.txt');
        $manager->trash($ws, $file->refresh());
        $manager->restore($ws, $file->refresh());

        $actions = FileAuditLog::query()->where('node_id', $file->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame([Audit::UPLOAD, Audit::RENAME, Audit::TRASH, Audit::RESTORE], $actions);
        $this->assertSame(1, Audit::query($ws, ['action' => Audit::RENAME])->count());
        // the name at the time: the upload was "a.txt"
        $this->assertSame(3, Audit::query($ws, ['node' => 'b.txt'])->count());
        $this->assertSame(0, Audit::query($ws, ['from' => now()->addDay()->format('Y-m-d')])->count());

        // the page and the CSV: those who manage the workspace only
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB));
        $this->get(admin_urls('file_server', $ws->id, 'audit'))->assertOk()->assertSee('b.txt');
        $csv = $this->get(admin_urls('file_server', $ws->id, 'audit') . '?format=csv');
        $csv->assertOk();
        $this->assertStringContainsString('b.txt', $csv->streamedContent());
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->get(admin_urls('file_server', $ws->id, 'audit'))->assertStatus(403);

        $this->assertSame("'=1+1", \Exceedone\Exment\Controllers\FileServerGovernanceController::csvCell('=1+1'));
    }

    public function testFollowsCommentsAndNews(): void
    {
        $ws = $this->workspace();
        $folder = $this->manager()->createFolder($ws, null, 'Team');
        $user2 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertTrue((new Collaboration($user2))->toggleFollow($ws, $folder));
        $this->assertTrue((new Collaboration($user2))->isFollowing($ws, $folder));
        $user2Id = $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $user1 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $file = $user1->storeFile($ws, $folder, 'plan.txt', $this->local('p'));
        $this->assertContains($user2Id, Collaboration::followers($file));

        $before = NotifyNavbar::query()->where('target_user_id', $user2Id)->count();
        $this->assertSame(1, Collaboration::send($file->id, Collaboration::EVENT_ADDED, $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1)));
        $this->assertSame($before + 1, NotifyNavbar::query()->where('target_user_id', $user2Id)->count());
        // not to the one who did it
        $this->assertSame(0, Collaboration::send($file->id, Collaboration::EVENT_ADDED, $user2Id));

        // a draft is not told to those who may not see it
        Governance::setRules($this->manager(), $ws, $folder, ['requires_approval' => true]);
        $draft = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->storeFile($ws, $folder->refresh(), 'draft.txt', $this->local('d'));
        $this->assertSame(0, Collaboration::send($draft->id, Collaboration::EVENT_ADDED, null));
        $this->assertContains($this->userId(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB), Collaboration::managers($ws, $draft));

        // comments: those who may see; the maker or a manager removes one
        $comment = (new Collaboration($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2)))->addComment($ws, $file, 'looks good');
        $this->assertCount(1, (new Collaboration($this->manager()))->comments($ws, $file));
        try {
            (new Collaboration($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)))->deleteComment($ws, $file, $comment);
            $this->fail('another user removed the comment');
        } catch (FileServerException $e) {
            $this->assertSame(403, $e->status());
        }
        (new Collaboration($this->manager()))->deleteComment($ws, $file, $comment);
        $this->assertCount(0, (new Collaboration($this->manager()))->comments($ws, $file));
        try {
            (new Collaboration($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2)))->addComment($ws, $draft, 'peek');
            $this->fail('a comment on a draft the user may not see');
        } catch (FileServerException $e) {
            $this->assertSame(403, $e->status());
        }

        $this->assertTrue(FileAuditLog::query()->where('node_id', $file->id)->where('action', Audit::COMMENT)->exists());
    }
}
