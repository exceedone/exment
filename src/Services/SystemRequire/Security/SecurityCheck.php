<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Controllers\TuningController;
use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\Features;

/**
 * The security check (ISS02833): the items, who may see them, and the fixes.
 *
 * On a server shared by several sites (a SaaS: EXMENT_FEATURES_LOCKED=tuning), the
 * items of the server are the operator's: a site sees only what it can fix itself
 * (the passwords, two-step sign-in).
 */
class SecurityCheck
{
    /** @var array<class-string<SecurityBase>> */
    public const ITEMS = [
        FilePermissionSecurity::class,
        AppDebugSecurity::class,
        DisplayErrorsSecurity::class,
        AppKeySecurity::class,
        AppEnvSecurity::class,
        HttpsSecurity::class,
        HeadersSecurity::class,
        ExposedPathSecurity::class,
        AdminPasswordSecurity::class,
        TwoFactorSecurity::class,
        WebhookSecurity::class,
        VersionSecurity::class,
        // the logs and personal data (ISS02839)
        LogSecurity::class,
        SessionCookieSecurity::class,
    ];

    /** @var array<string, SecurityBase> */
    protected $items;

    /**
     * Whether the items of the server are shown here (not on a server that keeps the
     * tuning from its sites).
     */
    public static function serverItemsShown(): bool
    {
        try {
            // EXMENT_TUNING_SERVER_ACTIONS=false: the server is the operator's too
            if (!TuningController::serverActionsAllowed()) {
                return false;
            }
            return !in_array(Features::TUNING, Features::lockedKeys(), true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * @param array<SecurityBase>|null $items for tests
     */
    public function __construct(?array $items = null)
    {
        $this->items = [];
        $server = static::serverItemsShown();
        foreach ($items ?? array_map(function ($class) {
            return new $class();
        }, static::ITEMS) as $item) {
            if (!$server && $item->scope() !== SecurityBase::SCOPE_SITE) {
                continue;
            }
            $this->items[$item->getKey()] = $item;
        }
    }

    /**
     * @return array<string, SecurityBase>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * The items that are not as they should be.
     *
     * @return array<string, SecurityBase>
     */
    public function problems(): array
    {
        return array_filter($this->items, function (SecurityBase $item) {
            return $item->checkResult() != SystemRequireResult::OK;
        });
    }

    /**
     * The problems of a high risk (isHigh).
     *
     * @return array<string, SecurityBase>
     */
    public function highProblems(): array
    {
        return array_filter($this->problems(), function (SecurityBase $item) {
            return $item->isHigh();
        });
    }

    /**
     * Where the initialize ends: the page of the check when something is of a high
     * risk; else the dashboard, telling how many items are left to look at.
     *
     * @return array{page: bool, high: int, other: int}
     */
    public function afterInitialize(): array
    {
        $problems = $this->problems();
        $high = count(array_filter($problems, function (SecurityBase $item) {
            return $item->isHigh();
        }));
        return ['page' => $high > 0, 'high' => $high, 'other' => count($problems) - $high];
    }

    /** The items asked on the install screen: the server's, without the database. */
    public const INSTALL_ITEMS = ['file_permission', 'app_debug', 'display_errors', 'app_key', 'app_env', 'https', 'headers', 'exposed_path', 'session_cookie'];

    /**
     * The problems shown on the install screen.
     *
     * @return array<string, SecurityBase>
     */
    public function installProblems(): array
    {
        try {
            $items = array_intersect_key($this->items, array_flip(static::INSTALL_ITEMS));
            return array_filter($items, function (SecurityBase $item) {
                return $item->checkResult() != SystemRequireResult::OK;
            });
        } catch (\Throwable $e) {
            \Log::warning('security check at the install: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fix an item, when Exment can. Logged with who did it (the request itself is in
     * the operation log).
     */
    public function fix(string $key): bool
    {
        $item = $this->items[$key] ?? null;
        if (!isset($item) || !$item->canFix()) {
            return false;
        }
        if ($item->scope() !== SecurityBase::SCOPE_SITE && !static::serverItemsShown()) {
            return false;
        }
        $item->fix();
        \Log::info('Exment security check: fixed ' . $key, ['user' => \Exment::getUserId()]);
        return true;
    }
}
