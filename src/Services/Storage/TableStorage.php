<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\DB;

/**
 * Storage metadata of one custom table: mode, state, generation (02 §2.1, §11.4, §15.7).
 *
 * Guards and transitions read it FRESH from custom_tables (never from the CustomTable model,
 * which is cached per request and possibly in the persistent cache), and change it only by a
 * compare-and-set on (state, generation). Before the storage migration has run the columns are
 * missing: every table is then reported as JSON, which is what it is.
 */
final class TableStorage
{
    /** @var int */
    private $tableId;
    /** @var string */
    private $mode;
    /** @var string */
    private $state;
    /** @var int */
    private $generation;

    private function __construct(int $tableId, string $mode, string $state, int $generation)
    {
        $this->tableId = $tableId;
        $this->mode = $mode;
        $this->state = $state;
        $this->generation = $generation;
    }

    /**
     * Whether the storage columns exist on custom_tables (the migration has run).
     * Checked against the schema, not the cached column listing.
     */
    public static function installed(): bool
    {
        // only a positive answer is kept: the migration may run later in the same process
        if (static::$installed) {
            return true;
        }
        return static::$installed = DB::connection()->getSchemaBuilder()->hasColumns('custom_tables', ['storage_mode', 'storage_state', 'storage_generation']);
    }

    /** @var bool */
    private static $installed = false;

    /**
     * The table's storage metadata read from the database now.
     *
     * @param CustomTable|int|string $table
     */
    public static function fresh($table): self
    {
        $id = (int)($table instanceof CustomTable ? $table->id : $table);
        if (!static::installed()) {
            return new self($id, StorageMode::JSON, StorageState::JSON, 0);
        }
        $row = DB::table('custom_tables')->where('id', $id)->first(['storage_mode', 'storage_state', 'storage_generation']);
        if (!$row) {
            throw new \InvalidArgumentException("custom table $id not found");
        }
        return new self(
            $id,
            (string)($row->storage_mode ?? StorageMode::JSON),
            (string)($row->storage_state ?? StorageState::JSON),
            (int)($row->storage_generation ?? 0)
        );
    }

    /**
     * The table's storage metadata read under a SHARED row lock of custom_tables. Must run inside
     * a transaction: the lock is held until it ends, so a transition (an UPDATE of that row,
     * transitionTo()) waits for every write that validated the state this way, and no write
     * starts against a state that is being changed. Lock clause per driver by the query builder
     * (MySQL/MariaDB `lock in share mode`, SQL Server `with(rowlock,holdlock)`).
     *
     * @return self|null null when the storage columns are not installed or the table row is gone
     */
    public static function lockedForWrite(int $tableId): ?self
    {
        if (!static::installed()) {
            return null;
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('TableStorage::lockedForWrite() needs an open transaction');
        }
        $query = DB::table('custom_tables')->where('id', $tableId);
        if (PhysicalSchemaManager::driverFamily() === 'mysql') {
            // MySQL 8: FOR SHARE (LOCK IN SHARE MODE is deprecated there); MariaDB keeps the
            // query builder's LOCK IN SHARE MODE; SQL Server: WITH (ROWLOCK, HOLDLOCK)
            $query->lock('for share');
        } else {
            $query->sharedLock();
        }
        $row = $query->first(['storage_mode', 'storage_state', 'storage_generation']);
        if (!$row) {
            return null;
        }
        return new self($tableId, (string)$row->storage_mode, (string)$row->storage_state, (int)$row->storage_generation);
    }

    /**
     * The storage metadata as the (request/persistent-cached) CustomTable model holds it - for
     * READ routing only, never for guards or transitions. A stale value is safe for reads in
     * every reachable state: from dualwrite to physical_read the JSON shadow and the typed
     * columns are written together, so either source returns the same public value; only the
     * L5 query semantics of a stale reader lag one request/job behind a cutover or rollback.
     */
    public static function fromModel(CustomTable $table): self
    {
        $mode = $table->getAttribute('storage_mode');
        $state = $table->getAttribute('storage_state');
        return new self(
            (int)$table->id,
            $mode === null ? StorageMode::JSON : (string)$mode,
            $state === null ? StorageState::JSON : (string)$state,
            (int)($table->getAttribute('storage_generation') ?? 0)
        );
    }

