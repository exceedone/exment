<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

use Exceedone\Exment\Services\Encryption\DataKeys;

/**
 * Envelope encryption with AWS KMS (ISS02929): .env keeps only the data keys wrapped by a KMS key
 * (EXMENT_DATA_KEY_WRAPPED="k2:<base64 CiphertextBlob>,k1:..."), useless without KMS; they are
 * opened with kms:Decrypt when needed, and kept in memory only.
 *
 * The encryption context names the app, the id of the key and the site (EXMENT_DATA_KEY_CONTEXT;
 * on SaaS the tenant): a wrapped key copied to another site does not open, the IAM policy can ask
 * for it (kms:EncryptionContext:site), and CloudTrail shows it with each call.
 */
class AwsKmsKeyProvider implements KeyProvider
{
    public const NAME = 'aws_kms';

    /** @var KmsClient */
    protected $client;

    /** @var string the KMS key (ARN, id or alias) */
    protected $keyId;

    /** @var string */
    protected $wrapped;

    /** @var string */
    protected $site;

    public function __construct(KmsClient $client, string $keyId, string $wrapped, string $site)
    {
        $this->client = $client;
        $this->keyId = $keyId;
        $this->wrapped = $wrapped;
        $this->site = $site;
    }

    public function name(): string
    {
        return static::NAME;
    }

    /**
     * @return array<string, string>
     */
    public function context(string $id): array
    {
        $context = ['app' => 'exment', 'key_id' => $id];
        if ($this->site !== '') {
            $context['site'] = $this->site;
        }
        return $context;
    }

    public function keys(): array
    {
        $keys = [];
        foreach (DataKeys::pairs($this->wrapped) as $id => $value) {
            $blob = base64_decode($value, true);
            if ($blob === false || $blob === '') {
                continue;
            }
            $raw = $this->client->decrypt($blob, $this->context($id), $this->keyId);
            if (strlen($raw) !== 32) {
                throw new KeyProviderException("KMS gave a key of another length for \"{$id}\".");
            }
            $keys[$id] = $raw;
        }
        return $keys;
    }

    public function generate(string $id): array
    {
        if ($this->keyId === '') {
            throw new KeyProviderException('Set EXMENT_DATA_KEY_KMS_KEY_ID (the KMS key) first.');
        }
        $made = $this->client->generateDataKey($this->keyId, $this->context($id));
        if (strlen($made['plain']) !== 32 || $made['wrapped'] === '') {
            throw new KeyProviderException('KMS did not give a data key of 32 bytes.');
        }
        $pair = $id . ':' . base64_encode($made['wrapped']);
        return [
            'id' => $id,
            'pair' => $pair,
            'setting' => 'EXMENT_DATA_KEY_WRAPPED="' . $pair . ($this->wrapped === '' ? '' : ',' . $this->wrapped) . '"',
        ];
    }
}
