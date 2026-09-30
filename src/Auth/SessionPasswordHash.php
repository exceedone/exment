<?php

namespace Exceedone\Exment\Auth;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Bind a signed-in web session to the password the user had when the session signed in.
 *
 * SessionGuard keeps only the id of the user in the session, so a browser that signed in before
 * the password was changed stays signed in. Here the hash of the password is kept in the session,
 * and a session that holds the hash of a previous password is signed out on its next request.
 */
class SessionPasswordHash
{
    /**
     * Ids of the login users whose password was saved during this request.
     *
     * @var array<int|string, bool>
     */
    protected static $changedLoginUserIds = [];

    /**
     * Whether the browser sent the "remember me" cookie that is valid for the signed-in user.
     *
     * @var bool
     */
    protected static $remembered = false;

    /**
     * Session key. The value is ['id' => id of the login user, 'hash' => hash of the password].
     *
     * @return string
     */
    public static function sessionKey(): string
    {
        return Define::SYSTEM_KEY_SESSION_PASSWORD_HASH;
    }

    /**
     * Forget what was kept for the previous request.
     *
     * @return void
     */
    public static function begin(): void
    {
        static::$changedLoginUserIds = [];
        static::$remembered = false;
    }

    /**
     * Sign the session out if the password was changed after the session signed in.
     *
     * @param Request $request
     * @return bool false if the session was signed out
     */
    public static function verify(Request $request): bool
    {
        $guard = static::guard();
        if (is_null($guard) || !$request->hasSession()) {
            return true;
        }

        $user = $guard->user();
        if (is_null($user)) {
            return true;
        }

        $password = $user->getAuthPassword();
        if (is_nullorempty($password)) {
            return true;
        }

        // The "remember me" cookie holds the hash of the password it was issued with
        static::$remembered = static::isRemembered($request, $guard, $user, $password);
        if ($guard->viaRemember() && !static::$remembered) {
            static::signOut($request, $guard);
            return false;
        }

        $stored = $request->session()->get(static::sessionKey());
        if (!is_array($stored) || (string)array_get($stored, 'id') !== (string)$user->getAuthIdentifier()) {
            // signed in before this check existed, signed in by the "remember me" cookie just now,
            // or the hash is of the user who signed in to this session before
            static::put($request, $guard, $user, $password);
            return true;
        }

        if (!static::matches($guard, $password, array_get($stored, 'hash'))) {
            // Not signed out if this session changed the password itself. A request that started before
            // the change saved the session after it, with the hash of the previous password
            if (!static::matches($guard, $password, Cache::get(static::changedSessionKey($request)))) {
                static::signOut($request, $guard);
                return false;
            }
            static::put($request, $guard, $user, $password);
        }

        return true;
    }

    /**
     * Keep the hash of the current password in the session. Call after the request was handled.
     *
     * @param Request $request
     * @return void
     */
    public static function store(Request $request): void
    {
        $guard = static::guard();
        if (is_null($guard) || !$request->hasSession() || !$guard->hasUser()) {
            return;
        }

        $user = $guard->user();
        if (!($user instanceof LoginUser)) {
            return;
        }

        // Not getAuthPassword(). If saving the user failed in this request, it returns the password that was not saved
        $password = $user->getRawOriginal('password');
        $remember_token = null;
        $changed = array_key_exists($user->getKey(), static::$changedLoginUserIds);
        // The user changed the password in this request. Read it again, as the request may have rolled the change back
        if ($changed) {
            $row = $user->getConnection()->table($user->getTable())->where($user->getKeyName(), $user->getKey())->first();
            $password = $row->password ?? null;
            $remember_token = $row->{$user->getRememberTokenName()} ?? null;
        }

        if (!is_string($password) || $password === '') {
            return;
        }

        static::put($request, $guard, $user, $password);
        if (!$changed) {
            return;
        }

        // The session is saved as a whole by every request. A request that started before the change saves
        // the hash of the previous password again when it ends, so the change is kept out of the session too
        Cache::put(static::changedSessionKey($request), static::hash($guard, $password), (int)config('session.lifetime') * 60);

        // The cookie of this browser is of the previous password. Without this, the browser is not remembered any more
        if (static::$remembered && is_string($remember_token) && $remember_token !== '') {
            static::queueRecaller($guard, $user, $remember_token, $password);
        }
    }

    /**
     * Notify that the password of the login user was saved.
     * The session that changed its own password stays signed in, the others are signed out.
     *
     * @param LoginUser $login_user
     * @return void
     */
    public static function passwordChanged(LoginUser $login_user): void
    {
        static::$changedLoginUserIds[$login_user->getKey()] = true;
    }

