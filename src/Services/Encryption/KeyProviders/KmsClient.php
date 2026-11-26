<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

/**
 * The calls of AWS KMS the data keys use (ISS02929). AwsKmsClient makes them with the AWS SDK;
 * a test gives a fake, so the CI never calls AWS.
 */
interface KmsClient
{
    /**
     * The plain data key of a wrapped one (kms:Decrypt).
     *
     * @param array<string, string> $context the encryption context the key was wrapped with
     * @throws KeyProviderException
     */
    public function decrypt(string $wrapped, array $context, string $keyId): string;

    /**
     * A new data key of 32 bytes (kms:GenerateDataKey, AES_256).
     *
     * @param array<string, string> $context
     * @return array{plain: string, wrapped: string}
     * @throws KeyProviderException
     */
    public function generateDataKey(string $keyId, array $context): array;
}
