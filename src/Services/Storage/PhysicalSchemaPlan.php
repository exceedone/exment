<?php

namespace Exceedone\Exment\Services\Storage;

/**
 * What provisioning a table would do, computed from metadata and the live schema, before any DDL.
 *
 * columns: one entry per custom column:
 *   ['column_id', 'column_name', 'column_type', 'physical_type' (null when none),
 *    'staging_column', 'action' => add|exists|json (multi-valued)|unsupported|blocked,
 *    'virtual_column' => ['name', 'exists', 'generated'], 'indexable', 'index_name', 'reasons' => []]
 * blockers: reasons that forbid provisioning (each ['code', 'message', 'column'?]).
 * warnings: facts that do not forbid provisioning but must be resolved before a later phase.
 * limits:   the checked schema/index limits with their values.
 */
final class PhysicalSchemaPlan
{
    /** @var array<int, array<string, mixed>> */
    private $columns;
    /** @var array<int, array<string, mixed>> */
    private $blockers;
    /** @var array<int, array<string, mixed>> */
    private $warnings;
    /** @var array<string, mixed> */
    private $limits;
    /** @var string */
    private $dbTableName;
    /** @var array{name: string, action: string}|null */
    private $valueOriginal;

    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<int, array<string, mixed>> $blockers
     * @param array<int, array<string, mixed>> $warnings
     * @param array<string, mixed> $limits
     * @param array{name: string, action: string}|null $valueOriginal action add|exists|blocked
     */
    public function __construct(string $dbTableName, array $columns, array $blockers, array $warnings, array $limits, ?array $valueOriginal = null)
    {
        $this->dbTableName = $dbTableName;
        $this->columns = $columns;
        $this->blockers = $blockers;
        $this->warnings = $warnings;
        $this->limits = $limits;
        $this->valueOriginal = $valueOriginal;
    }

    /** @return array{name: string, action: string}|null the value_original column plan */
    public function valueOriginal(): ?array
    {
        return $this->valueOriginal;
    }

    public function dbTableName(): string
    {
        return $this->dbTableName;
    }

    /** @return array<int, array<string, mixed>> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return array<int, array<string, mixed>> columns whose staging column must be added */
    public function toAdd(): array
    {
        return array_values(array_filter($this->columns, function ($c) {
            return $c['action'] === 'add';
        }));
    }

    /** @return array<int, array<string, mixed>> columns whose staging column already exists (ours) */
    public function existing(): array
    {
        return array_values(array_filter($this->columns, function ($c) {
            return $c['action'] === 'exists';
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    /** @return array<int, array<string, mixed>> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, mixed> */
    public function limits(): array
    {
        return $this->limits;
    }

    public function isProvisionable(): bool
    {
        return empty($this->blockers);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'db_table_name' => $this->dbTableName,
            'columns' => $this->columns,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'limits' => $this->limits,
            'value_original' => $this->valueOriginal,
        ];
    }
}