    /**
     * Get the value kept in the session for the hashed password.
     *
     * @param SessionGuard $guard
     * @param string $password hashed password of the user
     * @return string
     */
    public static function hash(SessionGuard $guard, string $password): string
    {
        // Exment runs on Laravel 10, where this method is not defined, so the hash below is used.
        // Kept for the upgrade: it is defined from Laravel 12.45, and returns the same kind of hash
        if (method_exists($guard, 'hashPasswordForCookie')) {
            return $guard->hashPasswordForCookie($password);
        }

        return hash_hmac('sha256', $password, (string)config('app.key'));
    }

    /**
     * Get hash of the password set to the "remember me" cookie. Same value as SessionGuard sets at login.
     *
     * @param SessionGuard $guard
     * @param string $password hashed password of the user
     * @return string
     */
    protected static function getRecallerHash(SessionGuard $guard, string $password): string
    {
        // Laravel older than 12.45 (Exment runs on Laravel 10) sets the hashed password itself.
        // From Laravel 12.45 the cookie holds the hash that hashPasswordForCookie() returns
        return method_exists($guard, 'hashPasswordForCookie') ? $guard->hashPasswordForCookie($password) : $password;
    }

    /**
     * @return SessionGuard|null
     */
    protected static function guard(): ?SessionGuard
    {
        $guard = Auth::guard(Define::AUTHENTICATE_KEY_WEB);

        return $guard instanceof SessionGuard ? $guard : null;
    }

    /**
     * Cache key of the hash of the password that the session of the request changed to.
     *
     * @param Request $request
     * @return string
     */
    protected static function changedSessionKey(Request $request): string
    {
        // Not the session id itself, as the cache may keep its keys readable
        return sprintf(Define::SYSTEM_KEY_SESSION_PASSWORD_CHANGED_SESSION, hash('sha256', $request->session()->getId()));
    }

    /**
     * @param Request $request
     * @param SessionGuard $guard
     * @param Authenticatable $user
     * @param string $password hashed password of the user
     * @return void
     */
    protected static function put(Request $request, SessionGuard $guard, Authenticatable $user, string $password): void
    {
        $request->session()->put(static::sessionKey(), [
            'id' => $user->getAuthIdentifier(),
            'hash' => static::hash($guard, $password),
        ]);
    }

    /**
     * Whether the request has the "remember me" cookie of the user, issued with the current password.
     *
     * @param Request $request
     * @param SessionGuard $guard
     * @param Authenticatable $user
     * @param string $password hashed password of the user
     * @return bool
     */
    protected static function isRemembered(Request $request, SessionGuard $guard, Authenticatable $user, string $password): bool
    {
        $recaller = $request->cookies->get($guard->getRecallerName());
        if (!is_string($recaller)) {
            return false;
        }

        // id|remember token|hash of password
        $segments = explode('|', $recaller, 3);
        $remember_token = $user->getRememberToken();
        if (count($segments) !== 3 || is_nullorempty($remember_token)) {
            return false;
        }

        return $segments[0] === (string)$user->getAuthIdentifier()
            && hash_equals((string)$remember_token, $segments[1])
            && static::matches($guard, $password, $segments[2]);
    }

    /**
     * Issue the "remember me" cookie again. Same value as SessionGuard issues at login.
     *
     * @param SessionGuard $guard
     * @param Authenticatable $user
     * @param string $remember_token
     * @param string $password hashed password of the user
     * @return void
     */
    protected static function queueRecaller(SessionGuard $guard, Authenticatable $user, string $remember_token, string $password): void
    {
        $value = implode('|', [$user->getAuthIdentifier(), $remember_token, static::getRecallerHash($guard, $password)]);
        $minutes = config('auth.guards.' . Define::AUTHENTICATE_KEY_WEB . '.remember');

        $cookieJar = $guard->getCookieJar();
        $cookieJar->queue(is_null($minutes)
            ? $cookieJar->forever($guard->getRecallerName(), $value)
            : $cookieJar->make($guard->getRecallerName(), $value, $minutes));
    }

    /**
     * @param SessionGuard $guard
     * @param string $password hashed password of the user
     * @param mixed $stored value kept in the session or in the cookie
     * @return bool
     */
    protected static function matches(SessionGuard $guard, string $password, $stored): bool
    {
        if (!is_string($stored) || $stored === '') {
            return false;
        }

        // A cookie issued by Laravel older than 12.45 holds the hashed password itself
        return hash_equals(static::hash($guard, $password), $stored) || hash_equals($password, $stored);
    }

    /**
     * @param Request $request
     * @param SessionGuard $guard
     * @return void
     */
    protected static function signOut(Request $request, SessionGuard $guard): void
    {
        // Not logout(). It renews the remember token, and that signs out the browsers the user signed in after the change
        $guard->logoutCurrentDevice();

        // Same as Laravel's AuthenticateSession: logoutCurrentDevice() and flush(), not invalidate().
        // The session id is kept, only the data and the sign in are thrown away.
        $request->session()->flush();
        $request->session()->regenerateToken();
    }
}
