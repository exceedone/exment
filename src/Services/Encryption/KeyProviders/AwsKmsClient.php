<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

/**
 * KmsClient with the AWS SDK (aws/aws-sdk-php, suggested by composer.json, not required). The
 * credentials are the SDK's default chain: on EC2 the role of the instance, no access key.
 */
class AwsKmsClient implements KmsClient
{
    /** @var mixed Aws\Kms\KmsClient (the SDK is optional: not known to the analysis) */
    protected $client;

    public function __construct(?string $region)
    {
        $class = '\\Aws\\Kms\\KmsClient';
        if (!class_exists($class)) {
            throw new KeyProviderException('The AWS SDK is not installed (composer require aws/aws-sdk-php).');
        }
        $this->client = new $class(AwsOptions::client('2014-11-01', $region));
    }

    public function decrypt(string $wrapped, array $context, string $keyId): string
    {
        try {
            $args = ['CiphertextBlob' => $wrapped, 'EncryptionContext' => $context];
            if ($keyId !== '') {
                $args['KeyId'] = $keyId;
            }
            $result = $this->client->decrypt($args);
            return strval($result['Plaintext'] ?? '');
        } catch (\Throwable $e) {
            throw new KeyProviderException('KMS Decrypt failed: ' . AwsOptions::reason($e));
        }
    }

    public function generateDataKey(string $keyId, array $context): array
    {
        try {
            $result = $this->client->generateDataKey(['KeyId' => $keyId, 'KeySpec' => 'AES_256', 'EncryptionContext' => $context]);
            return ['plain' => strval($result['Plaintext'] ?? ''), 'wrapped' => strval($result['CiphertextBlob'] ?? '')];
        } catch (\Throwable $e) {
            throw new KeyProviderException('KMS GenerateDataKey failed: ' . AwsOptions::reason($e));
        }
    }
}
