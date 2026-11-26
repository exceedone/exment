<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

use Exceedone\Exment\Services\Encryption\DataKeys;
use GuzzleHttp\ClientInterface;
use Illuminate\Encryption\Encrypter;

/**
 * The data keys wrapped by HashiCorp Vault's Transit engine (ISS02929), for sites on premises:
 * EXMENT_DATA_KEY_WRAPPED="k2:vault:v1:...,k1:vault:v1:..." opened with
 * POST /v1/<mount>/decrypt/<key>, with a token (DATA_KEY_VAULT_TOKEN) or AppRole
 * (EXMENT_DATA_KEY_VAULT_ROLE_ID and DATA_KEY_VAULT_SECRET_ID). The token and the secret id are
 * not named EXMENT_*, so a backup does not keep them.
 */
class VaultTransitKeyProvider implements KeyProvider
{
    public const NAME = 'vault';

    /** @var ClientInterface */
    protected $http;

    /** @var array{addr: string, mount: string, key: string, token: string, role_id: string, secret_id: string, namespace: string, wrapped: string} */
    protected $options;

    /** @var string|null the token of an AppRole login, for this provider's life */
    protected $loginToken;

    /**
     * @param array{addr: string, mount: string, key: string, token: string, role_id: string, secret_id: string, namespace: string, wrapped: string} $options
     */
    public function __construct(ClientInterface $http, array $options)
    {
        $this->http = $http;
        $this->options = $options;
    }

    public function name(): string
    {
        return static::NAME;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    protected function post(string $path, array $body, bool $withToken = true): array
    {
        $addr = rtrim($this->options['addr'], '/');
        if ($addr === '' || $this->options['key'] === '') {
            throw new KeyProviderException('Set EXMENT_DATA_KEY_VAULT_ADDR and EXMENT_DATA_KEY_VAULT_KEY first.');
        }
        $headers = ['Accept' => 'application/json'];
        if ($withToken) {
            $headers['X-Vault-Token'] = $this->token();
        }
        if ($this->options['namespace'] !== '') {
            $headers['X-Vault-Namespace'] = $this->options['namespace'];
        }
        try {
            $response = $this->http->request('POST', $addr . '/v1/' . ltrim($path, '/'), [
                'json' => $body,
                'headers' => $headers,
                'connect_timeout' => 3,
                'timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new KeyProviderException('Vault could not be reached: ' . class_basename($e));
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new KeyProviderException("Vault answered {$status} to {$path}.");
        }
        $json = json_decode((string) $response->getBody(), true);
        return is_array($json) ? $json : [];
    }

    protected function token(): string
    {
        if ($this->options['token'] !== '') {
            return $this->options['token'];
        }
        if (isset($this->loginToken)) {
            return $this->loginToken;
        }
        if ($this->options['role_id'] === '' || $this->options['secret_id'] === '') {
            throw new KeyProviderException('Set DATA_KEY_VAULT_TOKEN, or EXMENT_DATA_KEY_VAULT_ROLE_ID and DATA_KEY_VAULT_SECRET_ID.');
        }
        $json = $this->post('auth/approle/login', ['role_id' => $this->options['role_id'], 'secret_id' => $this->options['secret_id']], false);
        $token = strval($json['auth']['client_token'] ?? '');
        if ($token === '') {
            throw new KeyProviderException('The AppRole login of Vault gave no token.');
        }
        return $this->loginToken = $token;
    }

    protected function path(string $action): string
    {
        return trim($this->options['mount'] ?: 'transit', '/') . '/' . $action . '/' . rawurlencode($this->options['key']);
    }

    public function keys(): array
    {
        $keys = [];
        foreach (DataKeys::pairs($this->options['wrapped']) as $id => $ciphertext) {
            $json = $this->post($this->path('decrypt'), ['ciphertext' => $ciphertext]);
            $raw = base64_decode(strval($json['data']['plaintext'] ?? ''), true);
            if ($raw === false || strlen($raw) !== 32) {
                throw new KeyProviderException("Vault gave no key of 32 bytes for \"{$id}\".");
            }
            $keys[$id] = $raw;
        }
        return $keys;
    }

    public function generate(string $id): array
    {
        $raw = Encrypter::generateKey(DataKeys::CIPHER);
        $json = $this->post($this->path('encrypt'), ['plaintext' => base64_encode($raw)]);
        $ciphertext = strval($json['data']['ciphertext'] ?? '');
        if (!str_starts_with($ciphertext, 'vault:')) {
            throw new KeyProviderException('Vault gave no ciphertext.');
        }
        $pair = $id . ':' . $ciphertext;
        $current = $this->options['wrapped'];
        return [
            'id' => $id,
            'pair' => $pair,
            'setting' => 'EXMENT_DATA_KEY_WRAPPED="' . $pair . ($current === '' ? '' : ',' . $current) . '"',
        ];
    }
}
