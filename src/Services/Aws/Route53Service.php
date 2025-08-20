<?php

namespace Exceedone\Exment\Services\Aws;

use Aws\Route53\Route53Client;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;

class Route53Service
{
    protected Route53Client $client;
    protected string $hostedZoneId;
    protected string $baseDomain;

    public function __construct()
    {
        $this->hostedZoneId = config('exment.tenant.route53.hosted_zone_id');
        $this->baseDomain = config('exment.tenant.base_domain');

        if ($this->hostedZoneId) {

            $this->client = new Route53Client([
                'version' => 'latest',
                'region' => config('exment.tenant.route53.region', 'ap-northeast-1'),
                'credentials' => $this->getCredentials()
            ]);
        }
    }

    /**
     * Create subdomain record in Route53
     *
     * @param string $fqdn
     * @return array
     */
    public function createSubdomainRecord(string $fqdn): array
    {
        try {
            $target = config('exment.tenant.route53.target_ip') ?: config('exment.tenant.route53.target_alias');

            if (empty($target)) {
                return [
                    'success' => false,
                    'error' => 'MISSING_TARGET',
                    'message' => 'Route53 target not configured',
                    'status' => 500
                ];
            }

            $changeBatch = $this->buildChangeBatch($fqdn, $target, 'CREATE');

            $result = $this->client->changeResourceRecordSets([
                'HostedZoneId' => $this->hostedZoneId,
                'ChangeBatch' => $changeBatch
            ]);

            Log::info('Route53 record created successfully', [
                'fqdn' => $fqdn,
                'change_id' => $result['ChangeInfo']['Id'] ?? null
            ]);

            return [
                'success' => true,
                'data' => [
                    'change_id' => $result['ChangeInfo']['Id'] ?? null,
                    'status' => $result['ChangeInfo']['Status'] ?? 'PENDING',
                    'fqdn' => $fqdn
                ]
            ];
        } catch (AwsException $e) {
            Log::error('Route53 record creation failed', [
                'fqdn' => $fqdn,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'ROUTE53_CREATE_FAILED',
                'message' => 'Failed to create Route53 record: ' . $e->getMessage(),
                'status' => 500
            ];
        } catch (\Exception $e) {
            Log::error('Route53 record creation failed (non-AWS error)', [
                'fqdn' => $fqdn,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'ROUTE53_CREATE_FAILED',
                'message' => 'Failed to create Route53 record: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Delete subdomain record from Route53
     *
     * @param string $fqdn
     * @return array
     */
    public function deleteSubdomainRecord(string $fqdn): array
    {
        try {
            $target = config('exment.tenant.route53.target_ip') ?: config('exment.tenant.route53.target_alias');

            if (empty($target)) {
                return [
                    'success' => false,
                    'error' => 'MISSING_TARGET',
                    'message' => 'Route53 target not configured',
                    'status' => 500
                ];
            }

            $changeBatch = $this->buildChangeBatch($fqdn, $target, 'DELETE');

            $result = $this->client->changeResourceRecordSets([
                'HostedZoneId' => $this->hostedZoneId,
                'ChangeBatch' => $changeBatch
            ]);

            Log::info('Route53 record deleted successfully', [
                'fqdn' => $fqdn,
                'change_id' => $result['ChangeInfo']['Id'] ?? null
            ]);

            return [
                'success' => true,
                'data' => [
                    'change_id' => $result['ChangeInfo']['Id'] ?? null,
                    'status' => $result['ChangeInfo']['Status'] ?? 'PENDING',
                    'fqdn' => $fqdn
                ]
            ];
        } catch (AwsException $e) {
            Log::error('Route53 record deletion failed', [
                'fqdn' => $fqdn,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'ROUTE53_DELETE_FAILED',
                'message' => 'Failed to delete Route53 record: ' . $e->getMessage(),
                'status' => 500
            ];
        } catch (\Exception $e) {
            Log::error('Route53 record deletion failed (non-AWS error)', [
                'fqdn' => $fqdn,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'ROUTE53_DELETE_FAILED',
                'message' => 'Failed to delete Route53 record: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Check record status in Route53
     *
     * @param string $fqdn
     * @return array
     */
    public function checkRecordStatus(string $fqdn): array
    {
        try {
            $result = $this->client->listResourceRecordSets([
                'HostedZoneId' => $this->hostedZoneId,
                'StartRecordName' => $fqdn,
                'MaxItems' => '1'
            ]);

            $records = $result['ResourceRecordSets'] ?? [];
            $record = null;

            foreach ($records as $rec) {
                if ($rec['Name'] === $fqdn . '.') {
                    $record = $rec;
                    break;
                }
            }

            if (!$record) {
                return [
                    'success' => true,
                    'data' => [
                        'exists' => false,
                        'status' => 'NOT_FOUND'
                    ]
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'exists' => true,
                    'type' => $record['Type'] ?? 'UNKNOWN',
                    'ttl' => $record['TTL'] ?? null,
                    'status' => 'ACTIVE'
                ]
            ];
        } catch (AwsException $e) {
            Log::error('Route53 record status check failed', [
                'fqdn' => $fqdn,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'ROUTE53_STATUS_CHECK_FAILED',
                'message' => 'Failed to check Route53 record status: ' . $e->getMessage(),
                'status' => 500
            ];
        } catch (\Exception $e) {
            Log::error('Route53 record status check failed (non-AWS error)', [
                'fqdn' => $fqdn,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'ROUTE53_STATUS_CHECK_FAILED',
                'message' => 'Failed to check Route53 record status: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Wait for Route53 change to complete
     *
     * @param string $changeId
     * @param int $maxWaitSeconds
     * @return array
     */
    public function waitForChange(string $changeId, int $maxWaitSeconds = 300): array
    {
        try {
            $startTime = time();

            while (time() - $startTime < $maxWaitSeconds) {
                $result = $this->client->getChange([
                    'Id' => $changeId
                ]);

                $status = $result['ChangeInfo']['Status'] ?? 'UNKNOWN';

                if ($status === 'INSYNC') {
                    return [
                        'success' => true,
                        'data' => [
                            'status' => $status,
                            'waited_seconds' => time() - $startTime
                        ]
                    ];
                }

                if ($status === 'FAILED') {
                    return [
                        'success' => false,
                        'error' => 'ROUTE53_CHANGE_FAILED',
                        'message' => 'Route53 change failed',
                        'status' => 500
                    ];
                }

                // Wait 10 seconds before checking again
                sleep(10);
            }

            return [
                'success' => false,
                'error' => 'ROUTE53_CHANGE_TIMEOUT',
                'message' => 'Route53 change did not complete within timeout period',
                'status' => 408
            ];
        } catch (AwsException $e) {
            Log::error('Route53 change wait failed', [
                'change_id' => $changeId,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'ROUTE53_WAIT_FAILED',
                'message' => 'Failed to wait for Route53 change: ' . $e->getMessage(),
                'status' => 500
            ];
        } catch (\Exception $e) {
            Log::error('Route53 change wait failed (non-AWS error)', [
                'change_id' => $changeId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'ROUTE53_WAIT_FAILED',
                'message' => 'Failed to wait for Route53 change: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Build change batch for Route53 operations
     *
     * @param string $fqdn
     * @param string $target
     * @param string $action
     * @return array
     */
    protected function buildChangeBatch(string $fqdn, string $target, string $action): array
    {
        $recordType = $this->isIpAddress($target) ? 'A' : 'CNAME';

        $change = [
            'Action' => $action,
            'ResourceRecordSet' => [
                'Name' => $fqdn . '.',
                'Type' => $recordType,
                'TTL' => config('exment.tenant.route53.ttl', 300)
            ]
        ];

        if ($recordType === 'A') {
            $change['ResourceRecordSet']['ResourceRecords'] = [
                ['Value' => $target]
            ];
        } else {
            $change['ResourceRecordSet']['ResourceRecords'] = [
                ['Value' => $target . '.']
            ];
        }

        return [
            'Changes' => [$change],
            'Comment' => 'Tenant subdomain ' . $action . ' via Exment'
        ];
    }

    /**
     * Check if string is IP address
     *
     * @param string $string
     * @return bool
     */
    protected function isIpAddress(string $string): bool
    {
        return filter_var($string, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Get AWS credentials
     *
     * @return array
     */
    protected function getCredentials(): array
    {
        // Check for IAM role first (EC2 instance profile)
        if (config('exment.tenant.aws.use_iam_role', false)) {
            return [];
        }

        // Use access key and secret
        $accessKey = config('exment.tenant.aws.access_key_id');
        $secretKey = config('exment.tenant.aws.secret_access_key');

        if (empty($accessKey) || empty($secretKey)) {
            throw new \InvalidArgumentException('AWS credentials not configured');
        }

        return [
            'key' => $accessKey,
            'secret' => $secretKey
        ];
    }
}
