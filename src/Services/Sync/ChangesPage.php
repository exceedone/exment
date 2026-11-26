<?php

namespace Exceedone\Exment\Services\Sync;

/**
 * A page of the changed items (ISS00113): the items, the cursor of the next page, and whether
 * there is none.
 */
class ChangesPage
{
    /**
     * @param array<int, RemoteItem> $items
     */
    public function __construct(public array $items, public ?string $cursor, public bool $done)
    {
    }
}
