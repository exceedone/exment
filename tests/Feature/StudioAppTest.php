<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The phone app of the Studio (ISS00116): the page, its manifest and its service worker.
 */
class StudioAppTest extends FeatureTestBase
{
    use TestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testTheAppPageOpensForAnyUser(): void
    {
        foreach ([TestDefine::TESTDATA_USER_LOGINID_ADMIN, TestDefine::TESTDATA_USER_LOGINID_USER2] as $id) {
            $this->be(LoginUser::findOrFail($id));
            $page = $this->get(admin_url('app'));
            $page->assertOk();
            $page->assertSee(exmtrans('studio.app_title'));
            // the screens come from the JSON of the Studio, the data from the data API of the session
            $page->assertSee('/studio/screens', false);
            $page->assertSee(strval(json_encode(admin_url('webapi'))), false);
            // the manifest is fetched with the login
            $page->assertSee('rel="manifest" href="' . admin_url('app/manifest.webmanifest') . '" crossorigin="use-credentials"', false);
        }
    }

    public function testTheManifestAndTheServiceWorker(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $manifest = $this->get(admin_url('app/manifest.webmanifest'));
        $manifest->assertOk();
        $this->assertStringStartsWith('application/manifest+json', strval($manifest->headers->get('Content-Type')));
        $manifest->assertJsonPath('display', 'standalone');
        $manifest->assertJsonPath('start_url', admin_url('app'));

        $worker = $this->get(admin_url('app/sw.js'));
        $worker->assertOk();
        $this->assertStringStartsWith('application/javascript', strval($worker->headers->get('Content-Type')));
        $worker->assertSee("addEventListener('fetch'", false);
    }

    public function testTheAppNeedsTheLogin(): void
    {
        $this->get(admin_url('app'))->assertRedirect();
        $this->get(admin_url('app/code?value=1'))->assertRedirect();
    }

    public function testTheAppLoadsTheCodeReaderOfExment(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $page = $this->get(admin_url('app'));
        $page->assertSee(strval(json_encode(admin_asset('vendor/exment/js/zxing.js'))), false);
        $page->assertSee(strval(json_encode(admin_url('app/code'))), false);
    }

    public function testTheDraftsOnThePhoneAreKeptPerUser(): void
    {
        foreach ([TestDefine::TESTDATA_USER_LOGINID_ADMIN, TestDefine::TESTDATA_USER_LOGINID_USER2] as $id) {
            $user = LoginUser::findOrFail($id);
            $this->be($user);
            $page = $this->get(admin_url('app'));
            // the drafts and the kept screens are in the store of the phone, under the user
            $page->assertSee("indexedDB.open('exment-app'", false);
            $page->assertSee('var userKey = ' . json_encode(strval($user->getUserId())) . ';', false);
            // (the texts are in the script as JSON)
            $page->assertSee(trim(strval(json_encode(exmtrans('studio.app_draft_saved'))), '"'), false);
        }
    }

    public function testACodeWithTheLinkOfADataOpensIt(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $value = $table->getValueModel()->query()->orderBy('id')->firstOrFail();

        // the QR code of Exment, and the address of the page of the data
        foreach ([
            admin_url('qr-code/' . $table->table_name . '/' . $value->id),
            admin_url('data/' . $table->table_name . '/' . $value->id),
            admin_url('data/' . $table->table_name . '/' . $value->id . '/edit?after-save=1'),
        ] as $code) {
            $found = $this->getJson(admin_url('app/code?value=' . rawurlencode($code)))->assertOk()->json('found');
            $this->assertSame([['table' => $table->table_name, 'id' => $value->id, 'label' => strval($value->getLabel())]], $found, $code);
        }

        // the address of another site is not a data here
        $this->assertSame([], $this->getJson(admin_url('app/code?value=' . rawurlencode('https://example.com/admin/data/' . $table->table_name . '/' . $value->id)))->json('found'));
    }

    public function testACodeFindsOnlyTheDataTheUserMaySee(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NO_PERMISSION);
        $value = $table->getValueModel()->query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $code = rawurlencode(admin_url('qr-code/' . $table->table_name . '/' . $value->id));

        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->assertCount(1, $this->getJson(admin_url('app/code?value=' . $code))->json('found'));

        // the permissions of the admin are kept for the request: forget them
        System::clearRequestSession();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->assertSame([], $this->getJson(admin_url('app/code?value=' . $code))->json('found'));
        // nor by the value of a column
        $this->assertSame([], $this->getJson(admin_url('app/code?table=' . $table->table_name . '&value=' . rawurlencode(strval($value->getValue('index_text')))))->json('found'));
    }

    public function testAJanCodeGivenToADataOpensIt(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $value = $table->getValueModel()->query()->orderBy('id', 'desc')->firstOrFail();
        DB::table('jan_codes')->insert(['table_id' => $table->id, 'target_id' => $value->id, 'jan_code' => '4901234567894']);
        $other = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $own = $other->getValueModel()->query()->orderBy('id')->firstOrFail();
        $before = $own->getValue('index_text');

        try {
            $found = $this->getJson(admin_url('app/code?value=4901234567894'))->assertOk()->json('found');
            $this->assertSame([['table' => $table->table_name, 'id' => $value->id, 'label' => strval($value->getLabel())]], $found);
            // on the page of a table, the data of that table with the code come first
            $own->setValue('index_text', '4901234567894')->save();
            $found = $this->getJson(admin_url('app/code?table=' . $other->table_name . '&value=4901234567894'))->json('found');
            $this->assertSame([[$other->table_name, $own->id]], array_map(fn ($f) => [$f['table'], $f['id']], $found));
            // a code no data has
            $this->assertSame([], $this->getJson(admin_url('app/code?value=4900000000000'))->json('found'));
        } finally {
            DB::table('jan_codes')->where('jan_code', '4901234567894')->delete();
            $own->setValue('index_text', $before)->save();
        }
    }

    public function testACodeIsLookedForInTheColumnsOfTheTable(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $value = $table->getValueModel()->query()->orderBy('id')->skip(2)->firstOrFail();

        // a search-enabled column, a text column and a number column
        foreach (['index_text', 'email', 'integer'] as $column) {
            $code = strval($value->getValue($column));
            $found = $this->getJson(admin_url('app/code?table=' . $table->table_name . '&value=' . rawurlencode($code)))->assertOk()->json('found');
            $this->assertContains($value->id, array_column($found, 'id'), $column);
            foreach ($found as $row) {
                $this->assertSame($table->table_name, $row['table']);
                $this->assertSame($code, strval($table->getValueModel($row['id'])->getValue($column)), $column);
            }
        }

        // without the table, only links and JAN codes are looked for
        $this->assertSame([], $this->getJson(admin_url('app/code?value=' . rawurlencode(strval($value->getValue('index_text')))))->json('found'));
        // many rows: not more than the limit
        $many = $this->getJson(admin_url('app/code?table=' . $table->table_name . '&value=' . rawurlencode(strval($value->getValue('init_text')))))->json('found');
        $this->assertLessThanOrEqual(\Exceedone\Exment\Controllers\StudioAppController::CODE_MAX_FOUND, count($many));
        $this->assertNotEmpty($many);
    }
}
