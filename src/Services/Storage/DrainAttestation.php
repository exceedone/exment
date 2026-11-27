<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Exceptions\StorageConversionException;

/**
 * The operator's explicit statement that writers outside the storage write protocol are drained
 * or excluded for a table (02 §13.5, §15.7, §15.8). Required before backfill starts.
 *
 * The protocol (StorageWriteGate) fences every write Exment's current code performs. It cannot
 * reach, and therefore this attestation must cover:
 *  - processes running code older than the participation protocol (web, queue workers,
 *    scheduler, long CLI commands) - stopped or restarted on current code;
 *  - raw SQL / query-builder writes and plugins writing `value->` on the table - none, or
 *    excluded for the duration of the conversion;
 *  - external tools writing the database directly (BI, scripts) - none.
 * An elapsed time is not a drain: the attestation records what was done, when and by whom,
 * and must be made after dual-write started (it is checked against the transition log).
 */
final class DrainAttestation
{
    public const OLD_CODE_PROCESSES_STOPPED = 'old_code_processes_stopped';
    public const QUEUE_WORKERS_ON_CURRENT_CODE = 'queue_workers_on_current_code';
    public const SCHEDULER_AND_CLI_ON_CURRENT_CODE = 'scheduler_and_cli_on_current_code';
    public const NO_RAW_OR_PLUGIN_VALUE_WRITERS = 'no_raw_or_plugin_value_writers';
    public const NO_EXTERNAL_DIRECT_WRITERS = 'no_external_direct_writers';

    public const REQUIRED = [
        self::OLD_CODE_PROCESSES_STOPPED,
        self::QUEUE_WORKERS_ON_CURRENT_CODE,
        self::SCHEDULER_AND_CLI_ON_CURRENT_CODE,
        self::NO_RAW_OR_PLUGIN_VALUE_WRITERS,
        self::NO_EXTERNAL_DIRECT_WRITERS,
    ];

    /** @var string */
    private $attestedBy;
    /** @var \DateTimeImmutable */
    private $attestedAt;
    /** @var array<string, bool> */
    private $confirmations;
    /** @var string */
    private $measures;

    /**
     * @param array<string, bool> $confirmations every REQUIRED code => true
     * @param string $measures what was done (optional; e.g. "queue:restart at ..., deploy ..., plugin X disabled")
     */
    public function __construct(string $attestedBy, \DateTimeImmutable $attestedAt, array $confirmations, string $measures)
    {
        $this->attestedBy = $attestedBy;
        $this->attestedAt = $attestedAt;
        $this->confirmations = $confirmations;
        $this->measures = $measures;
    }

    /**
     * @throws StorageConversionException incomplete attestation
     */
    public function assertComplete(): void
    {
        $missing = [];
        foreach (self::REQUIRED as $code) {
            if (($this->confirmations[$code] ?? false) !== true) {
                $missing[] = $code;
            }
        }
        if (trim($this->attestedBy) === '') {
            $missing[] = 'attested_by';
        }
        if (!empty($missing)) {
            throw new StorageConversionException('drain attestation incomplete', ['missing' => $missing]);
        }
    }

    public function attestedAt(): \DateTimeImmutable
    {
        return $this->attestedAt;
    }

    /** @return array<string, mixed> persisted with the transition (no data values) */
    public function toArray(): array
    {
        return [
            'attested_by' => $this->attestedBy,
            'attested_at' => $this->attestedAt->format(\DateTimeInterface::ATOM),
            'confirmations' => $this->confirmations,
            'measures' => $this->measures,
        ];
    }
}
