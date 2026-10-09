<?php

namespace Exceedone\Exment\Model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class LineAccountLink extends Model
{
    protected $table = 'line_account_links';

    protected $fillable = ['user_id', 'line_user_id', 'line_link_code'];

    protected $casts = ['linked_at' => 'datetime', 'line_link_code_expires_at' => 'datetime'];

    public static function forUser(int $userId): self
    {
        return static::firstOrNew(['user_id' => $userId]);
    }

    public function isLinked(): bool
    {
        return !empty($this->line_user_id);
    }

    public function hasActiveCode(): bool
    {
        return !empty($this->line_link_code)
            && $this->line_link_code_expires_at !== null
            && $this->line_link_code_expires_at->isFuture();
    }

    public function generateCode(int $length = 6): string
    {
        $code = strtoupper(substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length));
        $this->line_link_code            = $code;
        $this->line_link_code_expires_at = Carbon::now()->addMinutes((int) config('exment.line.link_code_ttl_minutes', 10));
        $this->line_user_id              = null;
        $this->linked_at                 = null;
        $this->save();
        return $code;
    }

    public function markLinked(string $lineUserId): void
    {
        $this->line_user_id              = $lineUserId;
        $this->line_link_code            = null;
        $this->line_link_code_expires_at = null;
        $this->linked_at                 = Carbon::now();
        $this->save();
    }

    public function unlink(): void
    {
        $this->line_user_id              = null;
        $this->line_link_code            = null;
        $this->line_link_code_expires_at = null;
        $this->linked_at                 = null;
        $this->save();
    }
}
