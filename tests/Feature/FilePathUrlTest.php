<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FilePermission;
use Exceedone\Exment\Model\FileWorkspace;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\FileServer\Access;
use Exceedone\Exment\Services\FileServer\Level;
use Exceedone\Exment\Services\FileServer\Library;
use Exceedone\Exment\Services\FileServer\Paths;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * File server (ISS02815): the pages of the folders and files at their paths,
 * /file_server/<workspace>/<folder>/.../<file>; the addresses with numbers and
 * the old paths lead there; what may not be seen is not there.
 */
class FilePathUrlTest extends FeatureTestBase
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

    protected function admin(): Library
    {
        return new Library(new Access($this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), true));
    }

    protected function local(string $content): string
    {
        $path = Library::tempPath('test_');
        file_put_contents($path, $content);
        $this->locals[] = $path;
        return $path;
    }

    /**
     * A workspace with a name of its own (the database of the site may have others).
     */
    protected function workspace(string $name = '社内共有'): FileWorkspace
    {
        return $this->admin()->createWorkspace(['name' => $name . ' ' . uniqid()]);
    }

    protected function loginAs(string $loginId): void
    {
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail($loginId));
    }

    protected function addMember(FileWorkspace $ws, string $loginId, int $level): void
    {
        FilePermission::create([
            'workspace_id' => $ws->id, 'node_id' => null,
            'target_type' => FilePermission::TARGET_USER, 'target_id' => $this->userId($loginId), 'level' => $level,
        ]);
    }

    /**
     * @param string[] $names
     */
    protected function pathUrl(FileWorkspace $ws, array $names = []): string
    {
        return admin_url('file_server/' . implode('/', array_map('rawurlencode', array_merge([strval($ws->name)], $names))));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function page(string $url): TestResponse
    {
        return $this->get($url, ['X-Requested-With' => 'XMLHttpRequest', 'X-PJAX' => 'true']);
    }

    /**
     * Asked by fetch(), not pjax (pjax shows the error pages with 200).
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function fetch(string $url): TestResponse
    {
        return $this->get($url, ['X-Requested-With' => 'XMLHttpRequest']);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function location(TestResponse $response): string
    {
        return strval($response->headers->get('Location'));
    }

    public function testThePagesAreAtTheirPathsWithAnyName(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $sales = $lib->createFolder($ws, null, '営業部');
        $year = $lib->createFolder($ws, $sales, '2026 年度 #1 & 100%');
        $file = $lib->storeFile($ws, $year, '見積書 (案) +α.txt', $this->local('見積の本文'));
        // deep, with long names (an address of about 1,900 characters)
        $deep = $year;
        $names = ['営業部', '2026 年度 #1 & 100%'];
        for ($i = 0; $i < 8; $i++) {
            $name = str_repeat('長', 20) . $i;
            $deep = $lib->createFolder($ws, $deep, $name);
            $names[] = $name;
        }
        $last = $lib->storeFile($ws, $deep, 'last.txt', $this->local('the deepest'));
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $paths = new Paths();
        $this->assertSame($this->pathUrl($ws, ['営業部', '2026 年度 #1 & 100%', '見積書 (案) +α.txt']), $paths->url($ws, $file));
        $this->assertStringContainsString('/2026%20%E5%B9%B4%E5%BA%A6%20%231%20%26%20100%25/', strval($paths->url($ws, $file)));

        $this->page($this->pathUrl($ws))->assertOk()->assertSee('営業部');
        $this->page($this->pathUrl($ws, ['営業部']))->assertOk()->assertSee('2026 年度 #1 &amp; 100%', false);
        $this->page($this->pathUrl($ws, ['営業部', '2026 年度 #1 & 100%']))->assertOk()->assertSee('見積書 (案) +α.txt');
        $this->page($this->pathUrl($ws, ['営業部', '2026 年度 #1 & 100%', '見積書 (案) +α.txt']))->assertOk()->assertSee('見積の本文');
        $this->page($this->pathUrl($ws, array_merge($names, ['last.txt'])))->assertOk()->assertSee('the deepest');
        // the links of the pages are the paths
        $this->page($this->pathUrl($ws, ['営業部']))->assertSee('href="' . e(strval($paths->url($ws, $year))) . '"', false);
        $this->assertSame(strval($paths->url($ws, $last)), $this->pathUrl($ws, array_merge($names, ['last.txt'])));

        // a path too long for an address (the web servers refuse it): the number, not moved
        $longer = $lib->createFolder($ws, $deep, str_repeat('長', 200));
        $far = $lib->storeFile($ws, $longer, 'far.txt', $this->local('far away'));
        $this->assertNull((new Paths())->url($ws, $far));
        $this->assertSame(admin_urls('file_server', $ws->id, 'nodes', $far->id), (new Paths())->link($ws, $far));
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $far->id))->assertOk()->assertSee('far away');
    }

    // 302, not 301: where it leads changes (names, permissions); the number always opens it
    public function testTheAddressesWithNumbersMoveToThePaths(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $folder = $lib->createFolder($ws, null, '営業部');
        $file = $lib->storeFile($ws, $folder, '見積書.txt', $this->local('x'));
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $top = $this->get(admin_urls('file_server', $ws->id));
        $top->assertStatus(302);
        $this->assertSame($this->pathUrl($ws), $this->location($top));

        $list = $this->get(admin_urls('file_server', $ws->id, 'folder', $folder->id) . '?sort=size');
        $list->assertStatus(302);
        $this->assertSame($this->pathUrl($ws, ['営業部']) . '?n=' . $folder->id . '&sort=size', $this->location($list));

        $page = $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id));
        $page->assertStatus(302);
        $this->assertSame($this->pathUrl($ws, ['営業部', '見積書.txt']) . '?n=' . $file->id, $this->location($page));
        $this->followingRedirects()->get(admin_urls('file_server', $ws->id, 'nodes', $file->id))->assertOk()->assertSee('見積書.txt');

        // pjax follows the move; fetch() of the page (not pjax) is answered there
        $this->page(admin_urls('file_server', $ws->id, 'nodes', $file->id))->assertStatus(302);
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        // the details of a folder stay there (its path is its list)
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $folder->id))->assertOk();
    }

    public function testAnOldPathLeadsToTheItemAfterARenameOrAMove(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $a = $lib->createFolder($ws, null, 'A');
        $b = $lib->createFolder($ws, $a, 'B');
        $other = $lib->createFolder($ws, null, 'Other');
        $file = $lib->storeFile($ws, $b, 'c.txt', $this->local('see me'));
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        // the file renamed
        $lib->rename($ws, $file, 'd.txt');
        $moved = $this->page($this->pathUrl($ws, ['A', 'B', 'c.txt']));
        $moved->assertStatus(302);
        $this->assertSame($this->pathUrl($ws, ['A', 'B', 'd.txt']), $this->location($moved));

        // a folder above it renamed, then the one under it: the old paths of both ways
        $lib->rename($ws, $a, 'A2');
        $lib->rename($ws, $b->refresh(), 'B2');
        $now = $this->pathUrl($ws, ['A2', 'B2', 'd.txt']);
        $this->assertSame($now, $this->location($this->page($this->pathUrl($ws, ['A', 'B', 'c.txt']))));
        $this->assertSame($now, $this->location($this->page($this->pathUrl($ws, ['A', 'B', 'd.txt']))));
        $this->assertSame($now, $this->location($this->page($this->pathUrl($ws, ['A2', 'B', 'd.txt']))));
        // the query stays
        $this->assertSame($this->pathUrl($ws, ['A2', 'B2']) . '?sort=size', $this->location($this->page($this->pathUrl($ws, ['A', 'B']) . '?sort=size')));

        // moved to another folder
        $lib->move($ws, $file->refresh(), $other);
        $this->assertSame($this->pathUrl($ws, ['Other', 'd.txt']), $this->location($this->page($this->pathUrl($ws, ['A', 'B', 'c.txt']))));
        $this->followingRedirects()->get($this->pathUrl($ws, ['A', 'B', 'c.txt']))->assertOk()->assertSee('see me');

        // another case, or the NFD form of a Mac: to the name as it is
        $this->assertSame($this->pathUrl($ws, ['Other', 'd.txt']), $this->location($this->page($this->pathUrl($ws, ['other', 'D.TXT']))));
    }

    public function testANewItemAtAnOldPathWins(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $old = $lib->storeFile($ws, null, 'plan.txt', $this->local('the old one'));
        $lib->rename($ws, $old, 'plan-2025.txt');
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->page($this->pathUrl($ws, ['plan.txt']))->assertStatus(302);

        $new = $lib->storeFile($ws, null, 'plan.txt', $this->local('the new one'));
        $this->page($this->pathUrl($ws, ['plan.txt']))->assertOk()->assertSee('the new one');
        $this->assertDatabaseMissing(Paths::TABLE, ['workspace_id' => $ws->id, 'node_id' => $old->id]);

        // the new one gone: the path is not the old one's any more
        $lib->trash($ws, $new);
        $this->fetch($this->pathUrl($ws, ['plan.txt']))->assertNotFound();
        // the stable number still opens the old one
        $this->page($this->pathUrl($ws, ['plan.txt']) . '?n=' . $old->id)->assertStatus(302);
        $this->followingRedirects()->get($this->pathUrl($ws, ['plan.txt']) . '?n=' . $old->id)->assertOk()->assertSee('the old one');
    }

    public function testTheOldNameOfTheWorkspaceLeadsToIt(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace('営業');
        $before = strval($ws->name);
        $lib->storeFile($ws, null, 'a.txt', $this->local('in the workspace'));
        $lib->updateWorkspace($ws, ['name' => '営業本部 ' . uniqid()]);
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $old = admin_url('file_server/' . rawurlencode($before) . '/a.txt');
        $this->assertSame($this->pathUrl($ws->refresh(), ['a.txt']), $this->location($this->page($old)));
        $this->page($this->pathUrl($ws))->assertOk()->assertSee('a.txt');
    }

    public function testTheLinkToCopyHasTheNameAndTheNumber(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $folder = $lib->createFolder($ws, null, '営業部');
        $file = $lib->storeFile($ws, $folder, '見積書.txt', $this->local('x'));
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $paths = new Paths();
        $fileLink = $paths->url($ws, $file) . '?n=' . $file->id;
        $this->assertSame($fileLink, $paths->link($ws, $file));

        // the page of the folder: its own link, and one on each row
        $this->page($this->pathUrl($ws, ['営業部']))->assertOk()
            ->assertSee('data-fs-copy-link="' . e($paths->url($ws, $folder) . '?n=' . $folder->id) . '" data-fs-copy-name="営業部"', false)
            ->assertSee('data-fs-copy-link="' . e($fileLink) . '" data-fs-copy-name="見積書.txt"', false);
        // the page of the file
        $this->page($this->pathUrl($ws, ['営業部', '見積書.txt']))->assertOk()
            ->assertSee('data-fs-copy-link="' . e($fileLink) . '"', false)
            ->assertSee(exmtrans('file_server.copy_link'));

        // the number opens it after a rename
        $lib->rename($ws, $file, '見積書（確定）.txt');
        $answer = $this->page($fileLink);
        $answer->assertStatus(302);
        $this->assertSame($this->pathUrl($ws, ['営業部', '見積書（確定）.txt']) . '?n=' . $file->id, $this->location($answer));
    }

    public function testWhatMayNotBeSeenIsTheSameAsWhatIsNotThere(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $open = $lib->createFolder($ws, null, 'Open');
        $secret = $lib->createFolder($ws, null, 'Secret');
        $hidden = $lib->storeFile($ws, $secret, 'plan.txt', $this->local('secret'));
        $lib->storeFile($ws, $open, 'note.txt', $this->local('open'));
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::VIEW);
        $lib->breakInheritance($ws, $secret);
        $lib->setPermissions($ws, $secret, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'level' => Level::MANAGE]]);
        $lib->rename($ws, $hidden, 'plan-v2.txt');
        $other = $this->workspace('他の部署');
        $lib->storeFile($other, null, 'x.txt', $this->local('other'));

        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->page($this->pathUrl($ws, ['Open', 'note.txt']))->assertOk();

        $missing = $this->fetch($this->pathUrl($ws, ['Secret', 'nothing.txt']));
        $missing->assertNotFound();
        foreach ([
            $this->pathUrl($ws, ['Secret']),
            $this->pathUrl($ws, ['Secret', 'plan-v2.txt']),
            // an old path is not moved (the new name is not told)
            $this->pathUrl($ws, ['Secret', 'plan.txt']),
            // nor the number
            $this->pathUrl($ws, ['Open']) . '?n=' . $hidden->id,
            $this->pathUrl($ws, ['Open']) . '?n=abc',
            // a workspace the user is not in
            $this->pathUrl($other),
            $this->pathUrl($other, ['x.txt']),
        ] as $url) {
            $answer = $this->fetch($url);
            $answer->assertNotFound();
            $this->assertSame($missing->getContent(), $answer->getContent(), $url);
            $this->assertNull($answer->headers->get('Location'), $url);
        }
    }

    public function testUnderAFolderThatMayNotBeSeenTheNumberStays(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $secret = $lib->createFolder($ws, null, '人事');
        $shared = $lib->createFolder($ws, $secret, '共有');
        $file = $lib->storeFile($ws, $shared, 'memo.txt', $this->local('given to user1'));
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::VIEW);
        $lib->breakInheritance($ws, $secret);
        $lib->setPermissions($ws, $secret, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'level' => Level::MANAGE]]);
        $lib->breakInheritance($ws, $shared);
        $lib->setPermissions($ws, $shared, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'level' => Level::VIEW]]);

        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user1 = new Paths(new Access($this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), false));
        // the path would tell the name of the folder above (ISS00433): the number
        $this->assertNull($user1->url($ws, $file));
        $this->assertSame(admin_urls('file_server', $ws->id, 'nodes', $file->id), $user1->link($ws, $file));
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id))->assertOk()->assertSee('given to user1')->assertDontSee('人事');
        $this->fetch($this->pathUrl($ws, ['人事', '共有', 'memo.txt']))->assertNotFound();
        $this->fetch($this->pathUrl($ws, ['Open']) . '?n=' . $file->id)->assertNotFound();

        // the administrator sees it all: the path
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->page($this->pathUrl($ws, ['人事', '共有', 'memo.txt']))->assertOk()->assertSee('given to user1');
    }

    public function testAWorkspaceWhoseNameCannotBeInAnAddressKeepsTheNumbers(): void
    {
        $lib = $this->admin();
        $digits = $lib->createWorkspace(['name' => '2026']);
        $reserved = $lib->createWorkspace(['name' => 'search']);
        // a page of the routes under file_server/ (not in the list)
        $route = $lib->createWorkspace(['name' => 'Picker']);
        $slash = $lib->createWorkspace(['name' => 'A/B']);
        $php = $lib->createWorkspace(['name' => 'tools.php']);
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        foreach ([$digits, $reserved, $route, $slash, $php] as $ws) {
            $this->assertNull(Paths::segment($ws), strval($ws->name));
            $this->assertSame(admin_urls('file_server', $ws->id), (new Paths())->link($ws));
            $this->get(admin_urls('file_server', $ws->id))->assertOk();
        }
    }

    public function testTwoWorkspacesWithOneNameKeepTheNumbersAndAPathInOneOpens(): void
    {
        $lib = $this->admin();
        $name = 'Team ' . uniqid();
        $first = $lib->createWorkspace(['name' => $name]);
        $second = $lib->createWorkspace(['name' => $name]);
        $one = $lib->storeFile($first, null, 'one.txt', $this->local('in the first'));
        $lib->storeFile($second, null, 'two.txt', $this->local('in the second'));
        $lib->storeFile($first, null, 'both.txt', $this->local('x'));
        $lib->storeFile($second, null, 'both.txt', $this->local('y'));
        // an old name in the first, a name of now in the second: the one of now
        $old = $lib->storeFile($first, null, 'two.txt', $this->local('old'));
        $lib->rename($first, $old, 'renamed.txt');
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        // the links do not say which: the numbers, and no move from them
        $paths = new Paths();
        $this->assertNull($paths->url($second));
        $this->assertNull($paths->url($first, $one));
        $this->get(admin_urls('file_server', $second->id))->assertOk();
        // an address of the name, from before the second one: where the path is now
        $this->page($this->pathUrl($first, ['one.txt']))->assertStatus(302);
        $this->followingRedirects()->get($this->pathUrl($first, ['one.txt']))->assertOk()->assertSee('in the first');
        $this->followingRedirects()->get($this->pathUrl($second, ['two.txt']))->assertOk()->assertSee('in the second');
        // in both, or the top: not told which
        $this->fetch($this->pathUrl($first, ['both.txt']))->assertNotFound();
        $this->fetch($this->pathUrl($first))->assertNotFound();
    }

    public function testNamesTheWebServersKeepStayAtTheNumber(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $script = $lib->storeFile($ws, null, 'index.php', $this->local('<?php echo 1;'));
        $dot = $lib->createFolder($ws, null, '.config');
        $inDot = $lib->storeFile($ws, $dot, 'a.txt', $this->local('x'));
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $paths = new Paths();
        foreach ([$script, $dot, $inDot] as $node) {
            $this->assertNull($paths->url($ws, $node), strval($node->name));
        }
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $script->id))->assertOk();
    }

    // GitHub #2290: an address that ends in .png, .js ... is taken for a static file
    // (Exment did not add its CSS and JavaScript, the web servers' rules send the file)
    public function testAFileNamedLikeAStaticFileHasAPageWithTheCssOfExment(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $folder = $lib->createFolder($ws, null, 'images.png');
        $png = $lib->storeFile($ws, $folder, 'sample.png', $this->local('not really a png'));
        $js = $lib->storeFile($ws, null, 'app.JS', $this->local('var a = 1;'));
        $csv = $lib->storeFile($ws, null, 'list.csv', $this->local('a,b'));
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $paths = new Paths();
        $this->assertSame($this->pathUrl($ws, ['images.png', 'sample.png']) . '/~', $paths->url($ws, $png));
        $this->assertSame($this->pathUrl($ws, ['app.JS']) . '/~', $paths->url($ws, $js));
        // a folder (its list) and other files: as before
        $this->assertSame($this->pathUrl($ws, ['images.png']), $paths->url($ws, $folder));
        $this->assertSame($this->pathUrl($ws, ['list.csv']), $paths->url($ws, $csv));
        $this->assertStringEndsWith('/~?n=' . $png->id, $paths->link($ws, $png));

        // opened by its address (a reload, a new tab): the page of Exment, with its CSS
        $page = $this->get(strval($paths->url($ws, $png)));
        $page->assertOk()->assertSee('sample.png')->assertSee('vendor/exment/css/common.css', false);
        $this->get(strval($paths->url($ws, $js)))->assertOk()->assertSee('vendor/exment/css/common.css', false);
        // the address without the mark (links of before) and the number lead there
        $old = $this->get($this->pathUrl($ws, ['images.png', 'sample.png']));
        $old->assertStatus(302);
        $this->assertSame(strval($paths->url($ws, $png)), $this->location($old));
        $this->assertSame(strval($paths->url($ws, $png)) . '?n=' . $png->id, $this->location($this->get(admin_urls('file_server', $ws->id, 'nodes', $png->id))));
        // the mark after a folder or after nothing is not a page
        $this->assertSame(strval($paths->url($ws, $folder)), $this->location($this->get($this->pathUrl($ws, ['images.png']) . '/~')));
        $this->fetch($this->pathUrl($ws, ['nothing.png']) . '/~')->assertNotFound();
    }

    public function testANumberOfAnItemInTheTrashLeadsByThePath(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $folder = $lib->createFolder($ws, null, 'A');
        $old = $lib->storeFile($ws, $folder, 'report.txt', $this->local('old'));
        $link = (new Paths())->link($ws, $old);
        $lib->trash($ws, $old);
        $lib->storeFile($ws, $folder, 'report.txt', $this->local('the new report'));
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $this->page($link)->assertOk()->assertSee('the new report');
    }

    public function testThePageOfAFileDoesNotTellTheNameOfAFolderItIsIn(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $secret = $lib->createFolder($ws, null, '人事');
        $file = $lib->storeFile($ws, $secret, 'memo.txt', $this->local('given to user1'));
        $this->addMember($ws, TestDefine::TESTDATA_USER_LOGINID_USER1, Level::VIEW);
        $lib->breakInheritance($ws, $secret);
        $lib->setPermissions($ws, $secret, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'level' => Level::MANAGE]]);
        $lib->breakInheritance($ws, $file);
        $lib->setPermissions($ws, $file, [['target_type' => 'user', 'target_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'level' => Level::VIEW]]);

        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_urls('file_server', $ws->id, 'nodes', $file->id), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('given to user1')
            ->assertDontSee(rawurlencode('人事'))
            ->assertDontSee('人事');
    }

    public function testThePathsOfARemovedItemAreForgotten(): void
    {
        $lib = $this->admin();
        $ws = $this->workspace();
        $file = $lib->storeFile($ws, null, 'a.txt', $this->local('x'));
        $lib->rename($ws, $file, 'b.txt');
        $this->assertDatabaseHas(Paths::TABLE, ['workspace_id' => $ws->id, 'node_id' => $file->id, 'path' => 'a.txt']);

        $lib->trash($ws, $file->refresh());
        $lib->purge($ws, FileNode::findOrFail($file->id));
        $this->assertDatabaseMissing(Paths::TABLE, ['node_id' => $file->id]);
    }
}
