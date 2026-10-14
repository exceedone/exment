<?php

namespace Exceedone\Exment\Services\Auth2factor;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Auth2factorRecoveryCode;
use Exceedone\Exment\Model\LoginUser;

/**
 * Recovery codes of 2factor authentication.
 * One-time codes the user can enter instead of the one-time password, when the user cannot receive or see it
 * (Ex. lost the smartphone). Issued when the user set up 2factor, shown only once, and only the hash is stored.
 */
class Auth2factorRecoveryCodeService
{
    /**
     * Number of codes issued at once.
     */
    public const CODE_COUNT = 10;

    /**
     * Length of a code. Shown as "XXXXX-XXXXX".
     */
    public const CODE_LENGTH = 10;

    /**
     * Characters of a code. Easily confused characters (0, O, 1, I) are not used.
     */
    protected const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Issue new recovery codes. The old codes of the user (used or not) are deleted.
     *
     * @param LoginUser $login_user
     * @return array<string> the codes to show the user. Never stored.
     */
    public static function generate(LoginUser $login_user): array
    {
        $codes = [];
        $rows = [];
        $now = Carbon::now();
        while (count($codes) < static::CODE_COUNT) {
            $code = static::createCode();
            if (in_array($code, $codes)) {
                continue;
            }
            $codes[] = $code;
            $rows[] = [
                'login_user_id' => $login_user->id,
                'code_hash' => static::hash($code),
                'used_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        \DB::transaction(function () use ($login_user, $rows) {
            Auth2factorRecoveryCode::query()->where('login_user_id', $login_user->id)->delete();
            Auth2factorRecoveryCode::query()->insert($rows);
        });

        return array_map([static::class, 'format'], $codes);
    }

    /**
     * Issue recovery codes if the user has never been issued. (= the user has just set up 2factor)
     *
     * @param LoginUser $login_user
     * @return array<string>|null the issued codes. null: already issued (even if all are used), or the table is not migrated yet.
     */
    public static function issueIfNone(LoginUser $login_user): ?array
    {
        if (!static::isAvailable()) {
            return null;
        }

        return \DB::transaction(function () use ($login_user) {
            // the same one-time password can be posted twice at once (Ex. double click): serialize by the login user row,
            // so that the second request sees the codes of the first one and does not replace them.
            LoginUser::query()->where('id', $login_user->id)->lockForUpdate()->get();

            if (static::hasCodes($login_user)) {
                return null;
            }
            return static::generate($login_user);
        });
    }

    /**
     * Whether recovery codes have been issued to the user. (used ones are counted)
     *
     * @param LoginUser $login_user
     * @return bool
     */
    public static function hasCodes(LoginUser $login_user): bool
    {
        if (!static::isAvailable()) {
            return false;
        }
        return Auth2factorRecoveryCode::query()->where('login_user_id', $login_user->id)->exists();
    }

    /**
     * Number of the codes the user can still use.
     *
     * @param LoginUser $login_user
     * @return int
     */
    public static function getRemainingCount(LoginUser $login_user): int
    {
        if (!static::isAvailable()) {
            return 0;
        }
        return Auth2factorRecoveryCode::query()->where('login_user_id', $login_user->id)->unused()->count();
    }

    /**
     * Verify the code the user entered, and consume it if it matches.
     *
     * @param LoginUser $login_user
     * @param string|null $code as entered. Case, spaces and hyphens are ignored.
     * @return bool true: matched an unused code of the user, and the code is marked as used.
     */
    public static function verify(LoginUser $login_user, ?string $code): bool
    {
        if (!static::isAvailable()) {
            return false;
        }

        $normalized = static::normalize($code);
        if (strlen($normalized) != static::CODE_LENGTH) {
            return false;
        }

        $updated = Auth2factorRecoveryCode::query()
            ->where('login_user_id', $login_user->id)
            ->where('code_hash', static::hash($normalized))
            ->unused()
            ->update(['used_at' => Carbon::now()]);

        return $updated > 0;
    }

    /**
     * Whether the table exists. (before executing "exment:update", the table is not created yet)
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return hasTable(SystemTableName::AUTH2FACTOR_RECOVERY_CODE);
    }

    /**
     * Remove case, spaces and hyphens: "abcde-fghjk" -> "ABCDEFGHJK".
     *
     * @param string|null $code
     * @return string
     */
    public static function normalize(?string $code): string
    {
        if (is_null($code)) {
            return '';
        }
        return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    /**
     * Format for display: "ABCDEFGHJK" -> "ABCDE-FGHJK".
     *
     * @param string $code
     * @return string
     */
    public static function format(string $code): string
    {
        $half = intdiv(static::CODE_LENGTH, 2);
        return substr($code, 0, $half) . '-' . substr($code, $half);
    }

    /**
     * @return string a random code (not formatted)
     */
    protected static function createCode(): string
    {
        $chars = static::CODE_CHARS;
        $max = strlen($chars) - 1;
        $code = '';
        for ($i = 0; $i < static::CODE_LENGTH; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        return $code;
    }

    /**
     * Hash of a code. Keyed by the application key, so that the codes cannot be brute-forced from a leaked database.
     *
     * @param string $normalized
     * @return string
     */
    protected static function hash(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, (string)config('app.key'));
    }
}
