<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

/**
 * The provider of the data keys the settings ask for (ISS02929, config exment.data_key_provider).
 * A test sets $kms, $secrets or $http to fakes, so no call leaves the machine.
 */
class KeyProviders
{
    public const NAMES = [
        EnvKeyProvider::NAME,
        AwsKmsKeyProvider::NAME,
        AwsSecretsManagerKeyProvider::NAME,
        VaultTransitKeyProvider::NAME,
    ];

    /** @var KmsClient|null */
    public static $kms;

    /** @var SecretsClient|null */
    public static $secrets;

    /** @var \GuzzleHttp\ClientInterface|null */
    public static $http;

    /** The name in the settings; an unknown one is kept, so it is not taken for env. */
    public static function name(): string
    {
        $name = strtolower(trim(strval(config('exment.data_key_provider', EnvKeyProvider::NAME))));
        return $name === '' ? EnvKeyProvider::NAME : $name;
    }

    public static function isEnv(): bool
    {
        return static::name() === EnvKeyProvider::NAME;
    }

    /**
     * @throws KeyProviderException an unknown name, or the AWS SDK missing
     */
    public static function make(): KeyProvider
    {
        $name = static::name();
        $wrapped = strval(config('exment.data_key_wrapped', ''));
        switch ($name) {
            case EnvKeyProvider::NAME:
                return new EnvKeyProvider();
            case AwsKmsKeyProvider::NAME:
                return new AwsKmsKeyProvider(
                    static::$kms ?? new AwsKmsClient(static::region()),
                    strval(config('exment.data_key_kms_key_id', '')),
                    $wrapped,
                    strval(config('exment.data_key_context', ''))
                );
            case AwsSecretsManagerKeyProvider::NAME:
                return new AwsSecretsManagerKeyProvider(
                    static::$secrets ?? new AwsSecretsClient(static::region()),
                    strval(config('exment.data_key_secret_id', ''))
                );
            case VaultTransitKeyProvider::NAME:
                return new VaultTransitKeyProvider(static::$http ?? new \GuzzleHttp\Client(), [
                    'addr' => strval(config('exment.data_key_vault_addr', '')),
                    'mount' => strval(config('exment.data_key_vault_mount', 'transit')),
                    'key' => strval(config('exment.data_key_vault_key', '')),
                    'token' => strval(config('exment.data_key_vault_token', '')),
                    'role_id' => strval(config('exment.data_key_vault_role_id', '')),
                    'secret_id' => strval(config('exment.data_key_vault_secret_id', '')),
                    'namespace' => strval(config('exment.data_key_vault_namespace', '')),
                    'wrapped' => $wrapped,
                ]);
        }
        throw new KeyProviderException("Unknown EXMENT_DATA_KEY_PROVIDER \"{$name}\" (env, aws_kms, aws_secrets_manager or vault).");
    }

    protected static function region(): ?string
    {
        $region = strval(config('exment.data_key_aws_region', ''));
        return $region === '' ? null : $region;
    }

    /**
     * What tells one setting of the provider from another, for the cache of the keys: never a
     * secret (the token and the secret id are not in it, only whether they are set).
     */
    public static function fingerprint(): string
    {
        return hash('sha256', implode("\n", [
            static::name(),
            strval(config('exment.data_key_wrapped', '')),
            strval(config('exment.data_key_kms_key_id', '')),
            strval(config('exment.data_key_context', '')),
            strval(config('exment.data_key_secret_id', '')),
            strval(config('exment.data_key_vault_addr', '')),
            strval(config('exment.data_key_vault_key', '')),
            config('app.key'),
        ]));
    }
}
