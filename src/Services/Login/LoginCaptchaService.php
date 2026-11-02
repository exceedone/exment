<?php

namespace Exceedone\Exment\Services\Login;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginFailure;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;

/**
 * CAPTCHA of the login form, shown after failed logins, to stop automated attacks (bots).
 *
 * Required when one of these, in the minutes of "login_failure_minutes":
 * - the IP address failed "login_captcha_failures" times or more (wrong ID / password or wrong CAPTCHA)
 * - the entered ID got "login_captcha_failures" wrong passwords or more, from any IP address (a distributed attack)
 * - required once in this session (kept until the login succeeds)
 * Not required for "IP addresses never blocked" of the IP block.
 *
 * The image is drawn by GD on this server: no external service, no key, works without the Internet.
 * The code is kept in the session, valid for VALID_MINUTES, and usable only once.
 */
class LoginCaptchaService
{
    /**
     * Name of the input on the login form.
     */
    public const INPUT_NAME = 'captcha';

    public const CODE_LENGTH = 5;

    /**
     * Characters of a code. Easily confused characters (0, O, 1, I) are not used.
     */
    public const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const VALID_MINUTES = 10;

    public const WIDTH = 160;
    public const HEIGHT = 50;

    public const FAILURES_MAX = 1000;
    public const DEFAULT_FAILURES = 3;

    /**
     * Whether the image can be drawn on this server. (GD extension)
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        foreach (['imagecreatetruecolor', 'imagepng', 'imagestring', 'imagerotate', 'imagecopyresampled'] as $function) {
            if (!function_exists($function)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether CAPTCHA is on and can be used.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return boolval(System::login_captcha()) && static::isAvailable() && hasTable(SystemTableName::LOGIN_FAILURE);
    }

    /**
     * Number of failed logins to require CAPTCHA.
     *
     * @return int
     */
    public static function getFailureThreshold(): int
    {
        return LoginIpBlockService::toRange(System::login_captcha_failures(), 1, static::FAILURES_MAX, static::DEFAULT_FAILURES);
    }

    /**
     * Whether CAPTCHA is required for the request.
     *
     * @param Request $request
     * @param bool $withUsername also judged by the entered ID. (posting the login form)
     * @return bool
     */
    public function isRequired(Request $request, bool $withUsername = false): bool
    {
        if (!$this->isEnabled() || app(LoginIpBlockService::class)->isAllowedIp($request->ip())) {
            return false;
        }

        if ($request->hasSession() && boolval($request->session()->get(Define::SYSTEM_KEY_SESSION_LOGIN_CAPTCHA_REQUIRED))) {
            return true;
        }

        $since = Carbon::now()->subMinutes(LoginIpBlockService::getFailureMinutes());
        $threshold = static::getFailureThreshold();

        $ip_key = LoginIpBlockService::getIpKey($request->ip());
        if (!is_null($ip_key)) {
            $count = LoginFailure::query()
                ->where('ip_key', $ip_key)
                ->whereIn('reason', LoginFailure::IP_REASONS)
                ->where('created_at', '>=', $since)
                ->count();
            if ($count >= $threshold) {
                return true;
            }
        }

        if ($withUsername) {
            $username = $request->input('username');
            if (is_string($username) && app(LoginFailureService::class)->countUsernameFailures($username, $since) >= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * After a wrong password: require CAPTCHA from the next time in this session, if the IP address or the user failed enough.
     * (The user is judged here, because the ID entered next time may be another ID of the user. Ex. e-mail instead of user code)
     *
     * @param Request $request
     * @param LoginUser|null $login_user the user of the entered ID, if exists.
     * @return void
     */
    public function requireIfNeeded(Request $request, ?LoginUser $login_user): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $required = $this->isRequired($request, true);
        if (!$required && !is_null($login_user)) {
            $since = Carbon::now()->subMinutes(LoginIpBlockService::getFailureMinutes());
            $required = app(LoginFailureService::class)->countUserFailures($login_user->base_user_id, $since) >= static::getFailureThreshold();
        }

        if ($required) {
            $this->markRequired($request);
        }
    }

    /**
     * Require CAPTCHA in this session until the login succeeds.
     *
     * @param Request $request
     * @return void
     */
    public function markRequired(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(Define::SYSTEM_KEY_SESSION_LOGIN_CAPTCHA_REQUIRED, true);
        }
    }

    /**
     * Forget CAPTCHA of this session. (logged in)
     *
     * @param Request $request
     * @return void
     */
    public function clear(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget([Define::SYSTEM_KEY_SESSION_LOGIN_CAPTCHA_REQUIRED, Define::SYSTEM_KEY_SESSION_LOGIN_CAPTCHA]);
        }
    }

