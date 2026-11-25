<?php

namespace Exceedone\Exment\Services\Storage;

/**
 * The L5 report a System Administrator confirms before cutover (02 §14.4 L5, §15.5): the counts
 * per column and reason of the cells whose query semantics change, from one passed verification
 * run. Counts only, never values. The version is a hash of exactly what is shown, so a
 * confirmation binds to one run, one generation and one report content.
 */
final class StorageL5Report
{
    /** @var int */
    private $runId;
    /** @var int */
    private $generation;
    /** @var array<string, array<string, int>> */
    private $counts;

    /**
     * @param array<string, array<string, int>> $counts column_name => reason => count
     */
    private function __construct(int $runId, int $generation, array $counts)
    {
        ksort($counts);
        foreach ($counts as &$reasons) {
            ksort($reasons);
        }
        $this->runId = $runId;
        $this->generation = $generation;
        $this->counts = $counts;
    }

    /** The report of a verification run row (custom_table_storage_runs). */
    public static function fromRun($run): self
    {
        $counts = StorageRunRepository::counts($run);
        return new self((int)$run->id, (int)$run->generation, (array)($counts['l5'] ?? []));
    }

    public function runId(): int
    {
        return $this->runId;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    /** @return array<string, array<string, int>> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** Content hash (run, generation, counts). */
    public function version(): string
    {
        return hash('sha256', json_encode(['run' => $this->runId, 'generation' => $this->generation, 'l5' => $this->counts]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['run_id' => $this->runId, 'generation' => $this->generation, 'l5' => $this->counts, 'version' => $this->version()];
    }
}
