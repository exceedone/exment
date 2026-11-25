<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Middleware\LogOperation;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\LogArchiver;
use Exceedone\Exment\Services\LogManagement\LogPrivacy;
use Exceedone\Exment\Services\LogManagement\LogPrivacyTap;
use Exceedone\Exment\Services\LogManagement\LogSettings;
use Exceedone\Exment\Services\SystemRequire\Security\LogSecurity;
use Exceedone\Exment\Services\SystemRequire\Security\SecurityCheck;

/**
 * Personal data in the logs (ISS02839, part 4): each default, and each switch.
 */
class LogPrivacyTest extends UnitTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        \DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        \DB::rollBack();
        LogSecurity::$paths = null;
        System::clearCache();
        parent::tearDown();
    }

    /**
     * @param mixed $value
     */
    protected function set(string $key, $value): void
    {
        System::{$key}($value);
        System::clearCache();
    }

    public function testTheDefaults(): void
    {
        $this->assertFalse(LogPrivacy::storeInput(), 'the input is not kept');
        $this->assertSame(LogPrivacy::IP_KEEP, LogPrivacy::ipMode(), 'the IP as it is: the audits need it');
        $this->assertTrue(LogPrivacy::mailBody(), 'the mail send log keeps the body (the send history uses it)');
        $this->set('mail_log_no_body', true);
        $this->assertFalse(LogPrivacy::mailBody(), 'the switch');
        $this->assertFalse(LogArchiver::encrypted());
    }

    public function testTheOperationLogKeepsTheKeysOnly(): void
    {
        $input = ['value' => ['email' => 'taro@example.com', 'memo' => 'secret note', 'tags' => ['a', 'b']], 'password' => 'p', 'empty' => '', 'page' => 2];
        $masked = LogOperation::maskInputArray($input, 'admin/data/user');
        $expected = ['value' => ['email' => '***', 'memo' => '***', 'tags' => ['***', '***']], 'password' => '***', 'empty' => '', 'page' => '***'];
        $this->assertEquals($expected, $masked);
        $this->assertStringNotContainsString('taro@example.com', strval(json_encode($masked)));
    }

    public function testKeepingTheInputMasksTheSecretsMore(): void
    {
        $this->set('log_store_input', true);
        $masked = LogOperation::maskInputArray([
            'value' => ['email' => 'taro@example.com', 'api_key' => 'a column of the table'],
            'token' => 'a column of a business screen', 'access_token' => 'a', 'client_secret' => 's', 'private_key' => 'k', 'api_key' => 'x', 'password' => 'p', 'name' => 'Taro',
        ], 'admin/data/user');
        $this->assertSame('taro@example.com', $masked['value']['email'], 'on: the values as before');
        $this->assertSame('a column of the table', $masked['value']['api_key'], 'the values of a record keep their own column names');
        foreach (['access_token', 'client_secret', 'private_key', 'api_key', 'password'] as $key) {
            $this->assertSame('***', $masked[$key], $key);
        }
        $this->assertSame('a column of a business screen', $masked['token'], 'a plain "token" is masked on its own screens only');
        $this->assertSame('Taro', $masked['name']);
    }

    public function testTheIpModes(): void
    {
        $this->assertSame('203.0.113.7', LogPrivacy::ip('203.0.113.7'));
        $this->set('log_ip_mode', LogPrivacy::IP_TRUNCATE);
        $this->assertSame('203.0.113.0', LogPrivacy::ip('203.0.113.7'));
        $this->assertSame('2001:db8:1234::/48', LogPrivacy::ip('2001:db8:1234:5678::1'));
        $this->set('log_ip_mode', LogPrivacy::IP_HASH);
        $hashed = strval(LogPrivacy::ip('203.0.113.7'));
        $this->assertStringStartsWith('h:', $hashed);
        $this->assertEquals($hashed, LogPrivacy::ip('203.0.113.7'), 'the same address, the same text');
        $this->assertNotSame($hashed, LogPrivacy::ip('203.0.113.8'));
        $this->assertNull(LogPrivacy::ip(null));
    }

    public function testTheApplicationLogMasksMailsAndTheValuesOfSql(): void
    {
        $message = "SQLSTATE[23000]: Duplicate entry 'taro@example.com' (Connection: mysql, SQL: insert into `users` (`name`, `memo`) values ('Taro Yamada', 'it''s \\'mine\\''))";
        $masked = LogPrivacy::maskText($message);
        $this->assertStringNotContainsString('taro@example.com', $masked);
        $this->assertStringNotContainsString('Taro Yamada', $masked);
        $this->assertStringNotContainsString('mine', $masked);
        $this->assertStringContainsString('insert into `users`', $masked, 'the SQL itself stays');
        $this->assertSame('mail ***@*** sent', LogPrivacy::maskText('mail hanako@example.co.jp sent'));
        $this->assertSame("a 'quoted' text", LogPrivacy::maskText("a 'quoted' text"), 'quotes are masked in SQL only');

        $record = ['message' => 'to taro@example.com', 'context' => ['exception' => new \RuntimeException("SQL: select * where mail = 'taro@example.com'")]];
        $out = LogPrivacyTap::process($record);
        $this->assertStringNotContainsString('taro@example.com', $out['message']);
        $this->assertStringNotContainsString('taro@example.com', strval($out['context']['exception']));

        $this->set('log_store_input', true);
        $this->assertEquals($record, LogPrivacyTap::process($record), 'on: as before');
    }

    public function testTheFilesOfTheApplicationLog(): void
    {
        config(['logging.channels.single' => ['driver' => 'single', 'path' => storage_path('logs/laravel.log')]]);
        config(['exment.log_file_permission' => '0640']);
        LogSettings::applyPrivacy();
        $this->assertSame(0640, config('logging.channels.single.permission'));
        $this->assertContains(LogPrivacyTap::class, (array) config('logging.channels.single.tap'));
        config(['exment.log_file_permission' => '0660']);
        LogSettings::applyPrivacy();
        $this->assertSame(0660, config('logging.channels.single.permission'));
        $this->assertSame(1, count(array_keys((array) config('logging.channels.single.tap'), LogPrivacyTap::class)), 'the tap once');
    }

    public function testTheEncryptedArchive(): void
    {
        $root = storage_path('app/' . LogArchiver::ROOT);
        $before = glob($root . '/*/*.gz') ?: [];
        $this->set('log_archive_enabled', true);
        $this->set('log_archive_driver', LogArchiver::DRIVER_LOCAL);
        $this->set('log_archive_encrypt', true);
        try {
            LogArchiver::rows('privacy_test', ['a'], [['hello archive']], function () {
                return '2001-05';
            });
            $path = LogArchiver::ROOT . '/privacy_test/privacy_test-2001-05.csv.gz';
            $raw = strval(file_get_contents(storage_path('app/' . $path)));
            $this->assertStringStartsWith(LogArchiver::ENCRYPTED_LINE, $raw, 'not readable without APP_KEY');
            $this->assertStringContainsString('hello archive', strval(gzdecode(strval(LogArchiver::read($path)))), 'the download is the gzip');
        } finally {
            foreach (array_diff(glob($root . '/*/*.gz') ?: [], $before) as $made) {
                @unlink($made);
            }
            @rmdir($root . '/privacy_test');
        }
    }

    public function testTheSecurityCheck(): void
    {
        $this->assertContains(LogSecurity::class, SecurityCheck::ITEMS);
        $dir = sys_get_temp_dir() . '/exment-logsec-' . uniqid();
        mkdir($dir, 0700);
        LogSecurity::$paths = [$dir];
        try {
            config(['exment.debugmode' => false, 'exment.debugmode_sql' => false, 'exment.debugmode_request' => false]);
            $this->assertSame(SystemRequireResult::OK, (new LogSecurity())->checkResult());

            $this->set('log_store_input', true);
            $this->assertSame(['store_input'], (new LogSecurity())->findings());
            $this->set('log_store_input', false);

            config(['exment.debugmode_sql' => true]);
            $this->assertSame(['debug'], (new LogSecurity())->findings());
            config(['exment.debugmode_sql' => false]);

            if (DIRECTORY_SEPARATOR === '/') {
                chmod($dir, 0755);
                $this->assertSame(['readable'], (new LogSecurity())->findings());
                $this->assertSame(SystemRequireResult::WARNING, (new LogSecurity())->checkResult());
            }
            foreach (['label', 'explain', 'store_input', 'debug', 'readable', 'fix'] as $key) {
                foreach (['en', 'ja', 'de', 'es', 'ko', 'vi', 'zh_CN'] as $locale) {
                    $this->assertStringStartsNotWith('exment::', strval(trans('exment::exment.security_check.item.logs.' . $key, [], $locale)), "$key in $locale");
                }
            }
        } finally {
            @rmdir($dir);
        }
    }
}