    /** Application data access (reads and writes of values) is refused in this state: schema being prepared (02 §16). */
    public function refusesDataAccess(): bool
    {
        return in_array($this->state, \Exceedone\Exment\Enums\StorageState::dataAccessRefusedStates(), true);
    }

    /** Single-valued typed columns are the read source (storage_mode physical, physical-read state). */
    public function readsPhysical(): bool
    {
        return $this->mode === StorageMode::PHYSICAL && in_array($this->state, StorageState::physicalReadStates(), true);
    }

    /** The JSON shadow is complete and synchronized (every projecting write state; no cleanup exists). */
    public function hasCompleteShadow(): bool
    {
        return in_array($this->state, array_merge(StorageState::jsonOnlyWriteStates(), StorageState::projectingWriteStates()), true);
    }

    /**
     * Ids of the tables whose state is not plain JSON (fresh). Empty before the migration.
     *
     * @return array<int, int>
     */
    public static function nonJsonTableIds(): array
    {
        if (!static::installed()) {
            return [];
        }
        return DB::table('custom_tables')
            ->where(function ($query) {
                $query->where('storage_state', '<>', StorageState::JSON)
                    ->orWhere('storage_mode', '<>', StorageMode::JSON);
            })
            ->pluck('id')->map(function ($id) {
                return (int)$id;
            })->all();
    }

    /**
     * The table's storage metadata read under an EXCLUSIVE row lock of custom_tables (transitions).
     * Must run inside a transaction; waits for every write holding the shared lock.
     */
    public static function lockedForTransition(int $tableId): self
    {
        if (!static::installed()) {
            throw new \RuntimeException('the storage columns of custom_tables are not installed (run the migrations)');
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('TableStorage::lockedForTransition() needs an open transaction');
        }
        $row = DB::table('custom_tables')->where('id', $tableId)->lockForUpdate()
            ->first(['storage_mode', 'storage_state', 'storage_generation']);
        if (!$row) {
            throw new \InvalidArgumentException("custom table $tableId not found");
        }
        return new self($tableId, (string)$row->storage_mode, (string)$row->storage_state, (int)$row->storage_generation);
    }

    /**
     * Compare-and-set transition: succeeds only when the row still has the state and generation
     * this object was read with. The generation is incremented when the read source or the write
     * target changes (§11.4); a schema-only step (provisioning) does not change either.
     *
     * @return self|null the new metadata, or null when another process changed the row first
     */
    public function transitionTo(string $state, string $mode, bool $bumpGeneration): ?self
    {
        if (!StorageState::isValid($state) || !StorageMode::isValid($mode)) {
            throw new \InvalidArgumentException("invalid storage state/mode: $state/$mode");
        }
        if (!static::installed()) {
            throw new \RuntimeException('the storage columns of custom_tables are not installed (run the migrations)');
        }
        $generation = $bumpGeneration ? $this->generation + 1 : $this->generation;
        $updated = DB::table('custom_tables')
            ->where('id', $this->tableId)
            ->where('storage_state', $this->state)
            ->where('storage_mode', $this->mode)
            ->where('storage_generation', $this->generation)
            ->update([
                'storage_state' => $state,
                'storage_mode' => $mode,
                'storage_generation' => $generation,
            ]);
        if ($updated !== 1) {
            return null;
        }
        // The cached CustomTable models are stale now. Same invalidation as any save of a
        // CustomTable today (ClearCacheTrait => System::clearCache()); other processes keep
        // their cached model until their own cache is cleared - which is why guards read fresh
        // and why request/job generation checks are a later block (§11.4).
        // after commit when called inside a transaction (a cache rebuilt before the commit would
        // hold the old state)
        System::clearCacheAfterCommit();
        return new self($this->tableId, $mode, $state, $generation);
    }

    public function tableId(): int
    {
        return $this->tableId;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    /** Plain JSON table: the only state in which existing behaviour applies unchanged. */
    public function isJson(): bool
    {
        return $this->mode === StorageMode::JSON && $this->state === StorageState::JSON;
    }

    /** @return array{mode: string, state: string, generation: int} */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'state' => $this->state, 'generation' => $this->generation];
    }
}
