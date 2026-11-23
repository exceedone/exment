<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FilePermission;
use Exceedone\Exment\Model\FileShareLink;
use Exceedone\Exment\Model\FileWorkspace;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\FileServer\Access;
use Exceedone\Exment\Services\FileServer\FileServerException;
use Exceedone\Exment\Services\FileServer\Level;
use Exceedone\Exment\Services\FileServer\Library;
use Exceedone\Exment\Services\FileServer\ShareLinks;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

/**
 * File server, phase 3 (ISS00377): share links and request links for people outside.
 */
class FileShareTest extends FeatureTestBase
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

    protected function addMember(FileWorkspace $ws, string $loginId, int $level): void
    {
        FilePermission::create([
            'workspace_id' => $ws->id, 'node_id' => null,
            'target_type' => FilePermission::TARGET_USER, 'target_id' => $this->userId($loginId), 'level' => $level,
        ]);
    }

    /**
     * @return array{0: FileWorkspace, 1: FileNode, 2: FileNode}
     */
    protected function sample(): array
    {
        $lib = $this->admin();
        $ws = $lib->createWorkspace(['name' => 'Share']);
        $folder = $lib->createFolder($ws, null, 'Out');
        $file = $lib->storeFile($ws, $folder, 'hello.txt', $this->local('hello outside'));
        return [$ws, $folder, $file];
    }

    protected function shareUrl(FileShareLink $link, string $path = ''): string
    {
        return '/' . ShareLinks::basePath() . '/' . $link->plainToken() . ($path === '' ? '' : '/' . $path);
    }

    protected function links(Library $library): ShareLinks
    {
        return new ShareLinks($library);
    }

    public function testARequestLinkClosesWhenItsMakerMayOnlyUpload(): void
    {
        [$ws, $folder] = $this->sample();
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER2, Level::EDIT);
        $link = $this->links($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2))->create($ws, $folder, FileShareLink::KIND_REQUEST);
        $this->assertNotNull(ShareLinks::resolve(strval($link->plainToken())));
        // the maker may only upload now (as a link made before 7.2.1 by who could upload)
        FilePermission::query()->where('workspace_id', $ws->id)->whereNull('node_id')
            ->where('target_id', $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER2))->update(['level' => Level::UPLOAD]);
        $this->assertNull(ShareLinks::resolve(strval($link->plainToken())));
    }

    public function testWhoMayMakeALink(): void
    {
        [$ws, $folder, $file] = $this->sample();
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::UPLOAD);
        $uploader = $this->links($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1));

        // an uploader may make neither a request link (those who may edit the folder, ISS00433) nor a share link
        try {
            $uploader->create($ws, $folder, FileShareLink::KIND_REQUEST);
            $this->fail('an uploader made a request link');
        } catch (FileServerException $e) {
            $this->assertSame(403, $e->status());
        }
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER2, Level::EDIT);
        $editor = $this->links($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->assertSame(FileShareLink::KIND_REQUEST, $editor->create($ws, $folder, FileShareLink::KIND_REQUEST)->kind);
        // a password too short
        try {
            $editor->create($ws, $folder, FileShareLink::KIND_REQUEST, ['password' => 'short']);
            $this->fail('a password of 5 characters');
        } catch (FileServerException $e) {
            $this->assertStringContainsString('8', $e->getMessage());
        }
        try {
            $uploader->create($ws, $file, FileShareLink::KIND_SHARE);
            $this->fail('an uploader made a share link');
        } catch (FileServerException $e) {
            $this->assertSame(403, $e->status());
        }
        // a request link is for a folder only
        $this->expectException(FileServerException::class);
        $this->links($this->admin())->create($ws, $file, FileShareLink::KIND_REQUEST);
    }

    public function testOpensOfALinkAreWrittenOnceInAWhile(): void
    {
        [$ws, , $file] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $file, FileShareLink::KIND_SHARE);
        $this->get($this->shareUrl($link))->assertOk();
        $this->get($this->shareUrl($link))->assertOk();
        // once in 10 minutes for a link and an address (ISS00433)
        $this->assertSame(1, \Exceedone\Exment\Model\FileAuditLog::query()->where('link_id', $link->id)->where('action', \Exceedone\Exment\Services\FileServer\Audit::LINK_OPEN)->count());
    }

    public function testAShareLinkOfAFile(): void
    {
        [$ws, , $file] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $file, FileShareLink::KIND_SHARE, ['allow_download' => true]);

        $this->get($this->shareUrl($link))->assertOk()->assertSee('hello.txt')->assertSee('hello outside')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $download = $this->get($this->shareUrl($link, 'download'));
        $download->assertOk();
        $this->assertSame('hello outside', $download->streamedContent());
        $this->assertSame(1, $link->refresh()->access_count);

        // a token that is not there: the same page as a link that ended
        $this->get('/' . ShareLinks::basePath() . '/' . str_repeat('a', ShareLinks::TOKEN_LENGTH))->assertStatus(404);
    }

    public function testAPreviewOnlyLinkDoesNotDownload(): void
    {
        [$ws, , $file] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $file, FileShareLink::KIND_SHARE, ['allow_download' => false]);
        $this->get($this->shareUrl($link))->assertOk()->assertSee(exmtrans('file_server.share_preview_only_note'));
        $this->get($this->shareUrl($link, 'download'))->assertStatus(403);
    }

    public function testAPassword(): void
    {
        [$ws, , $file] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $file, FileShareLink::KIND_SHARE, ['password' => 'secret-1']);

        $this->get($this->shareUrl($link))->assertOk()->assertSee(exmtrans('file_server.share_password_title'))->assertDontSee('hello outside');
        $this->get($this->shareUrl($link, 'download'))->assertStatus(404);

        $this->post($this->shareUrl($link), ['password' => 'wrong'])->assertRedirect();
        $this->get($this->shareUrl($link))->assertSee(exmtrans('file_server.share_password_wrong'));

        $this->post($this->shareUrl($link), ['password' => 'secret-1'])->assertRedirect();
        $this->get($this->shareUrl($link))->assertOk()->assertSee('hello outside');
        $this->assertNull($link->refresh()->toArray()['password_hash'] ?? null);
    }

    public function testALinkEndsIsTakenBackOrIsUsedUp(): void
    {
        [$ws, , $file] = $this->sample();
        $links = $this->links($this->admin());

        $ended = $links->create($ws, $file, FileShareLink::KIND_SHARE, ['expires_at' => now()->format('Y-m-d')]);
        $this->get($this->shareUrl($ended))->assertOk();
        FileShareLink::query()->where('id', $ended->id)->update(['expires_at' => now()->subDay()]);
        $this->get($this->shareUrl($ended))->assertStatus(404)->assertSee(exmtrans('file_server.share_gone_title'));

        $revoked = $links->create($ws, $file, FileShareLink::KIND_SHARE);
        $links->revoke($ws, $revoked);
        $this->get($this->shareUrl($revoked))->assertStatus(404);

        $once = $links->create($ws, $file, FileShareLink::KIND_SHARE, ['max_access' => 1]);
        $this->get($this->shareUrl($once))->assertOk();
        $this->flushSession();
        $this->get($this->shareUrl($once))->assertStatus(404);

        // the file in the trash: the link does not open
        $live = $links->create($ws, $file, FileShareLink::KIND_SHARE);
        $this->admin()->trash($ws, $file);
        $this->get($this->shareUrl($live))->assertStatus(404);
    }

    public function testAFolderIsSeenWithWhatIsInItAndNothingElse(): void
    {
        [$ws, $folder, $file] = $this->sample();
        $lib = $this->admin();
        $inner = $lib->createFolder($ws, $folder, 'Inner');
        $deep = $lib->storeFile($ws, $inner, 'deep.txt', $this->local('deep'));
        $outside = $lib->storeFile($ws, null, 'secret.txt', $this->local('secret'));
        $link = $this->links($lib)->create($ws, $folder, FileShareLink::KIND_SHARE);

        $this->get($this->shareUrl($link))->assertOk()->assertSee('hello.txt')->assertSee('Inner');
        $this->get($this->shareUrl($link, 'item/' . $inner->id))->assertOk()->assertSee('deep.txt');
        $this->assertSame('deep', $this->get($this->shareUrl($link, 'download') . '?node=' . $deep->id)->streamedContent());

        // a file outside the folder of the link
        $this->get($this->shareUrl($link, 'item/' . $outside->id))->assertStatus(404);
        $this->get($this->shareUrl($link, 'download') . '?node=' . $outside->id)->assertStatus(404);
        $this->get($this->shareUrl($link, 'item/' . $file->id))->assertOk()->assertSee('hello outside');
    }

    public function testARequestLinkTakesFilesAndShowsNothing(): void
    {
        [$ws, $folder] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $folder, FileShareLink::KIND_REQUEST, ['message' => 'Please send the invoice']);

        $this->get($this->shareUrl($link))->assertOk()->assertSee('Please send the invoice')->assertDontSee('hello.txt');
        $this->post($this->shareUrl($link, 'upload'), [
            'files' => [UploadedFile::fake()->createWithContent('hello.txt', 'from outside'), UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJson(['result' => true]);

        $names = $this->admin()->children($ws, $folder)->pluck('name')->all();
        $this->assertSame(['hello (2).txt', 'hello.txt', 'invoice.pdf'], $names);
        // the file that was there is not changed
        $old = FileNode::query()->where('parent_id', $folder->id)->where('name', 'hello.txt')->firstOrFail();
        $this->assertSame(1, $old->current_version->version_no);

        // a share link cannot take files
        $share = $this->links($this->admin())->create($ws, $folder, FileShareLink::KIND_SHARE);
        $this->post($this->shareUrl($share, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('x.txt', 'x')]], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function testTheRulesOfTheSite(): void
    {
        [$ws, , $file] = $this->sample();
        $links = $this->links($this->admin());

        // no end given: 30 days by default, never past the latest end of the site
        config(['exment.file_server_share_max_days' => 90, 'exment.file_server_share_default_days' => 30]);
        $this->assertSame(now()->addDays(30)->format('Y-m-d'), $links->create($ws, $file, FileShareLink::KIND_SHARE)->expires_at->format('Y-m-d'));
        config(['exment.file_server_share_max_days' => 7]);
        $this->assertSame(now()->addDays(7)->format('Y-m-d'), $links->create($ws, $file, FileShareLink::KIND_SHARE)->expires_at->format('Y-m-d'));
        // no limit and no default: a link without an end, as before
        config(['exment.file_server_share_max_days' => 0, 'exment.file_server_share_default_days' => 0]);
        $this->assertNull($links->create($ws, $file, FileShareLink::KIND_SHARE)->expires_at);
        config(['exment.file_server_share_max_days' => 7]);
        try {
            $links->create($ws, $file, FileShareLink::KIND_SHARE, ['expires_at' => now()->addDays(30)->format('Y-m-d')]);
            $this->fail('a link past the days of the site');
        } catch (FileServerException $e) {
            $this->assertSame(422, $e->status());
        }
        $this->assertNotNull($links->create($ws, $file, FileShareLink::KIND_SHARE, ['expires_at' => now()->addDays(3)->format('Y-m-d')])->expires_at);

        config(['exment.file_server_external_share' => false]);
        $this->expectException(FileServerException::class);
        $links->create($ws, $file, FileShareLink::KIND_SHARE, ['expires_at' => now()->addDays(3)->format('Y-m-d')]);
    }

    public function testWhoMayTakeALinkBack(): void
    {
        [$ws, , $file] = $this->sample();
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::EDIT);
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER2, Level::EDIT);
        $link = $this->links($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1))->create($ws, $file, FileShareLink::KIND_SHARE);
        $link->created_user_id = $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $link->save();

        try {
            $this->links($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2))->revoke($ws, $link);
            $this->fail('another editor took the link back');
        } catch (FileServerException $e) {
            $this->assertSame(403, $e->status());
        }
        $this->links($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1))->revoke($ws, $link);
        $this->assertNotNull($link->refresh()->revoked_at);
    }

    public function testTheFilePageMakesALink(): void
    {
        [$ws, , $file] = $this->sample();
        \Exceedone\Exment\Model\System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $base = admin_urls('file_server', $ws->id, 'nodes', $file->id);

        $this->get($base, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee(exmtrans('file_server.share_links'));
        $json = $this->post($base . '/links', ['kind' => 'share', 'expires_at' => now()->addDays(2)->format('Y-m-d'), 'allow_download' => '1'], $headers)
            ->assertOk()->json();
        $this->assertStringContainsString('/' . ShareLinks::basePath() . '/', $json['url']);
        $link = FileShareLink::query()->where('node_id', $file->id)->firstOrFail();
        $this->get($base, ['X-Requested-With' => 'XMLHttpRequest'])->assertSee($link->url(), false);

        $this->post($base . '/links/' . $link->id . '/revoke', [], $headers)->assertOk();
        $this->assertNotNull($link->refresh()->revoked_at);
    }

    // ================================================================ ISS00410

    public function testALinkDoesNotGiveWhatHasItsOwnPermissions(): void
    {
        [$ws, $folder] = $this->sample();
        $lib = $this->admin();
        // a name a random token never has ("HR" alone may be in the token of the link)
        $hr = $lib->createFolder($ws, $folder, 'HR department');
        $salary = $lib->storeFile($ws, $hr, 'salary.txt', $this->local('salary'));
        $deeper = $lib->createFolder($ws, $hr, 'Deeper');
        $deep = $lib->storeFile($ws, $deeper, 'deep.txt', $this->local('deep'));
        $own = $lib->storeFile($ws, $folder, 'own.txt', $this->local('own'));
        foreach ([$hr, $own] as $node) {
            $lib->breakInheritance($ws, $node);
            $lib->setPermissions($ws, $node, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'level' => Level::MANAGE]]);
        }
        // made by one who edits the folder, but cannot see HR
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::EDIT);
        $link = $this->links($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1))->create($ws, $folder, FileShareLink::KIND_SHARE);

        $this->get($this->shareUrl($link))->assertOk()->assertSee('hello.txt')->assertDontSee('HR department')->assertDontSee('own.txt');
        foreach ([$hr, $salary, $deeper, $deep, $own] as $node) {
            $this->get($this->shareUrl($link, 'item/' . $node->id))->assertStatus(404);
            $this->get($this->shareUrl($link, 'download') . '?node=' . $node->id)->assertStatus(404);
            $this->get($this->shareUrl($link, 'content') . '?node=' . $node->id)->assertStatus(404);
        }

        // the zip of the folder leaves them out
        $zipResponse = $this->get($this->shareUrl($link, 'download'));
        $zipResponse->assertOk();
        $base = $zipResponse->baseResponse;
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $base);
        $zipPath = $base->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        $this->assertContains('Out/hello.txt', $names);
        foreach ($names as $name) {
            $this->assertStringNotContainsString('HR', strval($name));
            $this->assertStringNotContainsString('own.txt', strval($name));
        }

        // its own permissions given later close it too
        $inner = $lib->createFolder($ws, $folder, 'Later');
        $this->get($this->shareUrl($link, 'item/' . $inner->id))->assertOk();
        $lib->breakInheritance($ws, $inner);
        $this->get($this->shareUrl($link, 'item/' . $inner->id))->assertStatus(404);
    }

    public function testTheCountOfALink(): void
    {
        [$ws, , $file] = $this->sample();
        $links = $this->links($this->admin());

        // max 1: the one who opened it can still download it
        $once = $links->create($ws, $file, FileShareLink::KIND_SHARE, ['max_access' => 1]);
        $this->get($this->shareUrl($once))->assertOk();
        $this->assertSame('hello outside', $this->get($this->shareUrl($once, 'download'))->streamedContent());
        $this->assertSame(1, $once->refresh()->access_count);
        $this->flushSession();
        $this->get($this->shareUrl($once))->assertStatus(404);
        $this->get($this->shareUrl($once, 'download'))->assertStatus(404);

        // a download without the page counts too
        $direct = $links->create($ws, $file, FileShareLink::KIND_SHARE, ['max_access' => 1]);
        $this->flushSession();
        $this->get($this->shareUrl($direct, 'download'))->assertOk();
        $this->assertSame(1, $direct->refresh()->access_count);
        $this->flushSession();
        $this->get($this->shareUrl($direct, 'download'))->assertStatus(404);
        $this->assertSame(1, $direct->refresh()->access_count);
    }

    public function testThePasswordStopsAfterTooManyTries(): void
    {
        [$ws, , $file] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $file, FileShareLink::KIND_SHARE, ['password' => 'right-12']);
        \Illuminate\Support\Facades\Cache::forget('exment_file_share_fail_' . $link->id);
        for ($i = 0; $i < ShareLinks::PASSWORD_TRIES; $i++) {
            $this->assertFalse(ShareLinks::unlock($link, 'wrong'));
        }
        // the right one does not open it while it is stopped
        $this->assertTrue(ShareLinks::isLocked($link));
        $this->assertFalse(ShareLinks::unlock($link, 'right-12'));
        \Illuminate\Support\Facades\Cache::forget('exment_file_share_fail_' . $link->id);
        $this->assertTrue(ShareLinks::unlock($link, 'right-12'));
    }

    public function testTheLimitsOfARequestLink(): void
    {
        [$ws, $folder] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $folder, FileShareLink::KIND_REQUEST);
        $json = ['Accept' => 'application/json'];

        // a type it does not take
        $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('run.exe', 'MZ')]], $json)->assertStatus(415);

        // the count and the bytes of the link
        config(['exment.file_server_request_max_files' => 2, 'exment.file_server_request_max_bytes' => 10]);
        $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('a.txt', '12345')]], $json)->assertOk();
        $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('big.txt', '1234567')]], $json)->assertStatus(413);
        $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('b.txt', '12345')]], $json)->assertOk();
        $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('c.txt', '1')]], $json)->assertStatus(413);
        $link->refresh();
        $this->assertSame(2, $link->received_count);
        $this->assertSame(10, $link->received_bytes);

        // the size of one file
        config(['exment.file_server_request_max_files' => 0, 'exment.file_server_request_max_bytes' => 0, 'exment.file_server_request_max_file_size' => 3]);
        $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('d.txt', '1234')]], $json)->assertStatus(413);

        // the preview of a file from outside waits until a manager asks for it (ISS00433), not when it is opened
        $received = FileNode::query()->where('parent_id', $folder->id)->where('name', 'a.txt')->firstOrFail();
        $this->assertSame(\Exceedone\Exment\Model\FileNodeVersion::RENDITION_HELD, $received->current_version->rendition_status);
        \Exceedone\Exment\Model\System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $received->id), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertSame(\Exceedone\Exment\Model\FileNodeVersion::RENDITION_HELD, $received->refresh()->current_version->rendition_status);
        $this->post(admin_urls('file_server', $ws->id, 'nodes', $received->id, 'preview'), [], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertNotSame(\Exceedone\Exment\Model\FileNodeVersion::RENDITION_HELD, $received->refresh()->current_version->rendition_status);
    }

    public function testAFileFromALinkStaysHeldWhenCopiedOrGivenANewVersion(): void
    {
        [$ws, $folder] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $folder, FileShareLink::KIND_REQUEST);
        $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('held.txt', 'from outside')]], ['Accept' => 'application/json'])->assertOk();
        $received = FileNode::query()->where('parent_id', $folder->id)->where('name', 'held.txt')->firstOrFail();
        $held = \Exceedone\Exment\Model\FileNodeVersion::RENDITION_HELD;
        $this->assertSame($held, $received->current_version->rendition_status);
        $first = $received->current_version;

        $lib = $this->admin();
        // a copy, a new version, a restore: still held
        $copy = $lib->copy($ws, $received, null);
        $this->assertSame($held, $copy->current_version->rendition_status);
        $lib->addVersion($ws, $received, $this->local('again from inside'));
        $this->assertSame($held, $received->refresh()->current_version->rendition_status);
        $lib->restoreVersion($ws, $received, $first);
        $this->assertSame($held, $received->refresh()->current_version->rendition_status);

        // a file that was never held is not held by a copy or a new version
        $file = FileNode::query()->where('parent_id', $folder->id)->where('name', 'hello.txt')->firstOrFail();
        $this->assertNotSame($held, $lib->copy($ws, $file, null)->current_version->rendition_status);
        $lib->addVersion($ws, $file, $this->local('hello again'));
        $this->assertNotSame($held, $file->refresh()->current_version->rendition_status);
    }

    public function testAShareLinkStopsGivingFilesWhenTheRuleOfDownloadIsRaised(): void
    {
        [$ws, , $file] = $this->sample();
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER2, Level::EDIT);
        $link = $this->links($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER2))->create($ws, $file, FileShareLink::KIND_SHARE, ['allow_download' => true]);
        $this->assertTrue(ShareLinks::downloadAllowed($link, $file));
        $this->get($this->shareUrl($link, 'download') . '?node=' . $file->id)->assertOk();

        // the workspace now lets only those who manage it download: the link made before follows
        $ws->download_level = Level::MANAGE;
        $ws->save();
        $link = $link->fresh();
        $this->assertNotNull(ShareLinks::resolve(strval($link->plainToken())), 'the link still opens');
        $this->assertFalse(ShareLinks::downloadAllowed($link, $file));
        $this->get($this->shareUrl($link, 'download') . '?node=' . $file->id)->assertStatus(403);
    }

    public function testTheTokenIsKeptHashed(): void
    {
        [$ws, , $file] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $file, FileShareLink::KIND_SHARE);
        $plain = strval($link->plainToken());
        $this->assertSame(ShareLinks::TOKEN_LENGTH, strlen($plain));

        $row = \DB::table('file_share_links')->where('id', $link->id)->first();
        $this->assertSame(hash('sha256', $plain), $row->token, 'only the sha256 is kept');
        $this->assertStringNotContainsString($plain, strval($row->token_encrypted), 'the copy is encrypted');
        $this->assertNotNull(ShareLinks::resolve($plain));
        $this->assertNull(ShareLinks::resolve($row->token), 'the kept value does not open it');
        $this->assertStringEndsWith('/' . $plain, strval(FileShareLink::query()->findOrFail($link->id)->url()));

        // a link made before: the migration moves its token, and it still opens
        $legacy = Str::random(ShareLinks::TOKEN_LENGTH);
        \DB::table('file_share_links')->where('id', $link->id)->update(['token' => $legacy, 'token_encrypted' => null]);
        (require base_path('vendor/exceedone/exment/database/migrations/2026_11_30_000000_hash_file_share_link_tokens.php'))->up();
        $this->assertSame(hash('sha256', $legacy), \DB::table('file_share_links')->where('id', $link->id)->value('token'));
        $this->assertNotNull(ShareLinks::resolve($legacy));
        $this->assertStringEndsWith('/' . $legacy, strval(FileShareLink::query()->findOrFail($link->id)->url()));
    }

    public function testTheUrlIsShownToItsMakerAndToWhoManages(): void
    {
        [$ws, , $file] = $this->sample();
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER2, Level::EDIT);
        $this->links($this->admin())->create($ws, $file, FileShareLink::KIND_SHARE);
        $rows = function (string $loginId) use ($ws, $file) {
            $this->be(LoginUser::findOrFail($loginId));
            \Exceedone\Exment\Model\System::clearRequestSession();
            $method = new \ReflectionMethod(\Exceedone\Exment\Controllers\FileServerController::class, 'linkRows');
            $method->setAccessible(true);
            return $method->invoke(app(\Exceedone\Exment\Controllers\FileServerController::class), $ws, $file);
        };
        $this->assertNotNull($rows(TestDefine::TESTDATA_USER_LOGINID_ADMIN)[0]['url'] ?? null, 'its maker');
        $editor = $rows(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertCount(1, $editor, 'an editor sees the link');
        $this->assertNull($editor[0]['url'], 'but not its url');
    }

    public function testAZipHasALimit(): void
    {
        [$ws, $folder] = $this->sample();
        config(['exment.file_server_zip_max_bytes' => 5]);
        try {
            $this->admin()->zip($ws, [$folder]);
            $this->fail('a zip past the limit');
        } catch (FileServerException $e) {
            $this->assertSame(413, $e->status());
        }

        $old = Library::tempPath('old_');
        file_put_contents($old, 'x');
        touch($old, time() - 2 * 86400);
        $new = Library::tempPath('new_');
        file_put_contents($new, 'x');
        $this->locals[] = $new;
        Library::removeOldTempFiles();
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
    }

    public function testTheTypesARequestLinkTakes(): void
    {
        [$ws, $folder] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $folder, FileShareLink::KIND_REQUEST);
        $json = ['Accept' => 'application/json'];

        // a dot or a space at the end is still the type (Windows drops them)
        foreach (['run.exe.', "run.exe\u{3000}", "shell.php. . ", "run.exe\u{00A0}", 'page.html', 'img.svg', 'm.xlsm', 'disk.iso'] as $name) {
            $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent($name, 'x')]], $json)
                ->assertStatus(415);
        }
        $this->assertSame(['hello.txt'], $this->admin()->children($ws, $folder)->pluck('name')->all());

        // only the types the site allows
        config(['exment.file_server_request_allow_extensions' => 'pdf, .jpg']);
        $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]], $json)->assertStatus(415);
        $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('noext', 'x')]], $json)->assertStatus(415);
        $this->post($this->shareUrl($link, 'upload'), ['files' => [UploadedFile::fake()->createWithContent('a.PDF', '%PDF')]], $json)->assertOk();
    }

    public function testALinkClosesWhenItsFolderIsClosed(): void
    {
        [$ws, $folder] = $this->sample();
        $lib = $this->admin();
        $inner = $lib->createFolder($ws, $folder, 'Inner');
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::EDIT);
        $links = $this->links($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $onFolder = $links->create($ws, $folder, FileShareLink::KIND_SHARE);
        $onInner = $links->create($ws, $inner, FileShareLink::KIND_SHARE);
        $this->assertSame($this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), $onFolder->created_user_id);
        $this->get($this->shareUrl($onFolder))->assertOk();
        $this->get($this->shareUrl($onInner))->assertOk();

        // the folder of the links gets its own permissions without the maker
        $lib->breakInheritance($ws, $folder);
        $lib->setPermissions($ws, $folder, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'level' => Level::MANAGE]]);
        $this->flushSession();
        $this->get($this->shareUrl($onFolder))->assertStatus(404);
        // and the link of a folder in it
        $this->get($this->shareUrl($onInner))->assertStatus(404);

        // a link of the admin stays
        $mine = $this->links($lib)->create($ws, $folder, FileShareLink::KIND_SHARE);
        $this->get($this->shareUrl($mine))->assertOk();
    }

    public function testOneZipAtATime(): void
    {
        [$ws, $folder] = $this->sample();
        $link = $this->links($this->admin())->create($ws, $folder, FileShareLink::KIND_SHARE);
        // one zip at a time for a link; another link of the workspace is not stopped by it (ISS00433)
        $other = $this->links($this->admin())->create($ws, $folder, FileShareLink::KIND_SHARE);
        $lock = \Illuminate\Support\Facades\Cache::lock('exment_file_server_zip_link_' . $link->id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->get($this->shareUrl($link, 'download'))->assertStatus(429);
            $this->get($this->shareUrl($other, 'download'))->assertOk();
        } finally {
            $lock->release();
        }
        $this->get($this->shareUrl($link, 'download'))->assertOk();
        // and a few for all the links at a time
        $slots = [];
        for ($i = 0; $i < \Exceedone\Exment\Services\FileServer\Library::LINK_ZIP_SLOTS; $i++) {
            $slots[$i] = \Illuminate\Support\Facades\Cache::lock('exment_file_server_zip_links_' . $i, 60);
            $this->assertTrue($slots[$i]->get());
        }
        try {
            $this->get($this->shareUrl($other, 'download'))->assertStatus(429);
        } finally {
            foreach ($slots as $slot) {
                $slot->release();
            }
        }

        // a failed zip leaves nothing
        $before = glob(storage_path('app/file_server_tmp/zip_*')) ?: [];
        config(['exment.file_server_zip_max_bytes' => 0]);
        Storage::disk(Define::DISKNAME_ADMIN)->delete(FileNode::query()->where('name', 'hello.txt')->firstOrFail()->current_version->path);
        try {
            $this->admin()->zip($ws, [$folder]);
        } catch (\Throwable $e) {
            // the bytes of the file are not there
        }
        $this->assertSame(count($before), count(glob(storage_path('app/file_server_tmp/zip_*')) ?: []));
    }

    public function testThePixelsOfAThumbnail(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            $this->markTestSkipped('no GD');
        }
        $path = Library::tempPath('px_') . '.png';
        $im = imagecreatetruecolor(200, 100);
        imagepng($im, $path);
        imagedestroy($im);
        $this->locals[] = $path;

        config(['exment.file_server_preview_max_pixels' => 19999]);
        $this->assertNull(\Exceedone\Exment\Services\FileServer\Preview::imageThumbnail($path));
        config(['exment.file_server_preview_max_pixels' => 0]);
        /** @var string|null $thumb (null without GD) */
        $thumb = \Exceedone\Exment\Services\FileServer\Preview::imageThumbnail($path);
        $this->assertTrue(is_string($thumb) && is_file($thumb));
        $this->locals[] = strval($thumb);
    }
}
