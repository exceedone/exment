<?php

namespace Exceedone\Exment\Model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores the link between an Exment account (user_id) and a LINE account (line_user_id).
 * Uses a dedicated line_account_links table (not a custom column on the user table).
 */
class LineAccountLink extends Model
{
    protected $table = 'line_account_links';

    protected $fillable = ['user_id', 'line_user_id', 'line_link_code'];

    protected $casts = ['linked_at' => 'datetime'];

    public static function forUser(int $userId): self
    {
        return static::firstOrNew(['user_id' => $userId]);
    }

    public function isLinked(): bool
    {
        return !empty($this->line_user_id);
    }

    public function generateCode(int $length = 6): string
    {
        $code = strtoupper(substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length));
        $this->line_link_code = $code;
        $this->line_user_id   = null;
        $this->linked_at      = null;
        $this->save();
        return $code;
    }

    public function markLinked(string $lineUserId): void
    {
        $this->line_user_id   = $lineUserId;
        $this->line_link_code = null;
        $this->linked_at      = Carbon::now();
        $this->save();
    }

    public function unlink(): void
    {
        $this->line_user_id   = null;
        $this->line_link_code = null;
        $this->linked_at      = null;
        $this->save();
    }
}
