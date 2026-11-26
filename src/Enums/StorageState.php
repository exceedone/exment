<?php

namespace Exceedone\Exment\Enums;

/**
 * Conversion phase of a custom table (hybrid storage, 02 §15.7 table; FAILED from §11.4).
 *
 * Reachable through internal code only (no route/command/UI; ConversionAvailability unavailable
 * in production): JSON <-> PROVISIONED (PhysicalSchemaManager), PROVISIONED -> DUALWRITE ->
 * BACKFILLING -> VERIFYING -> AWAITING_CONFIRMATION and back to PROVISIONED
 * (StorageTransitions). INITIALIZING: PhysicalTableInitializer (a table created as physical). PHYSICAL_READ via cutover
 * or initialization. CLEANING, CLEANED and FAILED are not entered by any code.
 */
class StorageState extends EnumBase
{
    public const JSON = 'json';
    public const PROVISIONED = 'provisioned';
    public const DUALWRITE = 'dualwrite';
    public const BACKFILLING = 'backfilling';
    public const VERIFYING = 'verifying';
    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const PHYSICAL_READ = 'physical_read';
    public const CLEANING = 'cleaning';
    public const CLEANED = 'cleaned';
    public const FAILED = 'failed';
    /**
     * A table created directly as physical, while its schema is being prepared (02 §16): application
     * data access (reads and writes) is refused; administration (column list, deletion, the conversion
     * screen, recovery) works. Entered from JSON under the exclusive storage lock after the table is
     * proven empty; left for PHYSICAL_READ (schema validated) or back to JSON (cancel).
     */
    public const INITIALIZING = 'initializing';

    /**
     * States whose single-valued columns are read from the physical columns (storage_mode physical).
     *
     * @return array<int, string>
     */
    public static function physicalReadStates(): array
    {
        return [self::PHYSICAL_READ, self::CLEANING, self::CLEANED];
    }

    /**
     * Writes go to the JSON value only (as before). PROVISIONED: the schema exists but nothing
     * writes it - provisioning alone never activates dual-write.
     *
     * @return array<int, string>
     */
    public static function jsonOnlyWriteStates(): array
    {
        return [self::JSON, self::PROVISIONED];
    }

    /**
     * Model writes go to JSON + typed columns + value_original in one statement; reads stay JSON
     * (§15.7: dualwrite ... awaiting_confirmation, storage_mode json).
     *
     * @return array<int, string>
     */
    public static function dualWriteStates(): array
    {
        return [self::DUALWRITE, self::BACKFILLING, self::VERIFYING, self::AWAITING_CONFIRMATION];
    }

    /**
     * Writes carry the projection (typed columns + value_original) AND the complete JSON value
     * (the shadow in PHYSICAL_READ): the dual-write states (storage_mode json) and PHYSICAL_READ
     * (storage_mode physical).
     *
     * @return array<int, string>
     */
    public static function projectingWriteStates(): array
    {
        return array_merge(static::dualWriteStates(), [self::PHYSICAL_READ]);
    }

    /**
     * Application data access is refused entirely (reads and writes): the schema is being prepared.
     *
     * @return array<int, string>
     */
    public static function dataAccessRefusedStates(): array
    {
        return [self::INITIALIZING];
    }

    /**
     * Writes are refused: CLEANING / CLEANED (JSON cleanup is not implemented: no write path
     * without the shadow exists), FAILED (§11.4).
     *
     * @return array<int, string>
     */
    public static function writeRefusedStates(): array
    {
        return [self::CLEANING, self::CLEANED, self::FAILED];
    }
}
