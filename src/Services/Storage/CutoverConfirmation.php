<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\System;

/**
 * A System Administrator's confirmation of one L5 report (02 §14.4 L5, §15.5): bound to the
 * verification run, the storage generation and the report version it was shown.
 */
final class CutoverConfirmation
{
    /** @var int user id (custom value id of the user table) */
    private $userId;
    /** @var int */
    private $runId;
    /** @var int */
    private $generation;
    /** @var string */
    private $reportVersion;
    /** @var \DateTimeImmutable */
    private $confirmedAt;

    public function __construct(int $userId, int $runId, int $generation, string $reportVersion, \DateTimeImmutable $confirmedAt)
    {
        $this->userId = $userId;
        $this->runId = $runId;
        $this->generation = $generation;
        $this->reportVersion = $reportVersion;
        $this->confirmedAt = $confirmedAt;
    }

    /**
     * @throws StorageConversionException
     */
    public function assertMatches(StorageL5Report $report, int $generation): void
    {
        if (!collect(System::system_admin_users())->contains($this->userId)) {
            throw new StorageConversionException('cutover confirmation refused: not a System Administrator', []);
        }
        if ($this->runId !== $report->runId() || $this->generation !== $generation || $this->generation !== $report->generation()
            || !hash_equals($report->version(), $this->reportVersion)) {
            throw new StorageConversionException('cutover confirmation does not match the current verification report', []);
        }
    }

    public function confirmedAt(): \DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'run_id' => $this->runId,
            'generation' => $this->generation,
            'report_version' => $this->reportVersion,
            'confirmed_at' => $this->confirmedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
