<?php

namespace Exceedone\Exment\Tests\Unit;

use Aws\S3\S3Client;
use Exceedone\Exment\Storage\Adapter\ExmentAdapterS3;
use Illuminate\Support\Facades\Config;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

/**
 * #1553: the S3 client always got "credentials" from AWS_ACCESS_KEY_ID and
 * AWS_SECRET_ACCESS_KEY, also when they were empty, so the IAM role of an EC2 instance (or of an
 * ECS task) could not be used.
 *
 * "credentials" is now passed only when the key and the secret are both set. Without them the
 * AWS SDK looks for the credentials by itself (default credential provider chain).
 */
class S3AdapterCredentialsTest extends UnitTestBase
{
    protected function setUp(): void
    {
        parent::setUp();

        // the S3 driver is optional (league/flysystem-aws-s3-v3)
        if (!class_exists(AwsS3V3Adapter::class) || !class_exists(S3Client::class)) {
            $this->markTestSkipped('league/flysystem-aws-s3-v3 is not installed.');
        }
    }

    public function testKeyAndSecretArePassedAsCredentials(): void
    {
        $clientConfig = ExmentAdapterS3::getClientConfig([
            'key' => 'AKIAISSUE1553',
            'secret' => 'secret1553',
            'region' => 'ap-northeast-1',
            'bucket' => 'bucket1553',
        ]);

        $this->assertSame(['key' => 'AKIAISSUE1553', 'secret' => 'secret1553'], $clientConfig['credentials']);
        $this->assertSame('ap-northeast-1', $clientConfig['region']);
        $this->assertSame('bucket1553', $clientConfig['bucket']);
        $this->assertSame('latest', $clientConfig['version']);
    }

    public function testSessionTokenIsPassedWithKeyAndSecret(): void
    {
        $clientConfig = ExmentAdapterS3::getClientConfig([
            'key' => 'AKIAISSUE1553',
            'secret' => 'secret1553',
            'token' => 'token1553',
            'region' => 'ap-northeast-1',
            'bucket' => 'bucket1553',
        ]);

        $this->assertSame(
            ['key' => 'AKIAISSUE1553', 'secret' => 'secret1553', 'token' => 'token1553'],
            $clientConfig['credentials']
        );
    }

    /**
     * @param array<string, mixed> $credentials
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyCredentialsProvider')]
    public function testCredentialsAreLeftToTheSdkWhenKeyOrSecretIsEmpty(array $credentials): void
    {
        $clientConfig = ExmentAdapterS3::getClientConfig($credentials + [
            'region' => 'ap-northeast-1',
            'bucket' => 'bucket1553',
        ]);

        $this->assertArrayNotHasKey('credentials', $clientConfig);
        $this->assertSame('ap-northeast-1', $clientConfig['region']);
        $this->assertSame('bucket1553', $clientConfig['bucket']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function emptyCredentialsProvider(): array
    {
        return [
            'not defined' => [[]],
            'null (AWS_ACCESS_KEY_ID is not in .env)' => [['key' => null, 'secret' => null]],
            'empty string (AWS_ACCESS_KEY_ID= in .env)' => [['key' => '', 'secret' => '']],
            'only the key' => [['key' => 'AKIAISSUE1553', 'secret' => '']],
            'only the secret' => [['key' => null, 'secret' => 'secret1553']],
            'a token without the key' => [['key' => '', 'secret' => '', 'token' => 'token1553']],
        ];
    }

    public function testEndpointAndUrlAreStillPassed(): void
    {
        $clientConfig = ExmentAdapterS3::getClientConfig([
            'key' => '',
            'secret' => '',
            'region' => 'ap-northeast-1',
            'bucket' => 'bucket1553',
            'endpoint' => 'http://minio.example.com:9000',
            'url' => 'http://minio.example.com:9000/bucket1553',
        ]);

        $this->assertSame('http://minio.example.com:9000', $clientConfig['endpoint']);
        $this->assertSame('http://minio.example.com:9000/bucket1553', $clientConfig['url']);
    }

    /**
     * The adapter is created without any request to AWS in both cases: the SDK looks for the
     * credentials only when the first request is sent.
     */
    public function testAdapterIsCreatedWithAndWithoutKeys(): void
    {
        Config::set('exment.rootpath.s3.exment', 'bucket1553');

        foreach ([['AKIAISSUE1553', 'secret1553'], [null, null]] as [$key, $secret]) {
            Config::set('filesystems.disks.s3', [
                'driver' => 's3',
                'key' => $key,
                'secret' => $secret,
                'region' => 'ap-northeast-1',
                'bucket' => null,
            ]);

            $adapter = ExmentAdapterS3::getAdapter($this->app, ['mergeFrom' => 'exment'], 'exment');

            $this->assertInstanceOf(ExmentAdapterS3::class, $adapter);
        }
    }

    public function testKeysOfTheConfigAreUsedByTheClient(): void
    {
        $client = new S3Client(ExmentAdapterS3::getClientConfig([
            'key' => 'AKIAISSUE1553',
            'secret' => 'secret1553',
            'region' => 'ap-northeast-1',
            'bucket' => 'bucket1553',
        ]));

        /** @var \Aws\Credentials\CredentialsInterface $credentials */
        $credentials = $client->getCredentials()->wait();

        $this->assertSame('AKIAISSUE1553', $credentials->getAccessKeyId());
        $this->assertSame('secret1553', $credentials->getSecretKey());
    }
}
