<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

/**
 * The call of AWS Secrets Manager the data keys use (ISS02929). AwsSecretsClient makes it with
 * the AWS SDK; a test gives a fake.
 */
interface SecretsClient
{
    /**
     * The SecretString of a secret (secretsmanager:GetSecretValue).
     *
     * @throws KeyProviderException
     */
    public function secretString(string $secretId): string;
}
