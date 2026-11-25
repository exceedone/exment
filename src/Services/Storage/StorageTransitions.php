<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\DB;

/**
 * Internal coordinator of the storage state transitions of Stage B block 3 (02 §15.7, §15.14).
 * No route, command or screen calls it; every method requires ConversionAvailability, which is
 * unavailable in production (UnavailableConversion).
 *
 *   provisioned --startDualWrite (gen+1)--> dualwrite
 *   dualwrite   --startBackfill(attestation)--> backfilling
 *   backfilling --startVerification (backfill run completed, nothing pending)--> verifying
 *   verifying   --awaitConfirmation (verification passed)--> awaiting_confirmation
 *   dualwrite | backfilling | verifying | awaiting_confirmation --cancel (gen+1)--> provisioned
 * Not implemented here: awaiting_confirmation -> physical_read (cutover), cleanup, indexes.
 *
 * Each transition: one transaction, EXCLUSIVE lock of the table's custom_tables row
 * (TableStorage::lockedForTransition - waits for every participating write holding the shared
 * lock), validation on the locked values, compare-and-set UPDATE, audit row; metadata caches
 * cleared after commit. The generation changes exactly when the write target changes
 * (start / stop of dual-write); runs of an older generation become stale.
 */
class StorageTransitions
{
    /** @var ConversionAvailability */
    private $availability;
    /** @var PhysicalSchemaManager */
    private $schema;

    public function __construct(ConversionAvailability $availability, PhysicalSchemaManager $schema)
    {
        $this->availability = $availability;
        $this->schema = $schema;
    }

    /** provisioned -> dualwrite: every participating write after this commit dual-writes. */
    public function startDualWrite(CustomTable $table, ?int $actorUserId = null): TableStorage
    {
        $this->assertAvailable();
        DriverSupport::require(DriverSupport::DUAL_WRITE_TRANSITION);
        $eligibility = ConversionEligibility::check($table, $plan = $this->schema->plan($table));
        if (!$eligibility->isEligible()) {
            throw new StorageConversionException('table is not eligible', ['blockers' => $eligibility->blockers()]);
        }
        if (!empty($plan->toAdd()) || ($plan->valueOriginal()['action'] ?? null) !== 'exists') {
            throw new StorageConversionException('physical schema is not fully provisioned', ['missing' => array_merge(
                array_map(function ($e) {
                    return $e['staging_column'];
                }, $plan->toAdd()),
                ($plan->valueOriginal()['action'] ?? null) === 'exists' ? [] : [PhysicalNaming::VALUE_ORIGINAL]
            )]);
        }
        return $this->transition($table, [StorageState::PROVISIONED], StorageMode::JSON, StorageState::DUALWRITE, StorageMode::JSON, true, $actorUserId, null, function (TableStorage $locked) {
            if (StorageRunRepository::running($locked->tableId(), StagingCleanup::KIND, $locked->generation())) {
                throw new StorageConversionException('dual-write refused: a staging cleanup of this generation is running', []);
            }
        });
    }

    /**
     * dualwrite -> backfilling, on the operator's drain attestation made after dual-write started.
     */
    public function startBackfill(CustomTable $table, DrainAttestation $attestation, ?int $actorUserId = null): TableStorage
    {
        $this->assertAvailable();
        DriverSupport::require(DriverSupport::BACKFILL);
        $attestation->assertComplete();
        return $this->transition($table, [StorageState::DUALWRITE], StorageMode::JSON, StorageState::BACKFILLING, StorageMode::JSON, false, $actorUserId, ['drain' => $attestation->toArray()], function (TableStorage $locked) use ($attestation) {
            $since = DB::table('custom_table_storage_transitions')
                ->where('custom_table_id', $locked->tableId())
                ->where('to_state', StorageState::DUALWRITE)
                ->where('to_generation', $locked->generation())
                ->orderByDesc('id')->value('created_at');
            if ($since === null) {
                throw new StorageConversionException('no recorded start of dual-write for the current generation', []);
            }
            if ($attestation->attestedAt() <= new \DateTimeImmutable((string)$since)) {
                throw new StorageConversionException('the drain attestation predates the start of dual-write; it cannot cover writers of the previous generation', []);
            }
        });
    }

