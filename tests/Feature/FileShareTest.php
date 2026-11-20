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
        return '/' . ShareLinks::basePath() . '/' . $link->token . ($path === '' ? '' : '/' . $path);
    }

    protected function links(Library $library): ShareLinks
    {
        return new ShareLinks($library);
    }

    public function testWhoMayMakeALink(): void
    {
        [$ws, $folder, $file] = $this->sample();
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::UPLOAD);
        $uploader = $this->links($this->libraryFor(TestDefine::TESTDATA_USER_LOGINID_USER1));

        // an uploader may make a request link for a folder, not a share link
        $this->assertSame(FileShareLink::KIND_REQUEST, $uploader->create($ws, $folder, FileShareLink::KIND_REQUEST)->kind);
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

        config(['exment.file_server_share_max_days' => 7]);
        try {
            $links->create($ws, $file, FileShareLink::KIND_SHARE);
            $this->fail('a link without an end date');
        } catch (FileServerException $e) {
            $this->assertStringContainsString('7', $e->getMessage());
        }
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
}
