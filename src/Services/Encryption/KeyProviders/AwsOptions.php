<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

/**
 * What the AWS clients of the data keys share.
 */
class AwsOptions
{
    /**
     * The options of a client: short time limits, so an outage makes the pages wait a little only.
     *
     * @return array<string, mixed>
     */
    public static function client(string $version, ?string $region): array
    {
        return [
            'version' => $version,
            // config exment.data_key_aws_region (EXMENT_DATA_KEY_AWS_REGION, else AWS_DEFAULT_REGION)
            'region' => $region ?: 'ap-northeast-1',
            'http' => ['connect_timeout' => 3, 'timeout' => 5],
            'retries' => 1,
        ];
    }

    /**
     * Why a call failed, without what it carried: the AWS error code when there is one.
     */
    public static function reason(\Throwable $e): string
    {
        if (method_exists($e, 'getAwsErrorCode')) {
            $code = $e->getAwsErrorCode();
            if (is_string($code) && $code !== '') {
                return $code;
            }
        }
        return class_basename($e);
    }
}