    /** backfilling -> verifying: the backfill run of this generation completed with nothing pending. */
    public function startVerification(CustomTable $table, ?int $actorUserId = null): TableStorage
    {
        $this->assertAvailable();
        return $this->transition($table, [StorageState::BACKFILLING], StorageMode::JSON, StorageState::VERIFYING, StorageMode::JSON, false, $actorUserId, null, function (TableStorage $locked) {
            $run = StorageRunRepository::latest($locked->tableId(), StorageRunRepository::KIND_BACKFILL, $locked->generation());
            if (!$run || $run->status !== StorageRunRepository::STATUS_COMPLETED || StorageRunRepository::pendingCount((int)$run->id) > 0) {
                throw new StorageConversionException('backfill of the current generation is not complete', []);
            }
        });
    }

    /**
     * verifying -> awaiting_confirmation: a verification run of this generation, started after
     * the backfill completed, passed with no mismatch. Backfill completion alone never suffices.
     */
    public function awaitConfirmation(CustomTable $table, ?int $actorUserId = null): TableStorage
    {
        $this->assertAvailable();
        return $this->transition($table, [StorageState::VERIFYING], StorageMode::JSON, StorageState::AWAITING_CONFIRMATION, StorageMode::JSON, false, $actorUserId, null, function (TableStorage $locked) {
            $run = StorageRunRepository::latest($locked->tableId(), StorageRunRepository::KIND_VERIFY, $locked->generation());
            if (!$run || $run->status !== StorageRunRepository::STATUS_COMPLETED || $run->result !== StorageRunRepository::RESULT_PASSED) {
                throw new StorageConversionException('no passed verification of the current generation', []);
            }
        });
    }

    /** Stop dual-write: back to provisioned (gen+1). Typed data stays; JSON is still complete. */
    public function cancel(CustomTable $table, ?int $actorUserId = null): TableStorage
    {
        $this->assertAvailable();
        return $this->transition($table, StorageState::dualWriteStates(), StorageMode::JSON, StorageState::PROVISIONED, StorageMode::JSON, true, $actorUserId, null);
    }

    /**
     * awaiting_confirmation -> physical_read (storage_mode physical, gen+1): typed columns become
     * the read source of the routed query purposes and of the public value; writes keep the
     * complete JSON shadow. Every prerequisite is re-checked under the exclusive lock:
     *  - the backfill run of the current generation completed, nothing pending;
     *  - the verification run of the current generation passed, nothing pending, started after
     *    the backfill finished, and is the run whose L5 report was confirmed;
     *  - the physical schema is complete and unchanged (no column to add, value_original present,
     *    no blocker);
     *  - the System Administrator's confirmation matches run, generation and report version and
     *    was made after the verification finished;
     *  - the operator attests, after the verification finished, that raw writers stay excluded
     *    (attestation is an operational prerequisite, not technical enforcement).
     */
    public function cutover(CustomTable $table, CutoverConfirmation $confirmation, DrainAttestation $rawWriterExclusion, ?int $actorUserId = null): TableStorage
    {
        $this->assertAvailable();
        DriverSupport::require(DriverSupport::VERIFY);
        $rawWriterExclusion->assertComplete();
        return $this->transition($table, [StorageState::AWAITING_CONFIRMATION], StorageMode::JSON, StorageState::PHYSICAL_READ, StorageMode::PHYSICAL, true, $actorUserId,
            ['raw_writer_exclusion' => $rawWriterExclusion->toArray(), 'confirmation' => $confirmation->toArray()],
            function (TableStorage $locked) use ($table, $confirmation, $rawWriterExclusion) {
                $generation = $locked->generation();
                $backfill = StorageRunRepository::latest($locked->tableId(), StorageRunRepository::KIND_BACKFILL, $generation);
                if (!$backfill || $backfill->status !== StorageRunRepository::STATUS_COMPLETED || StorageRunRepository::pendingCount((int)$backfill->id) > 0) {
                    throw new StorageConversionException('cutover refused: backfill of the current generation is not complete', []);
                }
                $verify = StorageRunRepository::latest($locked->tableId(), StorageRunRepository::KIND_VERIFY, $generation);
                if (!$verify || $verify->status !== StorageRunRepository::STATUS_COMPLETED || $verify->result !== StorageRunRepository::RESULT_PASSED
                    || StorageRunRepository::pendingCount((int)$verify->id) > 0) {
                    throw new StorageConversionException('cutover refused: no passed verification of the current generation', []);
                }
                if ((string)$verify->started_at < (string)$backfill->finished_at) {
                    throw new StorageConversionException('cutover refused: the verification started before the backfill finished', []);
                }
                $confirmation->assertMatches(StorageL5Report::fromRun($verify), $generation);
                $verified = new \DateTimeImmutable((string)$verify->finished_at);
                if ($confirmation->confirmedAt() <= $verified || $rawWriterExclusion->attestedAt() <= $verified) {
                    throw new StorageConversionException('cutover refused: confirmation and attestation must follow the verification', []);
                }
                $plan = $this->schema->plan($table);
                if (!$plan->isProvisionable() || !empty($plan->toAdd()) || ($plan->valueOriginal()['action'] ?? null) !== 'exists') {
                    throw new StorageConversionException('cutover refused: physical schema incomplete or blocked', ['blockers' => $plan->blockers()]);
                }
            });
    }

