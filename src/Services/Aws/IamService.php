<?php

namespace Exceedone\Exment\Services\Aws;

use Aws\Iam\IamClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IamService
{
    protected IamClient $client;
    protected string $accountId;
    protected string $region;

    public function __construct()
    {
        $hostedZoneId = config('exment.tenant.route53.hosted_zone_id');

        if ($hostedZoneId) {
            $this->region = config('exment.tenant.aws.region', 'ap-northeast-1');
        
            $this->client = new IamClient([
                'version' => 'latest',
                'region' => $this->region,
                'credentials' => $this->getCredentials()
            ]);

            // Get account ID for ARN construction
            $this->accountId = $this->getAccountId();

        }
    }

    /**
     * Create IAM resources for tenant
     *
     * @param mixed $tenant
     * @param string $subdomain
     * @return array
     */
    public function createTenantIamResources($tenant, string $subdomain): array
    {
        try {
            $tenantId = $tenant->id ?? uniqid('tenant_');
            $userName = "tenant-{$subdomain}-{$tenantId}";
            $roleName = "tenant-{$subdomain}-{$tenantId}-role";
            $policyName = "tenant-{$subdomain}-{$tenantId}-policy";

            // Create IAM user
            $userResult = $this->createIamUser($userName);
            if (!$userResult['success']) {
                return $userResult;
            }

            // Create IAM role
            $roleResult = $this->createIamRole($roleName, $subdomain);
            if (!$roleResult['success']) {
                // Cleanup user if role creation fails
                $this->deleteIamUser($userName);
                return $roleResult;
            }

            // Create IAM policy
            $policyResult = $this->createIamPolicy($policyName, $subdomain);
            if (!$policyResult['success']) {
                // Cleanup user and role if policy creation fails
                $this->deleteIamUser($userName);
                $this->deleteIamRole($roleName);
                return $policyResult;
            }

            // Attach policy to role
            $attachResult = $this->attachPolicyToRole($policyName, $roleName);
            if (!$attachResult['success']) {
                // Cleanup all resources if attachment fails
                $this->deleteIamUser($userName);
                $this->deleteIamRole($roleName);
                $this->deleteIamPolicy($policyName);
                return $attachResult;
            }

            // Create access key for user
            $accessKeyResult = $this->createAccessKey($userName);
            if (!$accessKeyResult['success']) {
                // Cleanup all resources if access key creation fails
                $this->deleteIamUser($userName);
                $this->deleteIamRole($roleName);
                $this->deleteIamPolicy($policyName);
                return $accessKeyResult;
            }

            return [
                'success' => true,
                'data' => [
                    'user_name' => $userName,
                    'role_name' => $roleName,
                    'policy_name' => $policyName,
                    'access_key_id' => $accessKeyResult['data']['access_key_id'],
                    'secret_access_key' => $accessKeyResult['data']['secret_access_key'],
                    'user_arn' => $userResult['data']['user_arn'],
                    'role_arn' => $roleResult['data']['role_arn'],
                    'policy_arn' => $policyResult['data']['policy_arn']
                ]
            ];

        } catch (\Exception $e) {
            Log::error('IAM resource creation failed', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_CREATION_FAILED',
                'message' => 'Failed to create IAM resources: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Create IAM user
     *
     * @param string $userName
     * @return array
     */
    protected function createIamUser(string $userName): array
    {
        try {
            $result = $this->client->createUser([
                'UserName' => $userName,
                'Tags' => [
                    [
                        'Key' => 'Purpose',
                        'Value' => 'Tenant Management'
                    ],
                    [
                        'Key' => 'CreatedBy',
                        'Value' => 'Exment'
                    ]
                ]
            ]);

            Log::info('IAM user created successfully', [
                'user_name' => $userName,
                'user_arn' => $result['User']['Arn'] ?? null
            ]);

            return [
                'success' => true,
                'data' => [
                    'user_arn' => $result['User']['Arn'] ?? null,
                    'user_id' => $result['User']['UserId'] ?? null
                ]
            ];

        } catch (AwsException $e) {
            Log::error('IAM user creation failed', [
                'user_name' => $userName,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_USER_CREATION_FAILED',
                'message' => 'Failed to create IAM user: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Create IAM role
     *
     * @param string $roleName
     * @param string $subdomain
     * @return array
     */
    protected function createIamRole(string $roleName, string $subdomain): array
    {
        try {
            $trustPolicy = $this->buildTrustPolicy($subdomain);
            
            $result = $this->client->createRole([
                'RoleName' => $roleName,
                'AssumeRolePolicyDocument' => json_encode($trustPolicy),
                'Description' => "Role for tenant {$subdomain}",
                'Tags' => [
                    [
                        'Key' => 'Purpose',
                        'Value' => 'Tenant Management'
                    ],
                    [
                        'Key' => 'Tenant',
                        'Value' => $subdomain
                    ]
                ]
            ]);

            Log::info('IAM role created successfully', [
                'role_name' => $roleName,
                'role_arn' => $result['Role']['Arn'] ?? null
            ]);

            return [
                'success' => true,
                'data' => [
                    'role_arn' => $result['Role']['Arn'] ?? null,
                    'role_id' => $result['Role']['RoleId'] ?? null
                ]
            ];

        } catch (AwsException $e) {
            Log::error('IAM role creation failed', [
                'role_name' => $roleName,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_ROLE_CREATION_FAILED',
                'message' => 'Failed to create IAM role: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Create IAM policy
     *
     * @param string $policyName
     * @param string $subdomain
     * @return array
     */
    protected function createIamPolicy(string $policyName, string $subdomain): array
    {
        try {
            $policyDocument = $this->buildPolicyDocument($subdomain);
            
            $result = $this->client->createPolicy([
                'PolicyName' => $policyName,
                'PolicyDocument' => json_encode($policyDocument),
                'Description' => "Policy for tenant {$subdomain}",
                'Tags' => [
                    [
                        'Key' => 'Purpose',
                        'Value' => 'Tenant Management'
                    ],
                    [
                        'Key' => 'Tenant',
                        'Value' => $subdomain
                    ]
                ]
            ]);

            Log::info('IAM policy created successfully', [
                'policy_name' => $policyName,
                'policy_arn' => $result['Policy']['Arn'] ?? null
            ]);

            return [
                'success' => true,
                'data' => [
                    'policy_arn' => $result['Policy']['Arn'] ?? null,
                    'policy_id' => $result['Policy']['PolicyId'] ?? null
                ]
            ];

        } catch (AwsException $e) {
            Log::error('IAM policy creation failed', [
                'policy_name' => $policyName,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_POLICY_CREATION_FAILED',
                'message' => 'Failed to create IAM policy: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Attach policy to role
     *
     * @param string $policyName
     * @param string $roleName
     * @return array
     */
    protected function attachPolicyToRole(string $policyName, string $roleName): array
    {
        try {
            $policyArn = "arn:aws:iam::{$this->accountId}:policy/{$policyName}";
            
            $this->client->attachRolePolicy([
                'RoleName' => $roleName,
                'PolicyArn' => $policyArn
            ]);

            Log::info('IAM policy attached to role successfully', [
                'policy_name' => $policyName,
                'role_name' => $roleName
            ]);

            return ['success' => true];

        } catch (AwsException $e) {
            Log::error('IAM policy attachment failed', [
                'policy_name' => $policyName,
                'role_name' => $roleName,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_POLICY_ATTACHMENT_FAILED',
                'message' => 'Failed to attach IAM policy: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Create access key for user
     *
     * @param string $userName
     * @return array
     */
    protected function createAccessKey(string $userName): array
    {
        try {
            $result = $this->client->createAccessKey([
                'UserName' => $userName
            ]);

            Log::info('IAM access key created successfully', [
                'user_name' => $userName,
                'access_key_id' => $result['AccessKey']['AccessKeyId'] ?? null
            ]);

            return [
                'success' => true,
                'data' => [
                    'access_key_id' => $result['AccessKey']['AccessKeyId'] ?? null,
                    'secret_access_key' => $result['AccessKey']['SecretAccessKey'] ?? null
                ]
            ];

        } catch (AwsException $e) {
            Log::error('IAM access key creation failed', [
                'user_name' => $userName,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_ACCESS_KEY_CREATION_FAILED',
                'message' => 'Failed to create IAM access key: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Delete IAM resources for tenant
     *
     * @param mixed $tenant
     * @param string $subdomain
     * @return array
     */
    public function deleteTenantIamResources($tenant, string $subdomain): array
    {
        try {
            $tenantId = $tenant->id ?? uniqid('tenant_');
            $userName = "tenant-{$subdomain}-{$tenantId}";
            $roleName = "tenant-{$subdomain}-{$tenantId}-role";
            $policyName = "tenant-{$subdomain}-{$tenantId}-policy";

            $results = [];

            // Delete access keys first
            $this->deleteUserAccessKeys($userName);

            // Detach policy from role
            $detachResult = $this->detachPolicyFromRole($policyName, $roleName);
            $results['policy_detached'] = $detachResult['success'];

            // Delete policy
            $policyResult = $this->deleteIamPolicy($policyName);
            $results['policy_deleted'] = $policyResult['success'];

            // Delete role
            $roleResult = $this->deleteIamRole($roleName);
            $results['role_deleted'] = $roleResult['success'];

            // Delete user
            $userResult = $this->deleteIamUser($userName);
            $results['user_deleted'] = $userResult['success'];

            return [
                'success' => true,
                'data' => $results
            ];

        } catch (\Exception $e) {
            Log::error('IAM resource deletion failed', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_DELETION_FAILED',
                'message' => 'Failed to delete IAM resources: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Delete IAM user
     *
     * @param string $userName
     * @return array
     */
    protected function deleteIamUser(string $userName): array
    {
        try {
            $this->client->deleteUser(['UserName' => $userName]);

            Log::info('IAM user deleted successfully', [
                'user_name' => $userName
            ]);

            return ['success' => true];

        } catch (AwsException $e) {
            Log::error('IAM user deletion failed', [
                'user_name' => $userName,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_USER_DELETION_FAILED',
                'message' => 'Failed to delete IAM user: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Delete IAM role
     *
     * @param string $roleName
     * @return array
     */
    protected function deleteIamRole(string $roleName): array
    {
        try {
            $this->client->deleteRole(['RoleName' => $roleName]);

            Log::info('IAM role deleted successfully', [
                'role_name' => $roleName
            ]);

            return ['success' => true];

        } catch (AwsException $e) {
            Log::error('IAM role deletion failed', [
                'role_name' => $roleName,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_ROLE_DELETION_FAILED',
                'message' => 'Failed to delete IAM role: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Delete IAM policy
     *
     * @param string $policyName
     * @return array
     */
    protected function deleteIamPolicy(string $policyName): array
    {
        try {
            $policyArn = "arn:aws:iam::{$this->accountId}:policy/{$policyName}";
            
            $this->client->deletePolicy([
                'PolicyArn' => $policyArn
            ]);

            Log::info('IAM policy deleted successfully', [
                'policy_name' => $policyName
            ]);

            return ['success' => true];

        } catch (AwsException $e) {
            Log::error('IAM policy deletion failed', [
                'policy_name' => $policyName,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_POLICY_DELETION_FAILED',
                'message' => 'Failed to delete IAM policy: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Detach policy from role
     *
     * @param string $policyName
     * @param string $roleName
     * @return array
     */
    protected function detachPolicyFromRole(string $policyName, string $roleName): array
    {
        try {
            $policyArn = "arn:aws:iam::{$this->accountId}:policy/{$policyName}";
            
            $this->client->detachRolePolicy([
                'RoleName' => $roleName,
                'PolicyArn' => $policyArn
            ]);

            Log::info('IAM policy detached from role successfully', [
                'policy_name' => $policyName,
                'role_name' => $roleName
            ]);

            return ['success' => true];

        } catch (AwsException $e) {
            Log::error('IAM policy detachment failed', [
                'policy_name' => $policyName,
                'role_name' => $roleName,
                'error_code' => $e->getAwsErrorCode(),
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_POLICY_DETACHMENT_FAILED',
                'message' => 'Failed to detach IAM policy: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Delete user access keys
     *
     * @param string $userName
     * @return void
     */
    protected function deleteUserAccessKeys(string $userName): void
    {
        try {
            $result = $this->client->listAccessKeys(['UserName' => $userName]);
            
            foreach ($result['AccessKeyMetadata'] as $accessKey) {
                $this->client->deleteAccessKey([
                    'UserName' => $userName,
                    'AccessKeyId' => $accessKey['AccessKeyId']
                ]);
            }

            Log::info('IAM access keys deleted successfully', [
                'user_name' => $userName
            ]);

        } catch (AwsException $e) {
            Log::warning('Failed to delete IAM access keys', [
                'user_name' => $userName,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check IAM resource status
     *
     * @param mixed $tenant
     * @param string $subdomain
     * @return array
     */
    public function checkIamResourceStatus($tenant, string $subdomain): array
    {
        try {
            $tenantId = $tenant->id ?? uniqid('tenant_');
            $userName = "tenant-{$subdomain}-{$tenantId}";
            $roleName = "tenant-{$subdomain}-{$tenantId}-role";
            $policyName = "tenant-{$subdomain}-{$tenantId}-policy";

            $status = [
                'user_exists' => $this->checkUserExists($userName),
                'role_exists' => $this->checkRoleExists($roleName),
                'policy_exists' => $this->checkPolicyExists($policyName)
            ];

            return [
                'success' => true,
                'data' => $status
            ];

        } catch (\Exception $e) {
            Log::error('IAM resource status check failed', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'IAM_STATUS_CHECK_FAILED',
                'message' => 'Failed to check IAM resource status: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Check if user exists
     *
     * @param string $userName
     * @return bool
     */
    protected function checkUserExists(string $userName): bool
    {
        try {
            $this->client->getUser(['UserName' => $userName]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Check if role exists
     *
     * @param string $roleName
     * @return bool
     */
    protected function checkRoleExists(string $roleName): bool
    {
        try {
            $this->client->getRole(['RoleName' => $roleName]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Check if policy exists
     *
     * @param string $policyName
     * @return bool
     */
    protected function checkPolicyExists(string $policyName): bool
    {
        try {
            $policyArn = "arn:aws:iam::{$this->accountId}:policy/{$policyName}";
            $this->client->getPolicy(['PolicyArn' => $policyArn]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Build trust policy for IAM role
     *
     * @param string $subdomain
     * @return array
     */
    protected function buildTrustPolicy(string $subdomain): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Principal' => [
                        'Service' => 'ec2.amazonaws.com'
                    ],
                    'Action' => 'sts:AssumeRole'
                ],
                [
                    'Effect' => 'Allow',
                    'Principal' => [
                        'AWS' => "arn:aws:iam::{$this->accountId}:root"
                    ],
                    'Action' => 'sts:AssumeRole',
                    'Condition' => [
                        'StringEquals' => [
                            'sts:ExternalId' => "tenant-{$subdomain}"
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Build policy document for tenant
     *
     * @param string $subdomain
     * @return array
     */
    protected function buildPolicyDocument(string $subdomain): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Action' => [
                        's3:GetObject',
                        's3:PutObject',
                        's3:DeleteObject',
                        's3:ListBucket'
                    ],
                    'Resource' => [
                        "arn:aws:s3:::tenant-{$subdomain}/*",
                        "arn:aws:s3:::tenant-{$subdomain}"
                    ]
                ],
                [
                    'Effect' => 'Allow',
                    'Action' => [
                        'route53:GetChange',
                        'route53:ListResourceRecordSets'
                    ],
                    'Resource' => '*'
                ]
            ]
        ];
    }

    /**
     * Get AWS account ID
     *
     * @return string
     */
    protected function getAccountId(): string
    {
        try {
            $result = $this->client->getUser();
            $arn = $result['User']['Arn'];
            return explode(':', $arn)[4];
        } catch (AwsException $e) {
            Log::error('Failed to get AWS account ID', [
                'error' => $e->getMessage()
            ]);
            return '000000000000'; // Fallback
        }
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


