<?php

namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Services\EnvService;
use Illuminate\Encryption\Encrypter;

/**
 * A site not installed yet whose .env has no APP_KEY (an empty key in the zip): the first
 * request would end with a 500 (MissingAppKeyException, from the cookies) before the
 * install screen. The key is made here, before the cookies, and written to .env.
 */
class AppKey
{
    /** @var \Closure|null fn(array $values): void, writes .env (tests) */
    public static $envWriter;

    /**
     * Make the key when it is missing on a site not installed. True when it was made.
     */
    public static function ensure(): bool
    {
        if (strval(config('app.key')) !== '') {
            return false;
        }
        // an installed site without a key is a broken setting: not changed here
        if (boolval(env('EXMENT_INITIALIZE', false))) {
            return false;
        }
        $key = 'base64:' . base64_encode(Encrypter::generateKey(strval(config('app.cipher', 'AES-256-CBC'))));
        try {
            if (static::$envWriter) {
                call_user_func(static::$envWriter, ['APP_KEY' => $key]);
            } else {
                EnvService::setEnv(['APP_KEY' => $key]);
            }
        } catch (\Throwable $e) {
            // .env cannot be written: the install screen says so; this request still works
            \Log::warning('Exment install: cannot write APP_KEY to .env: ' . $e->getMessage());
        }
        // this request too
        config(['app.key' => $key]);
        return true;
    }
}