    /**
     * Fast rollback: physical_read -> dualwrite (storage_mode json, gen+1). Reads return to the
     * JSON shadow; writes keep projecting; typed columns and value_original are kept (never
     * dropped here). Conditions (02 §15.7 "Quay lại ngay"), under the exclusive lock:
     *  1. no cleanup ever started (state physical_read; cleanup is not implemented);
     *  2. every write since cutover kept the shadow - participating writes do by protocol; raw
     *     writers: the operator's attestation;
     *  3. no schema change JSON cannot represent (column changes are refused in this state;
     *     restore is refused) - the physical schema must still be complete;
     *  4. reconciliation: a passed verification run of the current (physical) generation with
     *     nothing pending, OR the explicit acceptance that the JSON shadow is the source
     *     ($acceptJsonAsSource, recorded).
     * In-flight writes finish under the previous state (the exclusive lock waits for them); runs
     * of the physical generation become stale.
     */
    public function rollback(CustomTable $table, DrainAttestation $rawWriterExclusion, bool $acceptJsonAsSource, ?int $actorUserId = null): TableStorage
    {
        $this->assertAvailable();
        $rawWriterExclusion->assertComplete();
        return $this->transition($table, [StorageState::PHYSICAL_READ], StorageMode::PHYSICAL, StorageState::DUALWRITE, StorageMode::JSON, true, $actorUserId,
            ['raw_writer_exclusion' => $rawWriterExclusion->toArray(), 'accept_json_as_source' => $acceptJsonAsSource],
            function (TableStorage $locked) use ($table, $acceptJsonAsSource) {
                if (!$acceptJsonAsSource) {
                    $verify = StorageRunRepository::latest($locked->tableId(), StorageRunRepository::KIND_VERIFY, $locked->generation());
                    if (!$verify || $verify->status !== StorageRunRepository::STATUS_COMPLETED || $verify->result !== StorageRunRepository::RESULT_PASSED
                        || StorageRunRepository::pendingCount((int)$verify->id) > 0) {
                        throw new StorageConversionException('rollback refused: no passed reconciliation of the current generation and JSON not accepted as source', []);
                    }
                }
                $plan = $this->schema->plan($table);
                if (!$plan->isProvisionable() || !empty($plan->toAdd()) || ($plan->valueOriginal()['action'] ?? null) !== 'exists') {
                    throw new StorageConversionException('rollback refused: physical schema changed', ['blockers' => $plan->blockers()]);
                }
            });
    }

    /**
     * @param array<int, string> $from
     * @param array<string, mixed>|null $audit
     * @param \Closure|null $check (TableStorage $locked) extra validation under the lock
     */
    private function transition(CustomTable $table, array $from, string $fromMode, string $to, string $toMode, bool $bumpGeneration, ?int $actorUserId, ?array $audit, ?\Closure $check = null): TableStorage
    {
        return DB::transaction(function () use ($table, $from, $fromMode, $to, $toMode, $bumpGeneration, $actorUserId, $audit, $check) {
            $locked = TableStorage::lockedForTransition((int)$table->id);
            if (!in_array($locked->state(), $from, true) || $locked->mode() !== $fromMode) {
                throw new StorageConversionException("transition to $to refused: storage is {$locked->mode()}/{$locked->state()}", []);
            }
            if ($check) {
                $check($locked);
            }
            $next = $locked->transitionTo($to, $toMode, $bumpGeneration);
            if ($next === null) {
                throw new StorageConversionException('storage state changed concurrently', []);
            }
            DB::table('custom_table_storage_transitions')->insert([
                'custom_table_id' => $locked->tableId(),
                'from_state' => $locked->state(),
                'to_state' => $to,
                'from_generation' => $locked->generation(),
                'to_generation' => $next->generation(),
                'attestation' => $audit === null ? null : \Illuminate\Database\Eloquent\Casts\Json::encode($audit),
                'actor_user_id' => $actorUserId,
                'created_at' => now(),
            ]);
            return $next;
        });
    }

    private function assertAvailable(): void
    {
        if (!$this->availability->isAvailable()) {
            throw new StorageConversionException('conversion is not available', ['missing' => $this->availability->missing()]);
        }
    }
}
