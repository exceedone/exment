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
use Exceedone\Exment\Model\System;
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
            // the managers of the tests are not system administrators
            'exment.file_server_workspace_create' => 'all',
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
        $this->login($loginId);
        return new Library(new Access($this->userId($loginId), false));
    }

    /**
     * Log in as the user (the user of the last request is kept in the session of the request).
     */
    protected function login(string $loginId): void
    {
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail($loginId));
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
        return $library->children($ws, $folder)->pluck('id')->map(function ($id) {
                return intval($id);
            })->all();
    }

    public function testTheFileServerTurnedOff(): void
    {
        $features = '\Exceedone\Exment\Services\Features';
        if (!class_exists($features)) {
            $this->markTestSkipped('Feature management is not in this Exment yet');
        }
        $ws = $this->workspace();
        $manager = $this->manager();
        $folder = $manager->createFolder($ws, null, 'Out');
        $link = (new ShareLinks($manager))->create($ws, $folder, FileShareLink::KIND_SHARE, ['expires_at' => now()->addDays(3)->format('Y-m-d')]);
        $this->assertNotNull(ShareLinks::resolve($link->token));

        $features::save(['file_server' => ['enabled' => false]]);
        System::clearRequestSession();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $this->assertFalse(Library::featureEnabled());
        $this->assertSame(Level::NONE, Access::current()->level($ws, null), 'no one may do anything');
        $this->assertNull(ShareLinks::resolve($link->token), 'the links are gone');
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('exment:file-server-purge'));
        // the data stays
        $this->assertTrue(FileNode::query()->whereKey($folder->id)->exists());
        $this->assertTrue(FileWorkspace::query()->whereKey($ws->id)->exists());

        $features::save(['file_server' => ['enabled' => true]]);
        System::clearRequestSession();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $this->assertSame(Level::MANAGE, Access::current()->level($ws, null), 'all comes back');
        $this->assertNotNull(ShareLinks::resolve($link->token));
    }

    /**
     * @param \Closure(): mixed $call
     */
    protected function assertRefused(\Closure $call, int $status, string $what): void
    {
        try {
            $call();
            $this->fail($what);
        } catch (FileServerException $e) {
            $this->assertSame($status, $e->status(), $what);
        }
    }

    public function testCopyMoveAndVersionsDoNotSkipTheApproval(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        $approval = $manager->createFolder($ws, null, 'Approval');
        Governance::setRules($manager, $ws, $approval, ['requires_approval' => true]);
        $free = $manager->createFolder($ws, null, 'Free');
        $approved = $manager->storeFile($ws, $free, 'approved.txt', $this->local('a'));

        $user1 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $draft = $user1->storeFile($ws, $approval->refresh(), 'draft.txt', $this->local('d'));
        $this->assertTrue($draft->isDraft());

        // a draft is neither copied nor moved by its maker
        $this->assertRefused(function () use ($user1, $ws, $draft, $approval) {
            $user1->copy($ws, $draft, $approval);
        }, 403, 'a draft was copied');
        $this->assertRefused(function () use ($user1, $ws, $draft, $free) {
            $user1->move($ws, $draft, $free);
        }, 403, 'a draft was moved out');

        // a copy into the folder that needs an approval waits for it
        $copy = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->copy($ws, $approved, $approval);
        $this->assertSame(FileNode::APPROVAL_DRAFT, $copy->refresh()->approval_status);
        $user2 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertNotContains($copy->id, $this->childIds($user2, $ws, $approval->refresh()));

        // moved in by a user who does not manage it: it waits too
        FilePermission::query()->where('workspace_id', $ws->id)->whereNull('node_id')->where('target_id', $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1))->update(['level' => Level::EDIT]);
        $user1 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $other = $manager->storeFile($ws, $free, 'other.txt', $this->local('o'));
        $user1->move($ws, $other->refresh(), $approval->refresh());
        $this->assertSame(FileNode::APPROVAL_DRAFT, $other->refresh()->approval_status);

        // a new version of an approved file in the folder: not by a user who does not manage it
        $inFolder = $manager->storeFile($ws, $approval->refresh(), 'by-manager.txt', $this->local('m'));
        $this->assertNull($inFolder->approval_status);
        $this->assertRefused(function () use ($user1, $ws, $inFolder) {
            $user1->addVersion($ws, $inFolder->refresh(), $this->local('m2'));
        }, 403, 'a new version skipped the approval');
        // the maker of a draft still sends its new versions
        $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->addVersion($ws, $draft->refresh(), $this->local('d2'));
        // the manager does
        $manager->addVersion($ws, $inFolder->refresh(), $this->local('m3'));
    }

    public function testFoldersAndTheTrashDoNotSkipTheApproval(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        $approval = $manager->createFolder($ws, null, 'Approval');
        Governance::setRules($manager, $ws, $approval, ['requires_approval' => true]);
        $free = $manager->createFolder($ws, null, 'Free');
        $inner = $manager->createFolder($ws, $free, 'F');
        $manager->storeFile($ws, $inner, 'a.txt', $this->local('a'));
        $manager->storeFile($ws, $inner, 'b.txt', $this->local('b'));
        $moved = $manager->createFolder($ws, $free, 'G');
        $x = $manager->storeFile($ws, $moved, 'x.txt', $this->local('x'));
        $y = $manager->storeFile($ws, $moved, 'y.txt', $this->local('y'));
        FilePermission::query()->where('workspace_id', $ws->id)->whereNull('node_id')->where('target_id', $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1))->update(['level' => Level::EDIT]);

        // a folder copied in: its files wait
        $copy = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->copy($ws, $inner, $approval->refresh());
        $copied = FileNode::query()->where('parent_id', $copy->id)->get();
        $this->assertCount(2, $copied);
        foreach ($copied as $file) {
            $this->assertSame(FileNode::APPROVAL_DRAFT, $file->approval_status);
        }

        // x in the trash, its folder moved in, x back: it waits too
        $user1 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user1->trash($ws, $x->refresh());
        $user1->move($ws, $moved->refresh(), $approval->refresh());
        $this->assertSame(FileNode::APPROVAL_DRAFT, $y->refresh()->approval_status);
        $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->restore($ws, $x->refresh());
        $this->assertSame(FileNode::APPROVAL_DRAFT, $x->refresh()->approval_status);

        // between two folders that need an approval: an approved file stays approved
        $other = $manager->createFolder($ws, $approval, 'B');
        $approved = $manager->storeFile($ws, $approval->refresh(), 'ok.txt', $this->local('ok'));
        $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->move($ws, $approved->refresh(), $other->refresh());
        $this->assertNull($approved->refresh()->approval_status);
    }

    public function testACopyIsADownload(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        // a zip (not a type shown in the page)
        $zipPath = $this->local('');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('a.txt', 'secret');
        $zip->close();
        $file = $manager->storeFile($ws, null, 'secret.zip', $zipPath);
        Governance::setWorkspaceRules($manager, $ws, ['download_level' => Level::EDIT]);
        $ws->refresh();
        // user1 may upload, not download: no copy either
        $user1 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertRefused(function () use ($user1, $ws, $file) {
            $user1->copy($ws, $file, null);
        }, 403, 'a copy of a file that may not be downloaded');

        // and the bytes of a file that is not shown in the page are a download too
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $answer = $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id, 'content'));
        // the error of the admin pages (not the file)
        $this->assertNotInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $answer->baseResponse);
        $this->assertStringNotContainsString('attachment', strval($answer->headers->get('Content-Disposition')));
        // who may download gets it, and it is in the audit log
        $this->login(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id, 'content'))->assertOk();
        $this->assertTrue(FileAuditLog::query()->where('node_id', $file->id)->where('action', Audit::DOWNLOAD)->exists());
    }

    public function testMoveTrimAndPurgeKeepTheHolds(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        $case = $manager->createFolder($ws, null, 'Case');
        $inner = $manager->createFolder($ws, $case, 'Inner');
        $file = $manager->storeFile($ws, $inner, 'evidence.txt', $this->local('v1'));
        Governance::setRules($manager, $ws, $case, ['legal_hold' => true]);
        // out of the held folder: no
        $this->assertRefused(function () use ($manager, $ws, $file) {
            $manager->move($ws, $file->refresh(), null);
        }, 423, 'a held file was moved out');

        // the versions are kept while the file must be kept
        Governance::setRules($manager, $ws, $case->refresh(), ['legal_hold' => false]);
        Governance::setWorkspaceRules($manager, $ws, ['retention_days' => 30]);
        $ws->refresh();
        $ws->version_limit = 1;
        $ws->save();
        $manager->addVersion($ws, $file->refresh(), $this->local('v2'));
        $manager->addVersion($ws, $file->refresh(), $this->local('v3'));
        $this->assertSame(3, FileNodeVersion::query()->where('node_id', $file->id)->count());

        // a hold set on a folder above, after the file went to the trash
        Governance::setWorkspaceRules($manager, $ws, ['retention_days' => null]);
        $ws->refresh();
        $manager->trash($ws, $file->refresh());
        Governance::setRules($manager, $ws, $case->refresh(), ['legal_hold' => true]);
        $this->assertRefused(function () use ($manager, $ws, $file) {
            $manager->purge($ws, $file->refresh());
        }, 423, 'a file under a held folder was removed for good');
    }

    public function testTheMakerOfADraftTakenOutOfTheWorkspace(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        $folder = $manager->createFolder($ws, null, 'Approval');
        Governance::setRules($manager, $ws, $folder, ['requires_approval' => true]);
        $draft = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->storeFile($ws, $folder->refresh(), 'mine.txt', $this->local('m'));
        $this->assertSame(Level::EDIT, (new Access($this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), false))->level($ws, $draft));
        // taken out of the workspace: nothing, not even their draft
        FilePermission::query()->where('workspace_id', $ws->id)->where('target_id', $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1))->delete();
        $this->assertSame(Level::NONE, (new Access($this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), false))->level($ws, $draft->refresh()));
    }

    public function testTheNewsEscapesWhatUsersWrote(): void
    {
        $this->assertSame('&lt;b&gt;x&lt;/b&gt; &#36;{system:mail_host} &#36;5', Collaboration::safeText('<b>x</b> ${system:mail_host} $5'));
        $ws = $this->workspace();
        $folder = $this->manager()->createFolder($ws, null, 'Team');
        $user2 = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2);
        (new Collaboration($user2))->toggleFollow($ws, $folder);
        $file = $this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1)->storeFile($ws, $folder, "Q&A O'Brien \${x}.txt", $this->local('x'));
        Collaboration::send($file->id, Collaboration::EVENT_COMMENT, $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), ['comment' => '<script>x</script> ${system:site_id}']);
        $alert = NotifyNavbar::withoutGlobalScopes()->where('target_user_id', $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER2))->orderByDesc('id')->first();
        $this->assertNotNull($alert);
        // the subject is plain text (escaped once where it is shown): as the name is, without "${"
        $subject = strval($alert->notify_subject);
        $this->assertStringContainsString("Q&A O'Brien", $subject);
        $this->assertStringNotContainsString('&amp;', $subject);
        $this->assertStringNotContainsString('${', $subject);
        // the body is shown as HTML: escaped, and no line breaks of its own (the alert makes them)
        $body = strval($alert->notify_body);
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('Q&amp;A O&#039;Brien', $body);
        $this->assertStringNotContainsString('${', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringNotContainsString('<br', $body);
        $this->assertSame('a $ {x}', Collaboration::plainText('a ${x}'));
        $this->assertSame('ab', Collaboration::cutHtml('ab&amp;c', 4));
    }

    public function testARequestLinkSeesThroughWhatDoesNotShow(): void
    {
        $ws = $this->workspace();
        $folder = $this->manager()->createFolder($ws, null, 'In');
        $link = (new ShareLinks($this->manager()))->create($ws, $folder, FileShareLink::KIND_REQUEST, ['expires_at' => now()->addDays(3)->format('Y-m-d')]);
        $this->assertRefused(function () use ($link) {
            ShareLinks::receive($link, "run.exe.\u{202E}", $this->local('MZ'));
        }, 415, 'an exe with a dot and a character that does not show was taken');
    }

    public function testANameDoesNotTurnTheDirection(): void
    {
        $name = $this->manager()->validName("report\u{202E}txt.exe");
        $this->assertSame('reporttxt.exe', $name);
    }

    public function testUploadsInPartsTakeTheirSpace(): void
    {
        $ws = $this->workspace();
        $ws->quota_bytes = 100;
        $ws->save();
        $uploader = new \Exceedone\Exment\Services\FileServer\Uploader($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $first = $uploader->init($ws, null, 'a.bin', 60);
        // the upload not finished takes its 60 bytes: another 60 does not fit
        $this->assertRefused(function () use ($uploader, $ws) {
            $uploader->init($ws, null, 'b.bin', 60);
        }, 413, 'the space of an upload not finished was not counted');
        // the parts: from the bytes that came only
        $chunk = $this->local(str_repeat('x', 30));
        $this->assertSame(30, $uploader->append($first, 0, $chunk));
        $this->assertRefused(function () use ($uploader, $first, $chunk) {
            $uploader->append($first, 0, $chunk);
        }, 409, 'a part sent twice was added twice');
        $this->assertSame(60, $uploader->append($first->refresh(), 30, $chunk));

        // the uploads a user may have not finished
        config(['exment.file_server_max_uploads' => 2]);
        $ws->quota_bytes = null;
        $ws->save();
        $uploader->init($ws->refresh(), null, 'c.bin', 1);
        $this->assertRefused(function () use ($uploader, $ws) {
            $uploader->init($ws, null, 'd.bin', 1);
        }, 429, 'too many uploads not finished');
        // the largest file, unless the site says
        $this->assertSame(2147483648, \Exceedone\Exment\Services\FileServer\Uploader::maxFileSize());
    }

    public function testRemovingAWorkspaceRemovesWhatIsInIt(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        $folder = $manager->createFolder($ws, null, 'Docs');
        $file = $manager->storeFile($ws, $folder, 'a.txt', $this->local('bytes'));
        (new ShareLinks($manager))->create($ws, $file, FileShareLink::KIND_SHARE);
        $path = strval($file->refresh()->current_version->path);
        $disk = Storage::disk(Define::DISKNAME_ADMIN);
        $this->assertTrue($disk->exists($path));

        $manager->deleteWorkspace($ws);
        $this->assertSame(0, FileNode::query()->where('workspace_id', $ws->id)->count());
        $this->assertSame(0, FileNodeVersion::query()->where('node_id', $file->id)->count());
        $this->assertSame(0, FilePermission::query()->where('workspace_id', $ws->id)->count());
        $this->assertSame(0, FileShareLink::query()->where('workspace_id', $ws->id)->count());
        $this->assertFalse($disk->exists($path), 'the bytes stayed on the disk');
        $this->assertNull(FileWorkspace::find($ws->id));
    }

    public function testAUserOfADeepFolderDoesNotSeeTheNamesAbove(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        $user2 = $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $managerId = $this->userId(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        // user2 sees only the deep folder
        FilePermission::query()->where('workspace_id', $ws->id)->whereNull('node_id')->where('target_id', $user2)->delete();
        $secret = $manager->createFolder($ws, null, 'Secret' . uniqid());
        $manager->setPermissions($ws, $secret, [['target_type' => FilePermission::TARGET_USER, 'target_id' => $managerId, 'level' => Level::MANAGE]]);
        $deep = $manager->createFolder($ws, $secret->refresh(), 'Deep');
        $manager->setPermissions($ws, $deep, [
            ['target_type' => FilePermission::TARGET_USER, 'target_id' => $managerId, 'level' => Level::MANAGE],
            ['target_type' => FilePermission::TARGET_USER, 'target_id' => $user2, 'level' => Level::VIEW],
        ]);
        $file = $manager->storeFile($ws, $deep->refresh(), 'in-deep.txt', $this->local('x'));

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $page = $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id));
        $page->assertOk();
        $page->assertSee('in-deep.txt');
        // not the name of the folder above, and not who else may see it: their own level only
        $page->assertDontSee($secret->name);
        $page->assertSee(exmtrans('file_server.permissions_your_level', ['level' => Level::label(Level::VIEW)]));
    }

    public function testBackFromTheTrashItKeepsWhoCouldSee(): void
    {
        $ws = $this->workspace();
        $manager = $this->manager();
        $managerId = $this->userId(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $folder = $manager->createFolder($ws, null, 'Closed');
        // the folder: the manager only (user2 sees the top of the workspace, not it)
        $manager->setPermissions($ws, $folder, [['target_type' => FilePermission::TARGET_USER, 'target_id' => $managerId, 'level' => Level::MANAGE]]);
        $file = $manager->storeFile($ws, $folder->refresh(), 'closed.txt', $this->local('x'));
        $level = function () use ($ws, $file) {
            return (new Access($this->userId(TestDefine::TESTDATA_USER_LOGINID_USER2), false))->level($ws, $file->refresh());
        };
        $this->assertSame(Level::NONE, $level());
        $manager->trash($ws, $file->refresh());
        $manager->trash($ws, $folder->refresh());
        // the file back at the top (its folder is in the trash): user2 still does not see it
        $manager->restore($ws, $file->refresh());
        $this->assertNull($file->refresh()->parent_id);
        $this->assertSame(Level::NONE, $level());
        $this->assertSame(Level::MANAGE, (new Access($managerId, false))->level($ws, $file->refresh()));
    }

    public function testWhoMayMakeAWorkspace(): void
    {
        $user = new Access($this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), false);
        config(['exment.file_server_workspace_create' => null]);
        $this->assertFalse($user->canCreateWorkspace(), 'by default, the system administrators only');
        config(['exment.file_server_workspace_create' => 'admin']);
        $this->assertFalse($user->canCreateWorkspace());
        config(['exment.file_server_workspace_create' => 'all']);
        $this->assertTrue($user->canCreateWorkspace());
        $this->assertTrue((new Access($this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), true))->canCreateWorkspace());
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

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        // the error page of Exment (not the file)
        $denied = $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id, 'download'));
        $this->assertStringNotContainsString('attachment', strval($denied->headers->get('Content-Disposition')));
        $this->assertNotInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $denied->baseResponse);
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
        $this->login(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $this->get(admin_urls('file_server', $ws->id, 'audit'))->assertOk()->assertSee('b.txt');
        $csv = $this->get(admin_urls('file_server', $ws->id, 'audit') . '?format=csv');
        $csv->assertOk();
        $this->assertStringContainsString('b.txt', $csv->streamedContent());
        // others get the error page of Exment (it ends the process, so it is not asked here)
        $this->assertFalse($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2)->access()->can($ws, null, Level::MANAGE));

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

        // not the scope of the alerts of the login user
        $before = NotifyNavbar::withoutGlobalScopes()->where('target_user_id', $user2Id)->count();
        $this->assertSame(1, Collaboration::send($file->id, Collaboration::EVENT_ADDED, $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1)));
        $this->assertSame($before + 1, NotifyNavbar::withoutGlobalScopes()->where('target_user_id', $user2Id)->count());
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
