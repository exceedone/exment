<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Services\SystemRequire\SystemRequireBase;

/**
 * A tuning advice item (ISS00414): a setting that makes Exment faster when it is set.
 *
 * Unlike a system requirement, a tuning item never blocks the install: its result is OK
 * (already set) or WARNING (recommended). The tuning page shows what to write (getFixText)
 * and, for the few settings that can be changed safely from the screen, a button (getAction).
 * Only whether a setting is set is shown, never a secret value (password, key, host name).
 */
abstract class TuningBase extends SystemRequireBase
{
    /**
     * The translation key under "tuning.item".
     *
     * @return string
     */
    abstract protected function key(): string;

    public function getKey(): string
    {
        return $this->key();
    }

    public function getLabel(): string
    {
        return exmtrans('tuning.item.' . $this->key() . '.label');
    }

    public function getExplain(): string
    {
        return exmtrans('tuning.item.' . $this->key() . '.explain');
    }

    protected function getMessageWarning(): ?string
    {
        return exmtrans('tuning.item.' . $this->key() . '.warning');
    }

    public function getResultText(): ?string
    {
        return $this->checkResult() == SystemRequireResult::OK
            ? exmtrans('tuning.result_ok')
            : exmtrans('tuning.result_recommended');
    }

    public function getSettingUrl(): ?string
    {
        return \Exceedone\Exment\Facades\ExmentFacade::getManualUrl('tuning');
    }

    /**
     * What to write to fix it (php.ini, .env, a command), shown so that it can be copied.
     *
     * @return string|null
     */
    public function getFixText(): ?string
    {
        return null;
    }

    /**
     * The action the tuning page can run for this item (TuningController::action), or null.
     *
     * @return string|null
     */
    public function getAction(): ?string
    {
        return null;
    }

    /**
     * Whether the setting belongs to the whole server (php.ini, .env, caches of the code).
     * A server-wide setting can be changed from the screen only where config
     * "exment.tuning_server_actions" allows it (turn it off on a multi-tenant server).
     *
     * @return bool
     */
    public function isServerWide(): bool
    {
        return true;
    }
}
