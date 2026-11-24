<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomCopy;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The buttons of a record send requests of their own; each request is checked as the button is:
 * - attaching a file needs the edit permission of the record (and the table keeping attachments),
 * - a copy needs the copy to be of this table and the create permission of the table it copies to,
 * - a plugin button runs only a plugin the table offers.
 *
 * user2 of the test data may only read the records of custom_value_view_all.
 */
class DataActionPermissionTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];
    protected const TABLE_VIEW = TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array<mixed> $parameters
     * @param array<mixed> $cookies
     * @param array<mixed> $files
     * @param array<mixed> $server
     * @param string|null $content
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }
        System::clearRequestSession();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function actAs(string $login_id): void
    {
        $this->be(LoginUser::findOrFail($login_id));
        System::clearRequestSession();
    }

    protected function record(): CustomValue
    {
        $record = CustomTable::getEloquent(static::TABLE_VIEW)->getValueModel()->query()->first();
        $this->assertNotNull($record);
        return $record;
    }

    protected function documentCount(CustomValue $record): int
    {
        return \Illuminate\Support\Facades\DB::table(getDBTableName(SystemTableName::DOCUMENT))
            ->where('parent_type', static::TABLE_VIEW)->where('parent_id', $record->id)->count();
    }

    public function testAUserWhoMayOnlyReadTheRecordDoesNotAttachAFile(): void
    {
        $record = $this->record();
        $before = $this->documentCount($record);

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->post(admin_urls('data', static::TABLE_VIEW, $record->id, 'fileupload'), [
            'file_data' => [UploadedFile::fake()->create('note.txt', 1, 'text/plain')],
        ], static::AJAX);

        $this->assertSame($before, $this->documentCount($record));
    }

    public function testAUserWhoMayEditStillAttachesAFile(): void
    {
        $record = $this->record();
        $before = $this->documentCount($record);

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->post(admin_urls('data', static::TABLE_VIEW, $record->id, 'fileupload'), [
            'file_data' => [UploadedFile::fake()->create('note.txt', 1, 'text/plain')],
        ], static::AJAX)->assertOk();

        $this->assertSame($before + 1, $this->documentCount($record));
    }

    public function testACopyDoesNotCreateWhereTheUserMayNotCreate(): void
    {
        $to = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NO_PERMISSION);
        $copy = new CustomCopy();
        $copy->from_custom_table_id = CustomTable::getEloquent(static::TABLE_VIEW)->id;
        $copy->to_custom_table_id = $to->id;
        $copy->save();
        $before = $to->getValueModel()->query()->withoutGlobalScopes()->count();

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->post(admin_urls('data', static::TABLE_VIEW, $this->record()->id, 'copyClick'), [
            'uuid' => $copy->suuid,
        ], static::AJAX);

        $this->assertSame($before, $to->getValueModel()->query()->withoutGlobalScopes()->count());
    }

    public function testAPluginNotSetForTheTableDoesNotRun(): void
    {
        $table = CustomTable::getEloquent(static::TABLE_VIEW);
        $offered = Plugin::getPluginsByTable($table)->pluck('id')->all();
        $plugin = Plugin::all()->first(function ($plugin) use ($offered) {
            return $plugin->plugin_name == 'TestPluginButton' && !in_array($plugin->id, $offered);
        });
        $this->assertNotNull($plugin, 'TestPluginButton is not set for ' . static::TABLE_VIEW);

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->post(admin_urls('data', static::TABLE_VIEW, $this->record()->id, 'pluginClick'), [
            'uuid' => $plugin->uuid,
            'plugin_type' => PluginType::BUTTON,
        ], static::AJAX)->assertStatus(404);
    }
}
