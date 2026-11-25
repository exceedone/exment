<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\McpController;
use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Middleware\CheckLoggingEnabled;
use Exceedone\Exment\Middleware\ExmentDebug;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\UserApiKeys;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Auth\Database\OperationLog;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

/**
 * With the settings as they are at first, no log keeps a password, a token, an API key, a mail
 * address or a value typed (ISS02839 part 4, ISS02903): the requests carry them through the
 * pages, the login, the API, MCP and the performance log, and a mail fails with an address in the
 * error; then every table of the logs and the files of the application log are searched for them.
 */
class LogPrivacyDefaultsTest extends ApiTestBase
{
    use DatabaseTransactions;

    protected const PASSWORD = 'Pw-Secret-7319';
    protected const TOKEN = 'tok7319ZZqq';
    protected const API_KEY = 'exk_7319AAbbCCdd';
    protected const EMAIL = 'hanako7319@example.com';
    protected const TYPED = 'TypedValue7319';

    /** @var array<string, int> the last id of each table of the logs before the test */
    protected $last = [];

    /** @var array<string, int> the size of each file of the application log before the test */
    protected $sizes = [];

    protected function setUp(): void
    {
        parent::setUp();
        System::clearCache();
        foreach ($this->tables() as $name => $query) {
            /** @var \Illuminate\Database\Query\Builder $query */
            $this->last[$name] = intval($query->max('id'));
        }
        foreach ($this->logFiles() as $file) {
            $this->sizes[$file] = intval(filesize($file));
        }
    }

    protected function tearDown(): void
    {
        Features::reset();
        System::clearCache();
        parent::tearDown();
    }

    /**
     * The values carried by a request.
     *
     * @return array<string, string>
     */
    protected function values(): array
    {
        return [
            'password' => static::PASSWORD,
            'access_token' => static::TOKEN,
            'api_key' => static::API_KEY,
            'email' => static::EMAIL,
            'memo' => static::TYPED,
        ];
    }

    /**
     * The tables of the logs.
     *
     * @return array<string, mixed> the queries (an Eloquent or a query builder)
     */
    protected function tables(): array
    {
        $tables = [
            'operation_log' => OperationLog::query(),
            'login_logs' => LoginLog::query(),
        ];
        foreach (['permission_change_logs', 'browser_logs'] as $table) {
            if (Schema::hasTable($table)) {
                $tables[$table] = \DB::table($table);
            }
        }
        foreach ([SystemTableName::SYSTEM_LOGS, SystemTableName::MAIL_SEND_LOG] as $name) {
            $custom = CustomTable::getEloquent($name);
            if (isset($custom)) {
                $tables[$name] = $custom->getValueModel()->query()->withoutGlobalScopes();
            }
        }
        return $tables;
    }

    /**
     * @return array<int, string>
     */
    protected function logFiles(): array
    {
        return array_values(array_filter((array) glob(storage_path('logs/*.log')), 'is_string'));
    }

