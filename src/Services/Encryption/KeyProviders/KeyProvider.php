<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

/**
 * Where the keys of the encrypted columns come from (ISS02929, config exment.data_key_provider):
 * env (DATA_ENCRYPTION_KEYS, the default), aws_kms, aws_secrets_manager or vault.
 *
 * A provider gives the plain keys, the current one first, for DataKeys to keep in memory only.
 * It throws KeyProviderException when it cannot be reached: DataKeys never falls back to
 * another place then.
 */
interface KeyProvider
{
    /** The name of the provider (the value of EXMENT_DATA_KEY_PROVIDER). */
    public function name(): string;

    /**
     * @return array<string, string> id => raw key (32 bytes), the current one first
     * @throws KeyProviderException
     */
    public function keys(): array;

    /**
     * A new key with this id, as it is written where the provider reads it.
     *
     * @return array{id: string, pair: string, setting: string}
     *   pair: "id:..." to put first; setting: where to put it (a line of .env, or the secret)
     * @throws KeyProviderException
     */
    public function generate(string $id): array;
}
