<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\FileServer\Collaboration;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * File server, phase 5 (ISS00379): tell the followers (or the users given)
 * of a change of a folder or file: an alert and a mail.
 */
class FileServerNotifyJob implements ShouldQueue
{
    use JobTrait;

    protected int $nodeId;
    protected string $event;
    protected ?int $actorId;

    /** @var array<string, mixed> */
    protected array $extra;

    /** @var int[]|null */
    protected ?array $targets;

    /**
     * @param array<string, mixed> $extra
     * @param int[]|null $targets
     */
    public function __construct(int $nodeId, string $event, ?int $actorId, array $extra = [], ?array $targets = null)
    {
        $this->nodeId = $nodeId;
        $this->event = $event;
        $this->actorId = $actorId;
        $this->extra = $extra;
        $this->targets = $targets;
        $this->tries = 2;
        $this->timeout = 300;
        $this->afterCommit();
    }

    public function handle(): void
    {
        Collaboration::send($this->nodeId, $this->event, $this->actorId, $this->extra, $this->targets);
    }
}
