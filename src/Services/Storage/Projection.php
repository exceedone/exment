<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Model\CustomTable;

/**
 * The physical projection of one row's JSON value (02 §15.2, §15.4, §15.13): for every
 * single-valued column with a physical type, the typed value of its staging column, and the
 * value_original object of the cells typed storage cannot reconstruct. A pure function of the
 * JSON - the same for the model dual-write, the set-based writes and the backfill.
 *
 * null and absent are the same cell (V-2 / V-6): typed NULL, no value_original entry.
 * Multi-valued columns and keys that are not columns of the table are not projected (JSON only).
 */
final class Projection
{
    /** @var array<string, int|string|null> staging column => typed value */
    private $typed = [];
    /** @var array<string, mixed> column_name => original JSON value (non-lossless cells) */
    private $original = [];
    /** @var array<string, string> column_name => reason (non-lossless cells; counts only) */
    private $reasons = [];

    /**
     * @param array<string, mixed>|null $json decoded `value`
     */
    public static function of(CustomTable $table, ?array $json): self
    {
        $projection = new self();
        $json = $json ?? [];
        foreach ($table->custom_columns_cache as $column) {
            $type = PhysicalColumnType::of($column);
            if ($type === null) {
                continue;
            }
            $projection->put(PhysicalNaming::stagingColumn($column), (string)$column->column_name, $type, $json);
        }
        return $projection;
    }

    /**
     * The projection of one column only (set-based write of one key).
     *
     * @param array<string, mixed>|null $json
     */
    public static function ofColumn(\Exceedone\Exment\Model\CustomColumn $column, ?array $json): self
    {
        $projection = new self();
        $type = PhysicalColumnType::of($column);
        if ($type !== null) {
            $projection->put(PhysicalNaming::stagingColumn($column), (string)$column->column_name, $type, $json ?? []);
        }
        return $projection;
    }

    /** @param array<string, mixed> $json */
    private function put(string $pcol, string $name, string $type, array $json): void
    {
        $value = array_key_exists($name, $json) ? $json[$name] : null;
        if ($value === null) {
            $this->typed[$pcol] = null;
            return;
        }
        $cell = CellConverter::convertAs($type, $value);
        $this->typed[$pcol] = $cell->typed();
        if (!$cell->isLossless()) {
            $this->original[$name] = $value;
            $this->reasons[$name] = (string)$cell->reason();
        }
    }

    /** @return array<string, int|string|null> */
    public function typed(): array
    {
        return $this->typed;
    }

    /** @return array<string, mixed> */
    public function original(): array
    {
        return $this->original;
    }

    /** @return array<string, string> */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /**
     * Decode a stored JSON text (or an already decoded array) to an array; null when absent.
     *
     * @param mixed $json
     * @return array<string, mixed>|null
     */
    public static function decode($json): ?array
    {
        if (is_array($json)) {
            return $json;
        }
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
