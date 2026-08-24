<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;

/**
 * Push the Meilisearch configuration (stored in Exment's System settings screen, the `systems` table)
 * into config('meilisearch.*') at runtime.
 *
 * The keys are declared in Define::SYSTEM_SETTING_NAME_VALUE (advanced group), so:
 *  - Form load/save is handled by Exment's own machinery (the original SystemController).
 *  - Reading via System::{key}() -> auto decrypt (password), cast (bool/int), and
 *    fallback to .env when there is no row in the DB yet.
 * Exment does NOT push meili into the runtime config by itself (only mail is hardcoded), so
 * apply() is called early in ExmentServiceProvider::bootMeilisearch().
 */
class MeiliConfig
{
    /** system_name (Define) => config key. */
    public const MAP = [
        'meili_host' => 'meilisearch.host',
        'meili_key' => 'meilisearch.key',
        'meili_index' => 'meilisearch.index',
        'meili_global_search' => 'meilisearch.global_search',
        'meili_realtime_sync' => 'meilisearch.realtime_sync',
        'meili_batch_size' => 'meilisearch.batch_size',
        'meili_repair_enabled' => 'meilisearch.repair_enabled',
        'meili_repair_at' => 'meilisearch.repair_at',
        'meili_filter_mode' => 'meilisearch.filter.mode',
    ];

    /**
     * Push the stored values into config('meilisearch.*'). Wrapped in try/catch so it does not
     * break when the DB is not installed yet (fresh install).
     */
    public static function apply(): void
    {
        try {
            if (!canConnection() || !hasTable(SystemTableName::SYSTEM)) {
                return;
            }
            foreach (self::MAP as $name => $cfg) {
                $value = System::{$name}();
                if ($value !== null && $value !== '') {
                    config([$cfg => $value]);
                }
            }
        } catch (\Throwable $e) {
            // DB not ready yet -> keep the .env values.
        }
    }
}
