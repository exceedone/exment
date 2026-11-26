<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

/**
 * SecretsClient with the AWS SDK (suggested, not required); the SDK's default credentials.
 */
class AwsSecretsClient implements SecretsClient
{
    /** @var mixed Aws\SecretsManager\SecretsManagerClient (the SDK is optional) */
    protected $client;

    public function __construct(?string $region)
    {
        $class = '\\Aws\\SecretsManager\\SecretsManagerClient';
        if (!class_exists($class)) {
            throw new KeyProviderException('The AWS SDK is not installed (composer require aws/aws-sdk-php).');
        }
        $this->client = new $class(AwsOptions::client('2017-10-17', $region));
    }

    public function secretString(string $secretId): string
    {
        try {
            $result = $this->client->getSecretValue(['SecretId' => $secretId]);
            return strval($result['SecretString'] ?? '');
        } catch (\Throwable $e) {
            throw new KeyProviderException('Secrets Manager GetSecretValue failed: ' . AwsOptions::reason($e));
        }
    }
}
