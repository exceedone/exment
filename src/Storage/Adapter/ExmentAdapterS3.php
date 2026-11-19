<?php

namespace Exceedone\Exment\Storage\Adapter;

use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\PathPrefixer;

class ExmentAdapterS3 extends AwsS3V3Adapter implements ExmentAdapterInterface
{
    use AdapterTrait;

    /**
     * Override EXTRA_METADATA_FIELDS because EXTRA_METADATA_FIELDS is private
     * @var string[]
     */
    protected const EXTRA_METADATA_FIELDS = [
        'Metadata',
        'StorageClass',
        'ETag',
        'VersionId',
    ];

    /**
     * Keep the client, the bucket and the prefix, because the ones of the parent are private.
     *
     * @var S3ClientInterface
     */
    protected $s3Client;

    /**
     * @var string
     */
    protected $s3Bucket;

    /**
     * @var PathPrefixer
     */
    protected $s3Prefixer;

    /**
     * @param S3ClientInterface $client
     * @param string $bucket
     * @param string $prefix
     * @param mixed ...$args the other arguments of the parent, passed as they are
     */
    public function __construct(S3ClientInterface $client, string $bucket, string $prefix = '', ...$args)
    {
        parent::__construct($client, $bucket, $prefix, ...$args);

        $this->s3Client = $client;
        $this->s3Bucket = $bucket;
        $this->s3Prefixer = new PathPrefixer($prefix);
    }

    /**
     * Get an url the client can use to download the file from S3 itself.
     *
     * Having this method makes Storage::disk(...)->providesTemporaryUrls() true, so Exment
     * sends the client to S3 instead of reading the file and forwarding it. S3 checks the
     * expiration when the request starts: a download already running is not cut when the url
     * expires, but a download restarted after that time fails.
     *
     * @param string $path
     * @param \DateTimeInterface $expiration
     * @param array<string, mixed> $options options of the GetObject command, as ResponseContentDisposition
     * @return string
     */
    public function getTemporaryUrl(string $path, $expiration, array $options = []): string
    {
        $command = $this->s3Client->getCommand('GetObject', array_merge([
            'Bucket' => $this->s3Bucket,
            'Key' => $this->s3Prefixer->prefixPath($path),
        ], $options));

        return (string) $this->s3Client->createPresignedRequest(
            $command,
            $expiration,
            $options
        )->getUri();
    }

    /**
     * get adapter class
     */
    // @phpstan-ignore-next-line
    public static function getAdapter($app, $config, $driverKey)
    {
        $mergeConfig = static::getConfig($config);

        $client = new S3Client(static::getClientConfig($mergeConfig));
        return new self($client, array_get($mergeConfig, 'bucket'));
    }

    /**
     * Get the config of the S3 client.
     *
     * The access key and the secret are passed only when both are set. When one of them is
     * empty, "credentials" is left out and the AWS SDK looks for the credentials by itself
     * (environment variables, the shared credentials file, the IAM role of the ECS task or of
     * the EC2 instance, ...), so an IAM role can be used without saving any key.
     *
     * @param array<string, mixed> $mergeConfig
     * @return array<string, mixed>
     */
    public static function getClientConfig(array $mergeConfig): array
    {
        $clientConfig = [
            'region' => array_get($mergeConfig, 'region'),
            'version' => 'latest',
            'bucket' => array_get($mergeConfig, 'bucket'),
        ];

        $key = array_get($mergeConfig, 'key');
        $secret = array_get($mergeConfig, 'secret');
        if (!is_nullorempty($key) && !is_nullorempty($secret)) {
            $clientConfig['credentials'] = [
                'key'    => $key,
                'secret' => $secret,
            ];
            // the session token of temporary credentials (AWS_SESSION_TOKEN)
            $token = array_get($mergeConfig, 'token');
            if (!is_nullorempty($token)) {
                $clientConfig['credentials']['token'] = $token;
            }
        }

        foreach (['endpoint', 'url'] as $optionKey) {
            if (array_key_value_exists($optionKey, $mergeConfig)) {
                $clientConfig[$optionKey] = $mergeConfig[$optionKey];
            }
        }

        return $clientConfig;
    }

    // @phpstan-ignore-next-line
    public static function getMergeConfigKeys(string $mergeFrom, array $options = []): array
    {
        return [
            'bucket' => config('exment.rootpath.s3.' . $mergeFrom),
        ];
    }

    /**
     * Get config. Execute merge.
     *
     * @param array $config
     * @return array
     */
    // @phpstan-ignore-next-line
    public static function getConfig($config): array
    {
        $mergeFrom = array_get($config, 'mergeFrom');
        $mergeConfig = static::mergeFileConfig('filesystems.disks.s3', "filesystems.disks.$mergeFrom", $mergeFrom);
        if (!array_key_exists('ACL', $mergeConfig)) {
            $mergeConfig['ACL'] = 'private';
        }
        return $mergeConfig;
    }
}
