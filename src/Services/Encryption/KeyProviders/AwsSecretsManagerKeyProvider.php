<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

use Exceedone\Exment\Services\Encryption\DataKeys;
use Illuminate\Encryption\Encrypter;

/**
 * The keys in a secret of AWS Secrets Manager (ISS02929, EXMENT_DATA_KEY_SECRET_ID): its
 * SecretString is "k2:base64...,k1:base64...", the form of DATA_ENCRYPTION_KEYS, or the JSON
 * {"keys": "k2:...,k1:..."}. Secrets Manager keeps it under its own KMS key; Exment only reads it.
 */
class AwsSecretsManagerKeyProvider implements KeyProvider
{
    public const NAME = 'aws_secrets_manager';

    /** @var SecretsClient */
    protected $client;

    /** @var string */
    protected $secretId;

    public function __construct(SecretsClient $client, string $secretId)
    {
        $this->client = $client;
        $this->secretId = $secretId;
    }

    public function name(): string
    {
        return static::NAME;
    }

    protected function pairsText(): string
    {
        if ($this->secretId === '') {
            throw new KeyProviderException('Set EXMENT_DATA_KEY_SECRET_ID (the secret of the keys) first.');
        }
        $text = trim($this->client->secretString($this->secretId));
        if (str_starts_with($text, '{')) {
            $json = json_decode($text, true);
            $text = is_array($json) ? strval($json['keys'] ?? '') : '';
        }
        return $text;
    }

    public function keys(): array
    {
        $keys = [];
        foreach (DataKeys::pairs($this->pairsText()) as $id => $value) {
            $raw = base64_decode($value, true);
            if ($raw !== false && strlen($raw) === 32) {
                $keys[$id] = $raw;
            }
        }
        return $keys;
    }

    public function generate(string $id): array
    {
        // made here; the secret is changed by its owner (Exment is not given the right to write it)
        $pair = $id . ':' . base64_encode(Encrypter::generateKey(DataKeys::CIPHER));
        $current = $this->pairsText();
        return [
            'id' => $id,
            'pair' => $pair,
            'setting' => 'the SecretString of ' . $this->secretId . ': ' . $pair . ($current === '' ? '' : ',' . $current),
        ];
    }
}
