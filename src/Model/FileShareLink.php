<?php

namespace Exceedone\Exment\Model;

/**
 * File server: a link that gives a folder or file to people outside, or that
 * takes files from them into a folder (ISS00377, phase 3).
 *
 * @property int $id
 * @property string $token the sha256 of the token of the url (the token itself is not kept)
 * @property string|null $token_encrypted the token, encrypted with the app key, to show the url again
 * @property int $workspace_id
 * @property int $node_id
 * @property string $kind share, request
 * @property string|null $password_hash
 * @property \Carbon\Carbon|null $expires_at
 * @property bool $allow_download
 * @property int|null $max_access
 * @property int $access_count
 * @property string|null $message
 * @property \Carbon\Carbon|null $revoked_at
 * @property int|null $revoked_user_id
 * @property \Carbon\Carbon|null $last_accessed_at
 * @property int $received_count
 * @property int $received_bytes
 * @property int|null $created_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property FileNode|null $node
 * @property FileWorkspace|null $workspace
 */
class FileShareLink extends ModelBase
{
    public const KIND_SHARE = 'share';
    public const KIND_REQUEST = 'request';

    protected $hidden = ['password_hash'];

    protected $casts = [
        'id' => 'integer',
        'workspace_id' => 'integer',
        'node_id' => 'integer',
        'allow_download' => 'boolean',
        'max_access' => 'integer',
        'access_count' => 'integer',
        'received_count' => 'integer',
        'received_bytes' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'revoked_user_id' => 'integer',
        'created_user_id' => 'integer',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<FileNode, $this>
     */
    public function node()
    {
        return $this->belongsTo(FileNode::class, 'node_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<FileWorkspace, $this>
     */
    public function workspace()
    {
        return $this->belongsTo(FileWorkspace::class, 'workspace_id');
    }

    public function hasPassword(): bool
    {
        return isset($this->password_hash) && $this->password_hash !== '';
    }

    public function isExpired(): bool
    {
        return isset($this->expires_at) && $this->expires_at->isPast();
    }

    public function isUsedUp(): bool
    {
        return isset($this->max_access) && $this->access_count >= $this->max_access;
    }

    /**
     * Whether it can be opened now: not taken back, not past its end, not used up.
     */
    public function isActive(): bool
    {
        return !isset($this->revoked_at) && !$this->isExpired() && !$this->isUsedUp();
    }

    /** @var string|null the token of the url, when known (made now, or opened with it) */
    protected $plainToken;

    /**
     * What is kept of a token: its sha256. The url has the token itself.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function setPlainToken(string $token): self
    {
        $this->plainToken = $token;
        return $this;
    }

    /**
     * The token of the url: the one this link was made or opened with, else the
     * encrypted copy (null when the app key changed since: the link still opens,
     * its url is not shown again).
     */
    public function plainToken(): ?string
    {
        if (isset($this->plainToken)) {
            return $this->plainToken;
        }
        if (!isset($this->token_encrypted)) {
            return null;
        }
        try {
            return $this->plainToken = \Illuminate\Support\Facades\Crypt::decryptString($this->token_encrypted);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function url(): ?string
    {
        $token = $this->plainToken();
        return isset($token) ? url()->to(url_join(\Exceedone\Exment\Services\FileServer\ShareLinks::basePath(), $token)) : null;
    }
}