    /**
     * Verify the entered code. The code in the session is used only once, whether it matches or not.
     * Case and spaces are ignored.
     *
     * @param Request $request
     * @return bool
     */
    public function verify(Request $request): bool
    {
        if (!$request->hasSession()) {
            return false;
        }

        $stored = $request->session()->pull(Define::SYSTEM_KEY_SESSION_LOGIN_CAPTCHA);
        $input = $request->input(static::INPUT_NAME);
        if (!is_array($stored) || !is_string($input) || !is_string($stored['code'] ?? null) || !is_numeric($stored['expires_at'] ?? null)) {
            return false;
        }
        if (intval($stored['expires_at']) < Carbon::now()->getTimestamp()) {
            return false;
        }

        $input = strtoupper((string)preg_replace('/\s+/u', '', $input));
        return $input !== '' && hash_equals($stored['code'], $input);
    }

    /**
     * Create a new code, keep it in the session, and draw the image.
     *
     * @param Request $request
     * @return string PNG
     */
    public function createImage(Request $request): string
    {
        $code = static::createCode();
        if ($request->hasSession()) {
            $request->session()->put(Define::SYSTEM_KEY_SESSION_LOGIN_CAPTCHA, [
                'code' => $code,
                'expires_at' => Carbon::now()->addMinutes(static::VALID_MINUTES)->getTimestamp(),
            ]);
        }

        return static::draw($code);
    }

    /**
     * @return string
     */
    public static function createCode(): string
    {
        $code = '';
        $max = strlen(static::CODE_CHARS) - 1;
        for ($i = 0; $i < static::CODE_LENGTH; $i++) {
            $code .= static::CODE_CHARS[random_int(0, $max)];
        }
        return $code;
    }

    /**
     * Draw the code: each character is enlarged, rotated and shifted, over and under noise lines.
     * Uses the built-in font of GD only, so that no font file is needed.
     *
     * @param string $code
     * @return string PNG
     */
    public static function draw(string $code): string
    {
        $image = imagecreatetruecolor(static::WIDTH, static::HEIGHT);
        imagefilledrectangle($image, 0, 0, static::WIDTH - 1, static::HEIGHT - 1, static::color($image, 235, 250));

        // noise under the characters
        for ($i = 0; $i < 6; $i++) {
            imageline($image, random_int(0, static::WIDTH), random_int(0, static::HEIGHT), random_int(0, static::WIDTH), random_int(0, static::HEIGHT), static::color($image, 120, 200));
        }
        for ($i = 0; $i < 250; $i++) {
            imagesetpixel($image, random_int(0, static::WIDTH - 1), random_int(0, static::HEIGHT - 1), static::color($image, 100, 220));
        }

        // characters
        $step = (int)((static::WIDTH - 20) / max(1, strlen($code)));
        for ($i = 0; $i < strlen($code); $i++) {
            $char = imagecreatetruecolor(13, 18);
            imagealphablending($char, false);
            imagesavealpha($char, true);
            $transparent = (int)imagecolorallocatealpha($char, 255, 255, 255, 127);
            imagefilledrectangle($char, 0, 0, 12, 17, $transparent);
            // drawn twice, shifted by 1 pixel: bold
            $color = static::color($char, 0, 70);
            imagestring($char, 5, 1, 1, $code[$i], $color);
            imagestring($char, 5, 2, 1, $code[$i], $color);

            $rotated = imagerotate($char, random_int(-25, 25), $transparent);
            if ($rotated === false) {
                $rotated = $char;
            }
            imagealphablending($image, true);
            $w = random_int(22, 26);
            $h = random_int(32, 38);
            imagecopyresampled($image, $rotated, 10 + $i * $step + random_int(-2, 2), random_int(2, static::HEIGHT - $h - 2), 0, 0, $w, $h, imagesx($rotated), imagesy($rotated));
        }

        // noise over the characters
        for ($i = 0; $i < 2; $i++) {
            imageline($image, 0, random_int(5, static::HEIGHT - 5), static::WIDTH, random_int(5, static::HEIGHT - 5), static::color($image, 110, 170));
        }

        ob_start();
        imagepng($image);
        return (string)ob_get_clean();
    }

    /**
     * Random gray-ish color in the brightness range.
     *
     * @param \GdImage $image
     * @param int $min 0 to 255
     * @param int $max 0 to 255
     * @return int
     */
    protected static function color($image, int $min, int $max): int
    {
        $value = function () use ($min, $max): int {
            return max(0, min(255, random_int(min($min, $max), max($min, $max))));
        };
        return (int)imagecolorallocate($image, $value(), $value(), $value());
    }
}
