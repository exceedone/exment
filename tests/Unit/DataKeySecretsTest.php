<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\EncryptedValue;
use Exceedone\Exment\Services\Encryption\Secrets;
use Exceedone\Exment\Services\Sync\SyncSecrets;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * The secrets of the settings (mail OAuth, LINE WORKS) kept with the keys of the encrypted
 * columns when the site has them (ISS02929), else with APP_KEY as before; read whichever they
 * are, and saved again the way of now.
 */
class DataKeySecretsTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        $this->keys('');
        config(['exment.data_key_provider' => 'env']);
        DataKeys::reset();
        System::clearCache();
        parent::tearDown();
    }

    protected function keys(string $keys): void
    {
        config(['exment.data_encryption_keys' => $keys]);
        DataKeys::reset();
        System::clearCache();
    }

    protected function raw(string $name): string
    {
        return strval(System::query()->where('system_name', $name)->value('system_value'));
    }

    public function testWithoutKeysAsBefore(): void
    {
        $this->keys('');
        System::system_lineworks_client_secret('lw-secret');
        $raw = $this->raw('system_lineworks_client_secret');
        // the bare payload of encrypt(), as the settings kept it
        $this->assertFalse(EncryptedValue::isEncrypted($raw));
        $this->assertStringStartsNotWith(Secrets::APP_PREFIX, $raw);
        $this->assertSame('lw-secret', decrypt($raw));
        System::clearCache();
        $this->assertSame('lw-secret', System::system_lineworks_client_secret());
    }

    public function testWithKeysTheColumnKeysAndTheOldValuesStillRead(): void
    {
        // saved before the keys: APP_KEY
        $this->keys('');
        System::system_mail_oauth_client_secret('old-secret');
        $before = $this->raw('system_mail_oauth_client_secret');

        $this->keys('k1:' . base64_encode(str_repeat('a', 32)));
        $this->assertSame('old-secret', System::system_mail_oauth_client_secret());
        $this->assertTrue(Secrets::needsResave($before));

        // saved now: the keys of the columns (a backup does not keep them)
        System::system_mail_oauth_refresh_token('refresh-token');
        $raw = $this->raw('system_mail_oauth_refresh_token');
        $this->assertTrue(EncryptedValue::isEncrypted($raw));
        $this->assertStringNotContainsString('refresh-token', $raw);
        System::clearCache();
        $this->assertSame('refresh-token', System::system_mail_oauth_refresh_token());

        // "rotate" saves the old one again
        $result = Secrets::resaveSettings();
        $this->assertGreaterThanOrEqual(1, $result['saved']);
        $this->assertTrue(EncryptedValue::isEncrypted($this->raw('system_mail_oauth_client_secret')));
        $this->assertSame('old-secret', System::system_mail_oauth_client_secret());

        // a setting without "data_key" is as before
        System::system_mail_password('mail-pass');
        $this->assertFalse(EncryptedValue::isEncrypted($this->raw('system_mail_password')));
    }

    public function testTheServiceNotReachedReadsNothingAndSavesNothing(): void
    {
        $this->keys('k1:' . base64_encode(str_repeat('a', 32)));
        System::system_lineworks_private_key('-----BEGIN PRIVATE KEY-----x');

        // an unknown provider stands for one not reached: configured, no keys
        config(['exment.data_key_provider' => 'not_reached']);
        DataKeys::reset();
        System::clearCache();
        $this->assertNull(System::system_lineworks_private_key());
        $this->assertFalse(SyncSecrets::usesAppKey());
        try {
            System::system_lineworks_private_key('another');
            $this->fail('a secret was saved with APP_KEY while the keys could not be read');
        } catch (\RuntimeException $e) {
            $this->assertSame(strval(exmtrans('encrypted.unavailable_save')), $e->getMessage());
        }
        $this->assertSame(0, Secrets::resaveSettings()['saved']);
    }

    public function testSyncSecretsGoTheSameWay(): void
    {
        $this->keys('');
        $app = SyncSecrets::encrypt('t');
        $this->assertStringStartsWith(SyncSecrets::APP_PREFIX, $app);
        $this->assertSame('t', SyncSecrets::decrypt($app));
        // never the bare payload
        $this->assertNull(SyncSecrets::decrypt(encrypt('t')));

        $this->keys('k1:' . base64_encode(str_repeat('a', 32)));
        $enc = SyncSecrets::encrypt('t');
        $this->assertTrue(EncryptedValue::isEncrypted($enc));
        $this->assertSame('t', SyncSecrets::decrypt($enc));
        $this->assertSame('t', SyncSecrets::decrypt($app));
    }
}
