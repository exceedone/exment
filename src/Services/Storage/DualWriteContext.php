<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Model\CustomTable;

/**
 * One model save in a dual-write state: the storage metadata validated under the shared lock
 * (state and generation cannot change until the transaction ends) and whether the projection
 * ran for the save's INSERT/UPDATE.
 */
final class DualWriteContext
{
    /** @var TableStorage */
    private $storage;
    /** @var CustomTable */
    private $table;
    /** @var bool */
    private $projected = false;

    public function __construct(TableStorage $storage, CustomTable $table)
    {
        $this->storage = $storage;
        $this->table = $table;
    }

    public function storage(): TableStorage
    {
        return $this->storage;
    }

    public function table(): CustomTable
    {
        return $this->table;
    }

    public function markProjected(): void
    {
        $this->projected = true;
    }

    public function wasProjected(): bool
    {
        return $this->projected;
    }
}
