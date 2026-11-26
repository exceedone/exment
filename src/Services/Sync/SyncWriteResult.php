<?php

namespace Exceedone\Exment\Services\Sync;

/**
 * What a SyncWriter did with an item (ISS00113): created, updated, unchanged or skipped, the
 * record it is (the engine links it; null: no link), and the notes of what was left out.
 */
class SyncWriteResult
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED = 'skipped';

    public const KINDS = [self::CREATED, self::UPDATED, self::UNCHANGED, self::SKIPPED];

    /**
     * @param array<int, string> $notes
     */
    public function __construct(public string $kind, public ?int $customValueId = null, public array $notes = [])
    {
        if (!in_array($kind, static::KINDS, true)) {
            $this->kind = static::UPDATED;
        }
    }
}
