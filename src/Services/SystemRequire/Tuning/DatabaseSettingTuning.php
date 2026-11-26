<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The memory of MySQL, MariaDB for the data (ISS02927): innodb_buffer_pool_size smaller than the
 * data and the indexes reads the disk again and again. Read when the database tells it; another
 * database, or a user that may not read it: cannot check.
 */
class DatabaseSettingTuning extends DataTuningBase
{
    /** @var int|null|false false: not read yet */
    protected $bufferPool = false;

    protected function key(): string
    {
        return 'database_settings';
    }

    /**
     * @param int|null $bufferPool for tests
     */
    public function setBufferPool(?int $bufferPool): self
    {
        $this->bufferPool = $bufferPool;
        return $this;
    }

    public function bufferPool(): ?int
    {
        if ($this->bufferPool === false) {
            $this->bufferPool = null;
            if (DataStats::isMySql()) {
                try {
                    $row = \DB::selectOne("show variables like 'innodb_buffer_pool_size'");
                    // the names of the columns are as the connection gives them (lower case in Exment)
                    $value = isset($row) ? (array_change_key_case((array) $row, CASE_LOWER)['value'] ?? null) : null;
                    $this->bufferPool = is_numeric($value) ? intval($value) : null;
                } catch (\Throwable $e) {
                }
            }
        }
        return $this->bufferPool;
    }

    protected function databaseBytes(): ?int
    {
        $bytes = $this->stats()['database']['bytes'] ?? null;
        return is_int($bytes) ? $bytes : null;
    }

    public function checkResult(): string
    {
        if (is_null($this->bufferPool()) || is_null($this->databaseBytes())) {
            return SystemRequireResult::CANNOT_CHECK;
        }
        return $this->warns() ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    protected function warns(): bool
    {
        return intval($this->bufferPool()) < intval($this->databaseBytes());
    }

    public function getResultText(): ?string
    {
        if (is_null($this->bufferPool())) {
            return exmtrans('tuning.item.database_settings.not_read');
        }
        return parent::getResultText();
    }

    protected function getMessageCannotCheck(): ?string
    {
        return is_null($this->bufferPool()) ? null : parent::getMessageCannotCheck();
    }

    protected function found(): string
    {
        return exmtrans('tuning.item.database_settings.found', ['pool' => static::bytes($this->bufferPool()), 'data' => static::bytes($this->databaseBytes())]);
    }

    public function getFixText(): ?string
    {
        if ($this->checkResult() != SystemRequireResult::WARNING) {
            return null;
        }
        // a size that holds the data and the indexes with room, in MB, by 128 MB
        $mb = (int) (ceil(intval($this->databaseBytes()) * 1.25 / 1024 / 1024 / 128) * 128);
        return "[mysqld]\ninnodb_buffer_pool_size = {$mb}M";
    }

    public function getSettingUrl(): ?string
    {
        return \Exment::getManualUrl('tuning');
    }

    public function isServerWide(): bool
    {
        return true;
    }
}
