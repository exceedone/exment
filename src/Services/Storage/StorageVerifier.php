<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Model\CustomTable;

/**
 * Verification (02 §15.2, §15.7, §15.10-1): for every row, the public value reconstructed from
 * the typed columns, value_original and the retained JSON must equal the JSON value, in state
 * `verifying`. Read-only: it never repairs a row.
 *
 * publicValue(row)[c] for a single-valued typed column c:
 *   value_original has c -> value_original[c] (the original JSON value, type included)
 *   typed column not NULL -> render(type, typed)      (PublicValueRenderer, a string)
 *   otherwise             -> absent
 * Multi-valued columns and other keys come from the retained JSON unchanged.
 * Compared with the JSON value cell (null = absent, V-6) as JSON values - type and
 * representation included ("12" differs from 12; "1.50" differs from "1.5").
 * Also checked per cell: the typed column equals the conversion of the JSON cell (query
 * semantics), a value_original entry exists only where the conversion is not lossless, and
 * value_original has no key that is not a single-valued typed column.
 *
 * A mismatching row is recorded pending (reason, column names; no value) and re-checked after the
 * range; the run completes with result passed (nothing pending) or failed. The boundary is
 * extended to the current max id when reached. L5 counts per column and reason are taken from
 * the range pass (each row once).
 */
class StorageVerifier extends StorageRunner
{
    protected function kind(): string
    {
        return StorageRunRepository::KIND_VERIFY;
    }

    protected function requiredState(): string
    {
        return StorageState::VERIFYING;
    }

    /**
     * Also in physical_read: the reconciliation a fast rollback may rely on (02 §15.7) and
     * periodic detection (never a substitute for the write protocol).
     */
    protected function allowedStates(): array
    {
        return [StorageState::VERIFYING, StorageState::PHYSICAL_READ];
    }

    /** A failed verification may be run again after repairs; only a passed one is settled. */
    protected function isSettled($run): bool
    {
        return $run->status === StorageRunRepository::STATUS_COMPLETED && $run->result === StorageRunRepository::RESULT_PASSED;
    }

    protected function capability(): string
    {
        return DriverSupport::VERIFY;
    }

    protected function completesWithPending(): bool
    {
        return true;
    }

    protected function extendsBoundary(): bool
    {
        return true;
    }

    protected function columns(CustomTable $table): array
    {
        $columns = ['id', 'value', PhysicalNaming::VALUE_ORIGINAL];
        foreach ($this->typedColumns($table) as $c) {
            $columns[] = $c['pcol'];
        }
        return $columns;
    }

    protected function processRows(CustomTable $table, $rows, array &$counts, bool $retry): array
    {
        DualWriter::assertColumnsExist(getDBTableName($table), array_keys(Projection::of($table, null)->typed()));
        $typedColumns = $this->typedColumns($table);
        $outcomes = [];
        foreach ($rows as $row) {
            $json = Projection::decode($row->value) ?? [];
            $original = Projection::decode($row->{PhysicalNaming::VALUE_ORIGINAL}) ?? [];
            $mismatch = [];
            $reasons = [];

            foreach ($typedColumns as $c) {
                $name = $c['name'];
                $expected = array_key_exists($name, $json) ? $json[$name] : null;
                $typedDb = $row->{$c['pcol']};
                $hasOriginal = array_key_exists($name, $original);

                // the public value
                if ($hasOriginal) {
                    $public = $original[$name];
                } elseif ($typedDb !== null) {
                    $public = PublicValueRenderer::render($c['type'], $typedDb);
                } else {
                    $public = null;
                }
                if (!static::same($expected, $public)) {
                    $mismatch[$name] = 'public_value';
                    continue;
                }

                // the typed value and the need for an original entry
                if ($expected === null) {
                    if ($typedDb !== null || $hasOriginal) {
                        $mismatch[$name] = 'typed_value';
                    }
                    continue;
                }
                $cell = CellConverter::convertAs($c['type'], $expected);
                $expectedTyped = $cell->typed() === null ? null : PublicValueRenderer::render($c['type'], $cell->typed());
                $actualTyped = $typedDb === null ? null : PublicValueRenderer::render($c['type'], $typedDb);
                if ($expectedTyped !== $actualTyped) {
                    $mismatch[$name] = 'typed_value';
                    continue;
                }
                if ($cell->isLossless() === $hasOriginal) {
                    $mismatch[$name] = 'original_entry';
                    continue;
                }
                if (!$cell->isLossless()) {
                    $reasons[$name] = (string)$cell->reason();
                }
            }
            foreach (array_keys($original) as $key) {
                if (!isset($typedColumns[$key])) {
                    $mismatch[(string)$key] = 'stray_original';
                }
            }

            if (!$retry) {
                StorageRunRepository::addL5($counts, $reasons);
            }
            if (empty($mismatch)) {
                $counts['matched']++;
                $outcomes[(int)$row->id] = 'settled';
            } else {
                $outcomes[(int)$row->id] = ['pending', 'mismatch', array_keys($mismatch)];
            }
        }
        return $outcomes;
    }

    /** @return array<string, array{name: string, pcol: string, type: string}> keyed by column_name */
    private function typedColumns(CustomTable $table): array
    {
        $out = [];
        foreach ($table->custom_columns_cache as $column) {
            $type = PhysicalColumnType::of($column);
            if ($type === null) {
                continue;
            }
            $name = (string)$column->column_name;
            $out[$name] = ['name' => $name, 'pcol' => PhysicalNaming::stagingColumn($column), 'type' => $type];
        }
        return $out;
    }

    /** Same JSON value (type and representation). */
    private static function same($a, $b): bool
    {
        return json_encode($a) === json_encode($b);
    }
}
