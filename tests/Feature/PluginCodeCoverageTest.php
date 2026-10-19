<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;

/**
 * PluginCodeController::fileupload/store/delete (admin/plugin/edit_code/{id}/...).
 * These write real files to the plugin's own disk (outside the DB transaction), so
 * the test creates its own throwaway file under the existing TestPluginButton
 * fixture, rewrites it, then deletes it - never touching the plugin's real
 * Plugin.php - and tearDown() removes it too in case an assertion fails first.
 */
class PluginCodeCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const TEST_FILE_NAME = 'claude_coverage_tmp.txt';
    private const TEST_FILE_PATH = '/' . self::TEST_FILE_NAME;

    /** @var Plugin|null */
    private $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->plugin = Plugin::where('plugin_name', 'TestPluginButton')->first();
        $this->assertNotNull($this->plugin, 'seed data must install the TestPluginButton fixture');
    }

    protected function tearDown(): void
    {
        if (isset($this->plugin)) {
            try {
                $this->plugin->deletePluginFile(self::TEST_FILE_PATH);
            } catch (\Exception $e) {
                // already gone - the test's own testDelete() already removed it
            }
        }
        parent::tearDown();
    }

    /**
     * @param string $path jstree node 'path' to find, e.g. "/" or "/claude_coverage_tmp.txt"
     * @return string the node id
     */
    protected function treeNodeId(string $path): string
    {
        // getTreeData() caches the jstree json in the session: without clearing
        // it first, a second call after fileupload() would still return the
        // tree from before the new file existed.
        session()->forget(Define::SYSTEM_KEY_SESSION_FILE_NODELIST);

        $json = $this->get(admin_url("plugin/edit_code/{$this->plugin->id}/getTree"))
            ->assertStatus(200)
            ->json();

        foreach ($json as $node) {
            if ($node['path'] === $path) {
                return $node['id'];
            }
        }

        $this->fail("jstree node not found for path $path");
    }

    public function testFileuploadStoreAndDeleteRoundTrip(): void
    {
        $rootNodeId = $this->treeNodeId('/');

        // 1. fileupload: create the throwaway file in the plugin's root folder.
        $this->post(admin_url("plugin/edit_code/{$this->plugin->id}/fileupload"), [
            'nodeid' => $rootNodeId,
            'fileUpload' => [UploadedFile::fake()->createWithContent(self::TEST_FILE_NAME, 'hello from claude')],
        ])->assertRedirect();
        $this->assertSame('hello from claude', $this->plugin->getPluginFiledata(self::TEST_FILE_PATH));

        // 2. store: rewrite the same file's content through the code editor.
        $fileNodeId = $this->treeNodeId(self::TEST_FILE_PATH);
        $this->post(admin_url("plugin/edit_code/{$this->plugin->id}"), [
            'nodeid' => $fileNodeId,
            'edit_file' => 'edited by claude coverage test',
        ])->assertStatus(200)->assertJson(['result' => true]);
        $this->assertSame('edited by claude coverage test', $this->plugin->getPluginFiledata(self::TEST_FILE_PATH));

        // 3. delete: remove it again - back to a clean plugin folder.
        $this->delete(admin_url("plugin/edit_code/{$this->plugin->id}"), [
            'nodeid' => $fileNodeId,
        ])->assertStatus(200)->assertJson(['result' => true]);

        $this->expectException(\Exception::class);
        $this->plugin->getPluginFiledata(self::TEST_FILE_PATH);
    }
}
