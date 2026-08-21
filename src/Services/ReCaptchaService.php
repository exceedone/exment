<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\PublicForm;
use Illuminate\Support\Facades\Log;

/**
 * Google reCAPTCHA for public forms, both v2 (checkbox) and v3 (score).
 *
 * This used to come from arcanedev/no-captcha, which supplied the markup
 * helpers and the verification call. That package was last released in
 * February 2022 and its dependency chain stops at Laravel 9, so it cannot be
 * installed next to Laravel 12 at all. Everything it did lives here now, and
 * the only library left is google/recaptcha - Google's own client, which
 * declares no framework requirement and therefore cannot be the thing that
 * blocks the next Laravel upgrade.
 *
 * google/recaptcha stays optional, exactly as arcanedev/no-captcha was: when
 * it is absent Exment shows the "install the library" notice instead of
 * failing. See Exment::isAvailableGoogleRecaptcha() and
 * PublicForm::isEnableRecaptcha().
 */
class ReCaptchaService
{
    /**
     * Field the token is posted in. Not a free choice: the v2 widget injects a
     * textarea with exactly this name, and Form\Field\ReCaptcha binds the
     * validation rule to the same name.
     */
    public const RESPONSE_KEY = 'g-recaptcha-response';

    /**
     * Action label sent with a v3 token and demanded back from Google. Its only
     * job is to match between scriptV3() and verify(), which stops a token
     * minted on some other page from being replayed here.
     */
    public const ACTION = 'publicform';

    /**
     * Id of the hidden input a v3 token is written into. v3 renders its own
     * input in the form footer rather than reusing the one Form\Field\ReCaptcha
     * pushes, because a form built from tabs or rows may render that field
     * somewhere the script cannot rely on. The footer is always the last thing
     * inside the form tag, so this is also the last value posted under
     * RESPONSE_KEY and therefore the one PHP keeps.
     */
    public const TOKEN_INPUT_ID = 'exment-recaptcha-token';

    public const VERSION_V2 = 'v2';
    public const VERSION_V3 = 'v3';

    /**
     * v3 grades a visitor from 0.0 (bot) to 1.0 (human) instead of answering
     * pass/fail. 0.5 is Google's own recommended starting point.
     */
    public const DEFAULT_SCORE_THRESHOLD = 0.5;

    /**
     * Where the browser loads reCAPTCHA from. Override in a subclass to use
     * https://www.recaptcha.net/recaptcha/api.js, the alternative host Google
     * documents for networks that cannot reach google.com.
     */
    protected const API_URL = 'https://www.google.com/recaptcha/api.js';

    /**
     * Whether the google/recaptcha library is installed.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return class_exists(\ReCaptcha\ReCaptcha::class);
    }

    /**
     * Ask Google whether a posted token is genuine.
     *
     * @param string|null $token value posted under RESPONSE_KEY
     * @param string|null $ip visitor ip address, optional
     * @param string|null $version self::VERSION_V2 or self::VERSION_V3. Null
     *                             follows the system settings screen.
     * @return bool
     */
    public static function verify(?string $token, ?string $ip = null, ?string $version = null): bool
    {
        if (!static::isAvailable()) {
            return false;
        }

        $secret = PublicForm::recaptchaSecretKey();
        if (is_nullorempty($secret) || is_nullorempty($token)) {
            return false;
        }

        $recaptcha = new \ReCaptcha\ReCaptcha($secret);

        // v3 reports success for every human-looking request and puts the real
        // verdict in the score, so the threshold and the action are what
        // actually reject a bot. A v2 response carries neither, and demanding
        // them would fail every single v2 submission.
        if (($version ?? PublicForm::recaptchaVersion()) === static::VERSION_V3) {
            $recaptcha->setExpectedAction(static::ACTION)
                ->setScoreThreshold(static::scoreThreshold());
        }

        $response = $recaptcha->verify($token, $ip);

        if (!$response->isSuccess()) {
            // A rejected bot and a wrong secret key look identical to the
            // visitor, so keep the reason somewhere an admin can read it.
            Log::debug('Google reCAPTCHA verification failed: ' . implode(', ', $response->getErrorCodes()));
            return false;
        }

        return true;
    }

    /**
     * Script tag loading Google's api.js. v3 carries the site key in the url
     * because the page itself calls grecaptcha.execute().
     *
     * @param string $version self::VERSION_V2 or self::VERSION_V3
     * @return string
     */
    public static function apiScript(string $version): string
    {
        if ($version === static::VERSION_V3) {
            return '<script src="' . e(static::API_URL . '?render=' . static::siteKey()) . '"></script>';
        }

        return '<script src="' . static::API_URL . '" async defer></script>';
    }

    /**
     * The v2 checkbox. Google turns this div into the widget and appends a
     * textarea named RESPONSE_KEY holding the token. The form footer renders
     * after the form fields, so that textarea comes after the hidden field of
     * Form\Field\ReCaptcha and is the value PHP keeps for that name.
     *
     * @return string
     */
    public static function widgetV2(): string
    {
        return '<div class="g-recaptcha" data-sitekey="' . e(static::siteKey()) . '"></div>';
    }

    /**
     * Hidden input a v3 token is posted in. v3 asks the visitor nothing, so
     * this is all it contributes to the visible form.
     *
     * @return string
     */
    public static function inputV3(): string
    {
        return '<input type="hidden" name="' . static::RESPONSE_KEY . '" id="' . static::TOKEN_INPUT_ID . '" />';
    }

    /**
     * v3 fetches a token as soon as the api is ready and writes it into the
     * input above.
     *
     * @return string
     */
    public static function scriptV3(): string
    {
        $siteKey = static::jsString(static::siteKey());
        $action = static::jsString(static::ACTION);
        $inputId = static::jsString(static::TOKEN_INPUT_ID);

        return <<<HTML
<script>
(function () {
    var siteKey = $siteKey, action = $action, inputId = $inputId;

    function refreshToken() {
        grecaptcha.execute(siteKey, {action: action}).then(function (token) {
            var input = document.getElementById(inputId);
            if (input) {
                input.value = token;
            }
        });
    }

    grecaptcha.ready(function () {
        refreshToken();
        // A token dies after 120 seconds, which is easily shorter than the time
        // a visitor spends filling the form, so renew it while the page is open.
        setInterval(refreshToken, 100000);
    });
})();
</script>
HTML;
    }

    /**
     * @return string
     */
    protected static function siteKey(): string
    {
        return (string) PublicForm::recaptchaSiteKey();
    }

    /**
     * v3 threshold, kept inside the 0..1 range Google actually returns so a
     * mistyped setting cannot silently accept or reject everything.
     *
     * @return float
     */
    protected static function scoreThreshold(): float
    {
        $threshold = (float) config('exment.recaptcha_v3_score_threshold', static::DEFAULT_SCORE_THRESHOLD);

        if ($threshold < 0 || $threshold > 1) {
            return static::DEFAULT_SCORE_THRESHOLD;
        }

        return $threshold;
    }

    /**
     * Quote a value as a javascript string literal. The site key comes from the
     * system settings screen, so it is escaped rather than trusted to be the
     * plain token Google hands out.
     *
     * @param string $value
     * @return string
     */
    protected static function jsString(string $value): string
    {
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
