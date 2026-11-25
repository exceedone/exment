<?php

namespace Exceedone\Exment\Services\Storage;

use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle audit rows in custom_table_storage_transitions for the operations that are not
 * StorageTransitions transitions: provisioning, deprovisioning, staging cleanup start.
 * No cell values. Skipped (not failed) before the block-3 migration has run.
 */
final class StorageAudit
{
    /**
     * @param array<string, mixed>|null $details
     */
    public static function record(int $tableId, string $fromState, string $toState, int $fromGeneration, int $toGeneration, ?array $details = null, ?int $actorUserId = null): void
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('custom_table_storage_transitions')) {
            return;
        }
        DB::table('custom_table_storage_transitions')->insert([
            'custom_table_id' => $tableId,
            'from_state' => $fromState,
            'to_state' => $toState,
            'from_generation' => $fromGeneration,
            'to_generation' => $toGeneration,
            'attestation' => $details === null ? null : Json::encode($details),
            'actor_user_id' => $actorUserId,
            'created_at' => now(),
        ]);
    }
}
