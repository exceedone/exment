<?php

namespace Exceedone\Exment\Auth;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Validation\ValidationException;

/**
 * Copied by Illuminate\Foundation\Auth\ThrottlesLogins
 *
 * @method string username()
 */
trait ThrottlesLogins
{
    /**
     * Determine if the user has too many failed login attempts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function hasTooManyLoginAttempts(Request $request)
    {
        return $this->limiter()->tooManyAttempts(
            $this->throttleKey($request),
            $this->maxAttempts()
        );
    }

    /**
     * Increment the login attempts for the user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    protected function incrementLoginAttempts(Request $request)
    {
        // RateLimiter::hit() takes seconds
        $this->limiter()->hit(
            $this->throttleKey($request),
            $this->decayMinutes() * 60
        );
    }

    /**
     * Redirect the user after determining they are locked out.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     * @throws \Illuminate\Validation\ValidationException
     */
    // @phpstan-ignore-next-line
    protected function sendLockoutResponse(Request $request, $errorKey = null)
    {
        if (!isset($errorKey)) {
            $errorKey = $this->username();
        }

        throw ValidationException::withMessages([
            $errorKey => $this->getLockoutMessage($request),
        ])->status(429);
    }

    /**
     * Redirect back to the form with the lockout message.
     * For a page of a signed-in user (e.g. 2 factor), where Exment::error() shows a thrown exception as the error page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $errorKey
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirectLockoutResponse(Request $request, $errorKey)
    {
        return back()->withInput()->withErrors([
            $errorKey => $this->getLockoutMessage($request),
        ]);
    }

    /**
     * Get the lockout message, with the minutes until the user can try again.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function getLockoutMessage(Request $request)
    {
        $seconds = $this->limiter()->availableIn(
            $this->throttleKey($request)
        );

        return exmtrans('login.throttle', ceil($seconds / 60));
    }

    /**
     * Clear the login locks for the given user credentials.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    protected function clearLoginAttempts(Request $request)
    {
        $this->limiter()->clear($this->throttleKey($request));
    }

    /**
     * Fire an event when a lockout occurs.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    protected function fireLockoutEvent(Request $request)
    {
        event(new Lockout($request));
    }

    /**
     * Get the throttle key for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function throttleKey(Request $request)
    {
        return Str::lower($request->input($this->username())).'|'.$request->ip();
    }

    /**
     * Get the rate limiter instance.
     *
     * @return \Illuminate\Cache\RateLimiter
     */
    protected function limiter()
    {
        return app(RateLimiter::class);
    }

    /**
     * Get the maximum number of attempts to allow.
     *
     * Cast: the value comes from config, which reads .env as a string.
     *
     * @return int
     */
    public function maxAttempts()
    {
        return (int) (property_exists($this, 'maxAttempts') ? $this->maxAttempts : 5);
    }

    /**
     * Get the number of minutes to throttle for.
     *
     * Cast: the value comes from config, which reads .env as a string, and
     * Carbon 3 (Laravel 12) refuses a numeric string in RateLimiter::hit().
     *
     * @return int
     */
    public function decayMinutes()
    {
        return (int) (property_exists($this, 'decayMinutes') ? $this->decayMinutes : 1);
    }
}
