<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\Encryption\DataKeys;
use Exceedone\Exment\Services\Encryption\EncryptedValue;
use Exceedone\Exment\Services\Encryption\KeyProviders\AwsKmsKeyProvider;
use Exceedone\Exment\Services\Encryption\KeyProviders\AwsSecretsManagerKeyProvider;
use Exceedone\Exment\Services\Encryption\KeyProviders\KeyProviderException;
use Exceedone\Exment\Services\Encryption\KeyProviders\KeyProviders;
use Exceedone\Exment\Services\Encryption\KeyProviders\KmsClient;
use Exceedone\Exment\Services\Encryption\KeyProviders\SecretsClient;
use Exceedone\Exment\Services\Encryption\KeyProviders\VaultTransitKeyProvider;
use Exceedone\Exment\Services\Sync\SyncSecrets;
use Exceedone\Exment\Services\SystemRequire\Security\DataKeySecurity;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Where the keys of the encrypted columns come from (ISS02929): env as before, AWS KMS (data keys
 * wrapped, opened with the encryption context of the site), Secrets Manager and Vault Transit.
 * The services are fakes: nothing leaves the machine.
 */
class DataKeyProvidersTest extends UnitTestBase
{
    /** @var FakeKms */
    protected $kms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kms = new FakeKms();
        KeyProviders::$kms = $this->kms;
        config(['exment.data_key_cache_seconds' => 0]);
    }

    protected function tearDown(): void
    {
        KeyProviders::$kms = null;
        KeyProviders::$secrets = null;
        KeyProviders::$http = null;
        config([
            'exment.data_key_provider' => 'env',
            'exment.data_key_wrapped' => '',
            'exment.data_key_context' => '',
            'exment.data_key_kms_key_id' => '',
            'exment.data_key_secret_id' => '',
            'exment.data_encryption_keys' => '',
        ]);
        DataKeys::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function use(array $config): void
    {
        config($config);
        DataKeys::reset();
    }

    public function testEnvIsAsBefore(): void
    {
        $this->use(['exment.data_key_provider' => 'env', 'exment.data_encryption_keys' => 'k2:' . base64_encode(str_repeat('b', 32)) . ',bad key,k1:' . base64_encode(str_repeat('a', 32))]);
        $this->assertSame(['k2', 'k1'], array_keys(DataKeys::all()));
        $this->assertNull(DataKeys::unavailable());
        $this->assertTrue(DataKeys::configured());
        $this->assertSame(SystemRequireResult::OK, (new DataKeySecurity())->checkResult());

        $this->use(['exment.data_encryption_keys' => '']);
        $this->assertFalse(DataKeys::configured());
        $this->assertTrue(SyncSecrets::usesAppKey());
    }

    public function testKmsOpensTheWrappedKeysWithTheContextOfTheSite(): void
    {
        $this->use(['exment.data_key_provider' => 'aws_kms', 'exment.data_key_kms_key_id' => 'alias/exment', 'exment.data_key_context' => 'tenant-a']);
        $made = KeyProviders::make()->generate('k1');
        // only the wrapped key is printed
        $this->assertStringStartsWith('EXMENT_DATA_KEY_WRAPPED="k1:', $made['setting']);
        $this->assertStringNotContainsString(base64_encode($this->kms->lastPlain), $made['setting']);
        $this->assertSame(['app' => 'exment', 'key_id' => 'k1', 'site' => 'tenant-a'], $this->kms->lastContext);

        $this->use(['exment.data_key_wrapped' => $made['pair']]);
        $this->assertSame(['k1'], array_keys(DataKeys::all()));
        $this->assertSame($this->kms->lastPlain, DataKeys::all()['k1']);
        $this->assertSame(1, $this->kms->decrypts);

        // a value encrypted and read again; the keys are read once a request
        $encrypted = EncryptedValue::encrypt('secret value');
        $this->assertSame('secret value', EncryptedValue::decrypt($encrypted));
        $this->assertSame(1, $this->kms->decrypts);

        // copied to another site: KMS does not open it (the context differs)
        $this->use(['exment.data_key_context' => 'tenant-b']);
        $this->assertSame([], DataKeys::all());
        $this->assertNotNull(DataKeys::unavailable());
        $this->assertNull(EncryptedValue::decrypt($encrypted));
    }

    public function testKmsNotReachedFallsBackToNothing(): void
    {
        $this->use(['exment.data_key_provider' => 'aws_kms', 'exment.data_key_kms_key_id' => 'alias/exment']);
        $made = KeyProviders::make()->generate('k1');
        $this->use(['exment.data_key_wrapped' => $made['pair']]);
        $encrypted = EncryptedValue::encrypt('kept');

        $this->kms->down = true;
        DataKeys::reset();
        $this->assertSame([], DataKeys::all());
        $this->assertStringContainsString('KMS', strval(DataKeys::unavailable()));
        // still "configured": nothing goes to APP_KEY meanwhile
        $this->assertTrue(DataKeys::configured());
        $this->assertFalse(SyncSecrets::usesAppKey());
        $this->assertNull(EncryptedValue::decrypt($encrypted));
        try {
            EncryptedValue::encrypt('new');
            $this->fail('a value was encrypted without a key');
        } catch (\RuntimeException $e) {
            $this->assertSame(strval(exmtrans('encrypted.unavailable_save')), $e->getMessage());
        }
        try {
            SyncSecrets::encrypt('token');
            $this->fail('a secret went to APP_KEY while KMS was down');
        } catch (\RuntimeException $e) {
            $this->assertSame(strval(exmtrans('encrypted.unavailable_save')), $e->getMessage());
        }
        $this->assertSame(SystemRequireResult::NG, (new DataKeySecurity())->checkResult());

        // back again
        $this->kms->down = false;
        DataKeys::reset();
        $this->assertSame('kept', EncryptedValue::decrypt($encrypted));
        $this->assertSame(SystemRequireResult::OK, (new DataKeySecurity())->checkResult());
    }

    public function testSecretsManager(): void
    {
        $keys = 'k2:' . base64_encode(str_repeat('d', 32)) . ',k1:' . base64_encode(str_repeat('c', 32));
        $secrets = new FakeSecrets(['exment/keys' => $keys, 'exment/json' => json_encode(['keys' => $keys])]);
        KeyProviders::$secrets = $secrets;
        $this->use(['exment.data_key_provider' => 'aws_secrets_manager', 'exment.data_key_secret_id' => 'exment/keys']);
        $this->assertSame(['k2', 'k1'], array_keys(DataKeys::all()));
        $this->assertSame(str_repeat('d', 32), DataKeys::all()['k2']);

        $this->use(['exment.data_key_secret_id' => 'exment/json']);
        $this->assertSame(['k2', 'k1'], array_keys(DataKeys::all()));

        $made = KeyProviders::make()->generate('k3');
        $this->assertStringContainsString('exment/json', $made['setting']);
        $this->assertStringEndsWith(',' . $keys, $made['setting']);

        $this->use(['exment.data_key_secret_id' => 'nothing']);
        $this->assertSame([], DataKeys::all());
        $this->assertNotNull(DataKeys::unavailable());
    }

    public function testVaultTransitWithATokenAndWithAppRole(): void
    {
        $raw = str_repeat('e', 32);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            // token: decrypt
            new Response(200, [], (string) json_encode(['data' => ['plaintext' => base64_encode($raw)]])),
            // AppRole: login, then decrypt
            new Response(200, [], (string) json_encode(['auth' => ['client_token' => 's.approle']])),
            new Response(200, [], (string) json_encode(['data' => ['plaintext' => base64_encode($raw)]])),
            // down
            new Response(503, [], 'sealed'),
        ]));
        $stack->push(Middleware::history($history));
        KeyProviders::$http = new Client(['handler' => $stack]);

        $this->use([
            'exment.data_key_provider' => 'vault',
            'exment.data_key_vault_addr' => 'https://vault.example.test:8200/',
            'exment.data_key_vault_key' => 'exment',
            'exment.data_key_vault_token' => 's.token',
            'exment.data_key_wrapped' => 'k1:vault:v1:abcdef==',
        ]);
        $this->assertSame(['k1' => $raw], DataKeys::all());
        $first = $history[0]['request'];
        $this->assertSame('https://vault.example.test:8200/v1/transit/decrypt/exment', strval($first->getUri()));
        $this->assertSame('s.token', $first->getHeaderLine('X-Vault-Token'));
        $this->assertSame(['ciphertext' => 'vault:v1:abcdef=='], json_decode((string) $first->getBody(), true));

        $this->use(['exment.data_key_vault_token' => '', 'exment.data_key_vault_role_id' => 'role', 'exment.data_key_vault_secret_id' => 'secret']);
        $this->assertSame(['k1' => $raw], DataKeys::all());
        $this->assertSame('https://vault.example.test:8200/v1/auth/approle/login', strval($history[1]['request']->getUri()));
        $this->assertSame('', $history[1]['request']->getHeaderLine('X-Vault-Token'));
        $this->assertSame('s.approle', $history[2]['request']->getHeaderLine('X-Vault-Token'));

        $this->use(['exment.data_key_vault_token' => 's.token']);
        $this->assertSame([], DataKeys::all());
        $this->assertStringContainsString('503', strval(DataKeys::unavailable()));
    }

    public function testAnUnknownProviderIsNotTakenForEnv(): void
    {
        $this->use(['exment.data_key_provider' => 'aws_kmss', 'exment.data_encryption_keys' => 'k1:' . base64_encode(str_repeat('a', 32))]);
        $this->assertSame([], DataKeys::all());
        $this->assertStringContainsString('aws_kmss', strval(DataKeys::unavailable()));
        $this->assertTrue(DataKeys::configured());
    }

    public function testTheMessagesHoldNoSecret(): void
    {
        $this->use(['exment.data_key_provider' => 'aws_kms', 'exment.data_key_kms_key_id' => 'alias/exment', 'exment.data_key_wrapped' => 'k1:' . base64_encode('wrapped-blob')]);
        $this->kms->down = true;
        DataKeys::all();
        $this->assertStringNotContainsString('wrapped-blob', strval(DataKeys::unavailable()));
        $this->assertStringNotContainsString(base64_encode('wrapped-blob'), strval(DataKeys::unavailable()));

        $this->expectException(KeyProviderException::class);
        (new AwsKmsKeyProvider($this->kms, '', '', ''))->generate('k1');
    }

    public function testTheProvidersAreNamed(): void
    {
        $this->assertSame(['env', 'aws_kms', 'aws_secrets_manager', 'vault'], KeyProviders::NAMES);
        $this->assertSame('aws_secrets_manager', (new AwsSecretsManagerKeyProvider(new FakeSecrets([]), 'x'))->name());
        $this->assertSame('vault', (new VaultTransitKeyProvider(new Client(), ['addr' => '', 'mount' => '', 'key' => '', 'token' => '', 'role_id' => '', 'secret_id' => '', 'namespace' => '', 'wrapped' => '']))->name());
    }
}

