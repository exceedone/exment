<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Storage\ConversionAvailability;
use Exceedone\Exment\Services\Storage\DrainAttestation;
use Exceedone\Exment\Services\Storage\TableStorage;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Stage B block 5 management screen through the HTTP kernel (isolated database).
 * The production binding (UnavailableConversion) is kept except where a test binds execution.
 */
class StorageConversionHttpTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    private function asUser(string $login): void
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs(LoginUser::find($login), 'admin');
    }

    private function editTable(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
    }

    public function testAdminPagesRenderAndReportUnavailableExecution(): void
    {
        $this->asUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $index = $this->get(admin_url('storage_conversion'));
        $index->assertStatus(200);
        $index->assertSee(exmtrans('storage_conversion.execution_unavailable'));
        $index->assertSee(exmtrans('storage_conversion.missing_raw_writer_enforcement'));

        $show = $this->get(admin_url('storage_conversion/' . $this->editTable()->id));
        $show->assertStatus(200);
        $show->assertSee(exmtrans('storage_conversion.preflight'));
        $show->assertSee('name="_token"', false);
        $show->assertSee('disabled', false);
    }

    /**
     * Every action of the controller refuses a non-system user with 403 before anything else.
     * Called directly (as SafetyCheckAdminPageTest does): Exment's 403 page ends the process
     * inside the HTTP test kernel.
     */
    public function testNonAdminIsRefused(): void
    {
        $this->asUser(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $controller = new \Exceedone\Exment\Controllers\StorageConversionController();
        $id = $this->editTable()->id;
        $request = \Illuminate\Http\Request::create('/', 'POST');
        $calls = [
            'index' => function () use ($controller, $request) {
                return $controller->index($request, new \ExmentAdminCore\Admin\Layout\Content());
            },
            'show' => function () use ($controller, $request, $id) {
                return $controller->show($request, new \ExmentAdminCore\Admin\Layout\Content(), $id);
            },
        ];
        foreach (['provision', 'dualwrite', 'backfill', 'verify', 'awaitConfirmation', 'cutover', 'cancel', 'rollback', 'cleanup', 'deprovision'] as $action) {
            $calls[$action] = function () use ($controller, $request, $id, $action) {
                return $controller->$action($request, $id);
            };
        }
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("$name: a non-system user must be refused");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), $name);
            }
        }
        $this->assertTrue(TableStorage::fresh($this->editTable())->isJson());
    }

    /**
     * The system menu entry: selectable on the menu screen (ja/en label), visible to a system
     * administrator, hidden from a user without system permission; the route keeps its own gate.
     */
    public function testMenuEntryAuthorization(): void
    {
        $this->assertSame('storage_conversion', \Exceedone\Exment\Model\Define::MENU_SYSTEM_DEFINITION['storage_conversion']['uri']);
        $controller = new \Exceedone\Exment\Controllers\MenuController();
        $options = new \ReflectionMethod($controller, 'getMenuType');
        $options->setAccessible(true);
        $list = $options->invoke($controller, \Exceedone\Exment\Enums\MenuType::SYSTEM, false);
        $this->assertArrayHasKey('storage_conversion', $list);
        foreach (['ja', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame('menu.system_definitions.storage_conversion', exmtrans('menu.system_definitions.storage_conversion'));
        }

        $item = ['uri' => 'storage_conversion', 'menu_type' => \Exceedone\Exment\Enums\MenuType::SYSTEM];
        $this->asUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertTrue(\Exment::user()->visible($item), 'system administrator sees the entry');
        $this->asUser(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertFalse(\Exment::user()->visible($item), 'user without system permission does not');

        // the entry opens a page that still reports execution as unavailable
        $this->asUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('storage_conversion'))->assertStatus(200)->assertSee(exmtrans('storage_conversion.execution_unavailable'));
    }

    public function testPostWhileUnavailableDoesNothing(): void
    {
        $this->asUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $table = $this->editTable();
        foreach (['provision', 'dualwrite', 'cancel', 'cutover', 'rollback', 'cleanup', 'deprovision'] as $action) {
            $this->post(admin_url("storage_conversion/{$table->id}/$action"))->assertRedirect(admin_url('storage_conversion/' . $table->id));
        }
        $this->assertTrue(TableStorage::fresh($table)->isJson());
        $this->assertSame(0, \DB::table('custom_table_storage_runs')->count());
        $this->assertSame(0, \DB::table('custom_table_storage_transitions')->count());
    }

    public function testAvailableActionsAreRecheckedByServices(): void
    {
        $this->app->instance(ConversionAvailability::class, new class () implements ConversionAvailability {
            public function isAvailable(): bool
            {
                return true;
            }

            public function missing(): array
            {
                return [];
            }
        });
        $this->asUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $table = $this->editTable();
        $attest = [];
        foreach (DrainAttestation::REQUIRED as $code) {
            $attest['attest_' . $code] = '1';
        }
        // a confirmation for a run / version that does not exist, on a table not awaiting confirmation
        $this->post(admin_url("storage_conversion/{$table->id}/cutover"), $attest + ['confirm_l5' => '1', 'run_id' => 999999, 'generation' => 0, 'report_version' => 'stale', 'attest_measures' => 'x'])
            ->assertRedirect();
        $this->post(admin_url("storage_conversion/{$table->id}/rollback"), $attest + ['attest_measures' => 'x'])->assertRedirect();
        $this->post(admin_url("storage_conversion/{$table->id}/cleanup"), ['confirm_table_name' => 'wrong'])->assertRedirect();
        $this->assertTrue(TableStorage::fresh($table)->isJson(), 'every refused action left the table untouched');
        // system table (R6) refused by the services even when available
        $user = CustomTable::getEloquent('user');
        $this->post(admin_url("storage_conversion/{$user->id}/dualwrite"))->assertRedirect();
        $this->assertTrue(TableStorage::fresh($user)->isJson());
    }

    public function testPostRoutesCarryCsrfAndTheMiddlewareRejectsMissingTokens(): void
    {
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create(admin_url('storage_conversion/1/cutover'), 'POST'));
        $middleware = app('router')->gatherRouteMiddleware($route);
        $this->assertContains(\Exceedone\Exment\Middleware\VerifyCsrfToken::class, $middleware);

        // Laravel skips CSRF while running unit tests; the same middleware with that switch off
        $csrf = new class (app(), app('encrypter')) extends \Exceedone\Exment\Middleware\VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $request = \Illuminate\Http\Request::create(admin_url('storage_conversion/1/cutover'), 'POST');
        $request->setLaravelSession(app('session.store'));
        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $csrf->handle($request, function () {
            return response('passed');
        });
    }

    /**
     * Every branch of the detail view compiles (state-dependent forms, L5 report, runs, lifecycle).
     */
    public function testDetailViewCompilesForEveryState(): void
    {
        $this->asUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $table = $this->editTable();
        foreach (['json', 'provisioned', 'dualwrite', 'backfilling', 'verifying', 'awaiting_confirmation', 'physical_read', 'failed'] as $state) {
            $html = view('exment::storage-conversion.show', [
                'execution' => ['available' => $state !== 'json', 'missing' => ['integration_verification'], 'driver' => 'mariadb', 'installed' => true],
                'table' => $table,
                'status' => [
                    'storage' => ['mode' => $state === 'physical_read' ? 'physical' : 'json', 'state' => $state, 'generation' => 2],
                    'preflight' => ['eligible' => false, 'blockers' => [['code' => 'row_size_exceeded', 'message' => 'm']], 'warnings' => [['code' => 'driver_unverified', 'message' => 'w']], 'dependencies' => ['D-3' => 'x'], 'plan_error' => null],
                    'plan' => null,
                    'runs' => ['verify' => ['id' => 3, 'status' => 'completed', 'result' => 'passed', 'boundary_id' => 9, 'checkpoint_id' => 9, 'pending' => 0, 'counts' => ['rows' => 9], 'error' => 'RuntimeException 0', 'started_at' => null, 'finished_at' => null]],
                    'l5_report' => ['run_id' => 3, 'generation' => 2, 'l5' => ['num' => ['invalid' => 1]], 'version' => 'v'],
                    'transitions' => [['from_state' => 'json', 'to_state' => 'provisioned', 'from_generation' => 0, 'to_generation' => 0, 'actor_user_id' => 1, 'created_at' => '2026-11-25 00:00:00']],
                ],
                'attestationCodes' => DrainAttestation::REQUIRED,
                'actionBase' => admin_url('storage_conversion/' . $table->id),
            ])->render();
            $this->assertStringContainsString(exmtrans('storage_conversion.state_' . $state), $html, $state);
        }
    }

    /** Run only after rolling the two Stage B migrations back (C:\hyb_p1_work runner). */
    public function testPagesWithoutStorageMigrations(): void
    {
        if (\Schema::hasColumn('custom_tables', 'storage_state')) {
            $this->markTestSkipped('storage migrations are present');
        }
        $this->asUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('storage_conversion'))->assertStatus(200)->assertSee(exmtrans('storage_conversion.not_installed'));
        $this->get(admin_url('storage_conversion/' . $this->editTable()->id))->assertStatus(200)->assertSee(exmtrans('storage_conversion.not_installed'));
        // a JSON table works as before without the migrations
        $m = $this->editTable()->getValueModel()->first();
        $m->setValue('text', 'no migration')->save();
        $this->assertSame('no migration', $this->editTable()->getValueModel()->find($m->id)->getValue('text'));
    }
}