    /**
     * None of the values is in a row written in the test, or in what the application log got.
     */
    protected function assertNowhere(string $what): void
    {
        $secrets = array_values($this->values());
        foreach ($this->tables() as $name => $query) {
            /** @var \Illuminate\Database\Query\Builder $query */
            $rows = $query->where('id', '>', $this->last[$name] ?? 0)->get();
            $text = strval(json_encode($rows->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text, "$what: $name");
            }
        }
        foreach ($this->logFiles() as $file) {
            $text = strval(file_get_contents($file, false, null, $this->sizes[$file] ?? 0));
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text, "$what: " . basename($file));
            }
        }
    }

    protected function admin(): LoginUser
    {
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        return $user;
    }

    public function testThePages(): void
    {
        $this->be($this->admin());
        $before = OperationLog::query()->count();
        $this->post(admin_urls('log_management', 'setting'), $this->values(), ['X-Requested-With' => 'XMLHttpRequest']);
        $this->get(admin_urls_query('data', 'user', $this->values()));
        $this->assertGreaterThan($before, OperationLog::query()->count(), 'the requests are logged');
        $this->assertNowhere('the pages');
    }

    public function testTheLogin(): void
    {
        $before = LoginLog::query()->count();
        $this->post(admin_url('auth/login'), ['username' => static::EMAIL, 'password' => static::PASSWORD]);
        $this->post(admin_url('auth/login'), ['username' => 'admin', 'password' => static::PASSWORD]);
        $this->assertGreaterThan($before, LoginLog::query()->count(), 'the failures are logged');
        $this->assertNowhere('the login');
    }

    public function testTheApi(): void
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ, ApiScope::VALUE_WRITE]);
        $before = OperationLog::query()->count();
        $this->withHeaders(['Authorization' => "Bearer $token"])->get(admin_urls_query('api', 'data', 'user', $this->values()));
        $this->withHeaders(['Authorization' => "Bearer $token"])->postJson(admin_urls('api', 'data', 'user'), ['value' => $this->values()]);
        // a key that is not one
        $this->withHeaders(['Authorization' => 'Bearer ' . static::API_KEY])->get(admin_urls('api', 'data', 'user'));
        $this->assertGreaterThan($before, OperationLog::query()->count(), 'the calls are logged');
        $this->assertNowhere('the API');
    }

    public function testMcp(): void
    {
        Features::reset();
        config(['exment.features_locked' => '']);
        System::features([
            Features::MCP => ['enabled' => true, 'role_groups' => null],
            Features::API_KEYS => ['enabled' => true, 'role_groups' => null],
        ]);
        System::clearRequestSession();
        Features::reset();
        $user = $this->admin();
        RateLimiter::clear(McpController::limiterKey((int) $user->id));
        $key = UserApiKeys::create((int) $user->base_user_id, 'privacy test', UserApiKeys::readOnlyScopes(), null)[1];

        \Auth::forgetGuards();
        $this->call('POST', admin_urls('api', 'mcp'), [], [], [], $this->transformHeadersToServerVars([
            'Authorization' => "Bearer $key",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ]), strval(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'search_data',
            'arguments' => ['table' => 'user', 'q' => static::TYPED] + $this->values(),
        ]])));
        $this->assertSame(1, OperationLog::query()->where('id', '>', $this->last['operation_log'])->where('method', 'MCP')->count(), 'the call is logged');
        $this->assertNowhere('MCP');
    }

    public function testThePerformanceLog(): void
    {
        System::logging_toggle_available(true);
        System::logging_slow_request_seconds(0);
        System::logging_slow_sql_seconds(0);
        System::logging_max_per_hour(0);
        System::clearCache();
        try {
            // the row it saves (its table is made when the log is turned on: not in a test that rolls back)
            ExmentDebug::handleLog();
            \DB::select('select ? as a, ? as b', [static::EMAIL, static::TYPED]);
            $maker = new class () extends CheckLoggingEnabled {
                /**
                 * @param \Illuminate\Http\Request $request
                 * @return array<string, mixed>|null
                 */
                public static function make($request): ?array
                {
                    return static::makeLog($request);
                }
            };
            $log = $maker::make(\Illuminate\Http\Request::create(admin_urls('data', 'user'), 'GET', $this->values()));
            $this->assertNotNull($log);
            $text = strval(json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->assertStringContainsString('select ? as a', $text, 'the SQL is kept');
            foreach ($this->values() as $value) {
                $this->assertStringNotContainsString($value, $text);
            }

            // a request: the table, when the site has it
            $this->be($this->admin());
            $this->get(admin_urls_query('data', 'user', $this->values()))->assertOk();
            $this->assertNowhere('the performance log');
        } finally {
            System::logging_toggle_available(false);
            System::clearCache();
        }
    }

    public function testTheErrorOfAMail(): void
    {
        $reason = MailSendJob::errorReason(new \RuntimeException('Expected response code "250" but got "550" with message "550 5.1.1 <' . static::EMAIL . '>: Recipient address rejected"'));
        $this->assertStringNotContainsString(static::EMAIL, $reason);
        $this->assertStringContainsString('Recipient address rejected', $reason, 'the reason stays');
    }
}