/**
 * A KMS: "wraps" by XOR with a key of its own and the context, so a wrong context opens nothing.
 */
class FakeKms implements KmsClient
{
    /** @var bool */
    public $down = false;

    /** @var int */
    public $decrypts = 0;

    /** @var string */
    public $lastPlain = '';

    /** @var array<string, string> */
    public $lastContext = [];

    /**
     * @param array<string, string> $context
     */
    protected function wrap(string $plain, array $context): string
    {
        ksort($context);
        return 'FAKEKMS' . hash('sha256', (string) json_encode($context)) . ($plain ^ str_repeat('K', strlen($plain)));
    }

    public function decrypt(string $wrapped, array $context, string $keyId): string
    {
        if ($this->down) {
            throw new KeyProviderException('KMS Decrypt failed: RequestTimeout');
        }
        $this->decrypts++;
        ksort($context);
        $head = 'FAKEKMS' . hash('sha256', (string) json_encode($context));
        if (!str_starts_with($wrapped, $head)) {
            throw new KeyProviderException('KMS Decrypt failed: InvalidCiphertextException');
        }
        $body = substr($wrapped, strlen($head));
        return $body ^ str_repeat('K', strlen($body));
    }

    public function generateDataKey(string $keyId, array $context): array
    {
        if ($this->down) {
            throw new KeyProviderException('KMS GenerateDataKey failed: RequestTimeout');
        }
        $plain = random_bytes(32);
        $this->lastPlain = $plain;
        $this->lastContext = $context;
        return ['plain' => $plain, 'wrapped' => $this->wrap($plain, $context)];
    }
}

class FakeSecrets implements SecretsClient
{
    /** @var array<string, string> */
    protected $secrets;

    /**
     * @param array<string, string|false> $secrets
     */
    public function __construct(array $secrets)
    {
        $this->secrets = array_map('strval', $secrets);
    }

    public function secretString(string $secretId): string
    {
        if (!isset($this->secrets[$secretId])) {
            throw new KeyProviderException('Secrets Manager GetSecretValue failed: ResourceNotFoundException');
        }
        return $this->secrets[$secretId];
    }
}
