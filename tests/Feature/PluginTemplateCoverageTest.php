<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\TemplateExportTarget;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Plugin and template screens that no test reached: uploading a plugin, its PUT/PATCH/DELETE
 * and "execute batch" (admin/plugin), a public file of a style plugin
 * (admin/plugins/{plugin}/public/...) and the template export (admin/template/export).
 *
 * The uploaded plugin is a config.json only batch plugin: it is never loaded, and a batch
 * plugin is installed inactive. Its files land on the plugin disks outside the transaction,
 * so tearDown() removes them.
 */
class PluginTemplateCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const PLUGIN_NAME = 'ClaudeCoveragePlugin';
    protected const PLUGIN_UUID = 'f3c1a2b4-5d6e-4f70-8a9b-0c1d2e3f4a5b';

    /**
     * @var string|null
     */
    protected $zipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        if (isset($this->zipPath) && is_file($this->zipPath)) {
            unlink($this->zipPath);
        }
        foreach ([Define::DISKNAME_PLUGIN_SYNC, Define::DISKNAME_PLUGIN_LOCAL, Define::DISKNAME_ADMIN] as $disk) {
            if (Storage::disk($disk)->exists(self::PLUGIN_NAME)) {
                Storage::disk($disk)->deleteDirectory(self::PLUGIN_NAME);
            }
        }
        System::clearCache();
        parent::tearDown();
    }

    // PluginController (admin/plugin) ------------------------------------------------

    public function testPluginCreateScreenIsDenied(): void
    {
        // A plugin is only ever installed by uploading it (store), the list has no create
        // button: the create route of the resource builds the edit form for no plugin, which
        // answers "not found or denied" - so only the rejection exists here. Sent as ajax:
        // the same denial without it ends the process (Pjax::respond() -> exit).
        $this->get(admin_url('plugin/create'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertForbidden();
    }

    public function testPluginUploadUpdateAndDelete(): void
    {
        $upload = UploadedFile::fake()->createWithContent(self::PLUGIN_NAME . '.zip', $this->pluginZip());

        $this->from(admin_url('plugin'))
            ->post(admin_url('plugin'), ['fileUpload' => $upload])
            ->assertRedirect(admin_url('plugin'))
            ->assertSessionMissing('errorMess');

        $plugin = Plugin::query()->where('plugin_name', self::PLUGIN_NAME)->firstOrFail();
        $this->assertSame(self::PLUGIN_UUID, $plugin->uuid);
        $this->assertSame('Claude coverage plugin', $plugin->plugin_view_name);
        $this->assertFalse(boolval($plugin->active_flg), 'a batch plugin is installed inactive');

        $this->put(admin_url("plugin/{$plugin->id}"), ['active_flg' => '1'])
            ->assertRedirect(admin_url('plugin'))
            ->assertSessionHasNoErrors();
        $this->assertTrue(boolval(Plugin::query()->where('id', $plugin->id)->firstOrFail()->active_flg));

        $this->patch(admin_url("plugin/{$plugin->id}"), ['active_flg' => '0'])
            ->assertRedirect(admin_url('plugin'))
            ->assertSessionHasNoErrors();
        $this->assertFalse(boolval(Plugin::query()->where('id', $plugin->id)->firstOrFail()->active_flg));

        $this->delete(admin_url("plugin/{$plugin->id}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);
        $this->assertDatabaseMissing('plugins', ['id' => $plugin->id]);
    }

    public function testExecuteBatchRunsTheBatchPlugin(): void
    {
        // TestPluginBatch deletes the trashed records of every table for good (PluginTest::testEventBatch)
        $plugin = Plugin::query()->where('plugin_name', 'TestPluginBatch')->firstOrFail();
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $table->getValueModel(3)->delete();
        $this->assertNotNull($table->getValueModel()->withTrashed()->find(3));

        $this->from(admin_url('plugin'))
            ->get(admin_url("plugin/{$plugin->id}/executeBatch"))
            ->assertRedirect(admin_url('plugin'))
            ->assertSessionHas('toastr');

        $this->assertNull($table->getValueModel()->withTrashed()->find(3), 'the batch ran');
    }

    // PluginPageController (admin/plugins/{plugin}/public/...) -------------------------

    public function testStylePluginServesItsPublicFile(): void
    {
        $response = $this->get(admin_url('plugins/test_plugin_style/public/style.css'))->assertOk();

        $this->assertStringStartsWith('text/css', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('#manual_link', (string) $response->getContent());
    }

    // TemplateController (admin/template/export) ----------------------------------------

    public function testTemplateExportDownloadsTheSelectedTables(): void
    {
        $response = $this->post(admin_url('template/export'), [
            'template_name' => 'claude_cov_template',
            'template_view_name' => 'Claude coverage template',
            'description' => 'exported by the coverage test',
            'export_target' => [TemplateExportTarget::TABLE],
            'target_tables' => [TestDefine::TESTDATA_TABLE_NAME_EDIT],
        ])->assertOk()->assertDownload('claude_cov_template.zip');

        $download = $response->baseResponse;
        if (!$download instanceof BinaryFileResponse) {
            $this->fail('the export answers a file download');
        }
        $this->zipPath = $download->getFile()->getPathname();

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->zipPath) === true, 'the download is a zip file');
        $config = json_decode((string) $zip->getFromName('config.json'), true);
        $zip->close();

        $this->assertIsArray($config);
        $this->assertSame('claude_cov_template', $config['template_name'] ?? null);
        $this->assertSame('Claude coverage template', $config['template_view_name'] ?? null);
        $this->assertSame([TestDefine::TESTDATA_TABLE_NAME_EDIT], array_column($config['custom_tables'] ?? [], 'table_name'));
    }

    /**
     * A plugin zip holding only its config.json.
     */
    protected function pluginZip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'claude_plugin');
        $this->assertIsString($path);
        $this->zipPath = $path;

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('config.json', (string) json_encode([
            'uuid' => self::PLUGIN_UUID,
            'plugin_name' => self::PLUGIN_NAME,
            'plugin_view_name' => 'Claude coverage plugin',
            'description' => 'uploaded by the coverage test',
            'author' => 'Exceedone',
            'version' => '1.0.0',
            'plugin_type' => 'batch',
        ]));
        $zip->close();

        return (string) file_get_contents($path);
    }
}
