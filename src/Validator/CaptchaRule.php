<?php

namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Services\ReCaptchaService;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Arr;

/**
 * Class     CaptchaRule. Originally copied from \Arcanedev\NoCaptcha\Rules\CaptchaRule
 * so that exment could translate the message. That package cannot be installed
 * alongside Laravel 12, so the verification now goes through ReCaptchaService,
 * which calls google/recaptcha directly.
 */
class CaptchaRule implements Rule
{
    /* -----------------------------------------------------------------
     |  Properties
     | -----------------------------------------------------------------
     */

    /** @var  string|null */
    protected $version;

    /** @var  array */
    // @phpstan-ignore-next-line
    protected $skipIps = [];

    /* -----------------------------------------------------------------
     |  Constructor
     | -----------------------------------------------------------------
     */

    /**
     * CaptchaRule constructor.
     *
     * @param  string|null  $version
     */
    public function __construct($version = null)
    {
        $this->version($version);
        // The second lookup only matters on a site upgraded from an older
        // Laravel that still has a published config/no-captcha.php lying around.
        $this->skipIps(
            config('exment.recaptcha_skip_ips') ?? config('no-captcha.skip-ips', [])
        );
    }

    /* -----------------------------------------------------------------
     |  Setters
     | -----------------------------------------------------------------
     */

    /**
     * Set the ReCaptcha version. Leave it null to follow the version chosen on
     * the system settings screen, which is what the public form does.
     *
     * @param  string|null  $version
     *
     * @return $this
     */
    public function version($version)
    {
        $this->version = $version;

        return $this;
    }

    /**
     * Set the ips to skip.
     *
     * @param  string|array  $ip
     *
     * @return $this
     */
    // @phpstan-ignore-next-line
    public function skipIps($ip)
    {
        $this->skipIps = Arr::wrap($ip);

        return $this;
    }

    /* -----------------------------------------------------------------
     |  Main methods
     | -----------------------------------------------------------------
     */

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed   $value
     *
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $ip = request()->ip();

        if (in_array($ip, $this->skipIps)) {
            return true;
        }

        return ReCaptchaService::verify(
            is_string($value) ? $value : null,
            $ip,
            $this->version
        );
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return (string) exmtrans('error.captcha');
    }
}
