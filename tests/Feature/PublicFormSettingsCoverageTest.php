<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Form\Builder;
use ExmentAdminCore\Admin\Form\Footer;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public form settings (admin/formpublic/{tableKey}) that no test reached: the previews of new
 * and saved settings, deleting the header logo, the template export and the PATCH. The seeder
 * gives the default form of every table two active public forms.
 *
 * A preview builds the public form, and PublicForm::getForm() switches the static footer class
 * of admin-core forms to the public form footer for the rest of the process: tearDown() puts
 * the admin footer back so the forms of later tests are drawn as usual.
 */
class PublicFormSettingsCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /**
     * @var string|null file stored on the upload disk by a test
     */
    protected $storedPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        Builder::$footerClassName = Footer::class;
        if (isset($this->storedPath) && Storage::disk(config('admin.upload.disk'))->exists($this->storedPath)) {
            Storage::disk(config('admin.upload.disk'))->delete($this->storedPath);
        }
        System::clearCache();
        parent::tearDown();
    }

    public function testPreviewOfNewSettings(): void
    {
        $table = $this->table();

        $this->post(admin_url("formpublic/{$table->table_name}/preview"), $this->previewPayload('Claude public preview (post)'))
            ->assertOk()
            ->assertSee('Claude public preview (post)')
            ->assertSee('name="value[index_text]"', false);
    }

    public function testPreviewOfNewSettingsByPut(): void
    {
        $table = $this->table();

        $this->put(admin_url("formpublic/{$table->table_name}/preview"), $this->previewPayload('Claude public preview (put)'))
            ->assertOk()
            ->assertSee('Claude public preview (put)')
            ->assertSee('name="value[index_text]"', false);
    }

    public function testPreviewOfASavedForm(): void
    {
        $table = $this->table();
        $publicForm = $this->publicForm();

        $this->post(admin_url("formpublic/{$table->table_name}/{$publicForm->id}/preview"), $this->previewPayload('Claude public preview (saved post)'))
            ->assertOk()
            ->assertSee('Claude public preview (saved post)')
            ->assertSee('name="value[index_text]"', false);
        // a preview never saves
        $this->assertNull(PublicForm::query()->where('id', $publicForm->id)->firstOrFail()->getOption('header_label'));
    }

    public function testPreviewOfASavedFormByPut(): void
    {
        $table = $this->table();
        $publicForm = $this->publicForm();

        $this->put(admin_url("formpublic/{$table->table_name}/{$publicForm->id}/preview"), $this->previewPayload('Claude public preview (saved put)'))
            ->assertOk()
            ->assertSee('Claude public preview (saved put)')
            ->assertSee('name="value[index_text]"', false);
    }

    public function testFileDeleteRemovesTheHeaderLogo(): void
    {
        $table = $this->table();
        $publicForm = $this->publicForm();
        $logo = ExmentFile::put(FileType::PUBLIC_FORM, 'publicform', 'claude logo bytes', ['filename' => 'claude_logo.png']);
        $this->storedPath = $logo->path;
        $publicForm->setOption('header_logo', $logo->path);
        $publicForm->save();
        $this->assertTrue(Storage::disk(config('admin.upload.disk'))->exists($logo->path));

        $this->put(admin_url("formpublic/{$table->table_name}/{$publicForm->id}/filedelete"))
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.delete_succeeded')]);

        $this->assertNull(PublicForm::query()->where('id', $publicForm->id)->firstOrFail()->getOption('header_logo'));
        $this->assertDatabaseMissing((new ExmentFile())->getTable(), ['uuid' => $logo->uuid]);
        $this->assertFalse(Storage::disk(config('admin.upload.disk'))->exists($logo->path), 'the logo file is deleted');
    }

    public function testExportDownloadsTheFormAsATemplate(): void
    {
        $table = $this->table();
        $publicForm = $this->publicForm();

        $response = $this->get(admin_url("formpublic/{$table->table_name}/{$publicForm->id}/export"))->assertOk();

        $download = $response->baseResponse;
        if (!$download instanceof BinaryFileResponse) {
            $this->fail('the export answers a file download');
        }
        $zipPath = $download->getFile()->getPathname();
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true, 'the download is a zip file');
            $config = json_decode((string) $zip->getFromName('config.json'), true);
            $zip->close();

            $this->assertIsArray($config);
            $this->assertSame($publicForm->public_form_view_name, $config['template_view_name'] ?? null);
            $this->assertIsArray($config['public_form'] ?? null, 'the template carries the public form');
        } finally {
            // deleteFileAfterSend() only deletes it once the response is sent, which a test never does
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    public function testPatch(): void
    {
        $table = $this->table();
        $publicForm = $this->publicForm();

        $this->patch(admin_url("formpublic/{$table->table_name}/{$publicForm->id}"), [
            'public_form_view_name' => 'Claude public form (patch)',
        ])->assertRedirect(admin_url("form/{$table->table_name}"))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('public_forms', [
            'id' => $publicForm->id,
            'public_form_view_name' => 'Claude public form (patch)',
            'active_flg' => 1,
        ]);
    }

    protected function table(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
    }

    protected function publicForm(): PublicForm
    {
        $form = CustomForm::getDefault($this->table());

        return PublicForm::query()->where('custom_form_id', $form->id)->orderBy('id')->firstOrFail();
    }

    /**
     * What the setting screen posts for a preview: the form to publish and its header.
     *
     * @param string $headerLabel
     * @return array<string, mixed>
     */
    protected function previewPayload(string $headerLabel): array
    {
        return [
            'custom_form_id' => CustomForm::getDefault($this->table())->id,
            'public_form_view_name' => 'Claude public preview',
            'design_setting' => [
                'use_header' => '1',
                'header_label' => $headerLabel,
                'use_footer' => '1',
            ],
        ];
    }
}
