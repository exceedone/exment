<?php
namespace Exceedone\Exment\Services;

use Illuminate\Support\Str;

class TraceCollector
{
    protected array $entries = [];
    protected string $requestId;
    protected float $startTs;
    protected int $memPeak = 0;
    protected ?array $meta = null;
    protected ?string $controllerName = null;
    protected ?float $controllerDuration = null;
    protected array $dbSlow = [];

    public function __construct()
    {
        $this->requestId = (string) Str::uuid();
        $this->startTs = microtime(true);
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    // giữ entry thô (nếu cần về sau)
    public function add(string $name, string $type = 'point', array $meta = []): void
    {
        $ts = microtime(true);
        $mem = memory_get_usage();
        if ($mem > $this->memPeak)
            $this->memPeak = $mem;

        $this->entries[] = [
            'ts' => $ts,
            'type' => $type,
            'name' => $name,
            'mem' => $mem,
            'meta' => $meta,
        ];

        // detect controller enter/exit pattern to capture duration
        if ($type === 'controller') {
            if (str_ends_with($name, ':enter')) {
                $this->controllerName = substr($name, 0, -6);
            } elseif (str_ends_with($name, ':exit')) {
                // meta.duration_ms expected
                $this->controllerDuration = $meta['duration_ms'] ?? null;
                $this->controllerName = $this->controllerName ?? substr($name, 0, -5);
            }
        }

        // db:query entries handled via addDbQuery helper below
    }

    public function addDbQuery(string $sql, float $timeMs): void
    {
        // store only slow queries (threshold handled by caller)
        $this->dbSlow[] = ['sql' => $sql, 'time_ms' => $timeMs];
    }

    public function summary(array $meta = []): array
    {
        $totalMs = round((microtime(true) - $this->startTs) * 1000, 3);
        return [
            'method' => $meta['method'] ?? null,
            'uri' => $meta['uri'] ?? null,
            'route' => $meta['route'] ?? null,
            'status' => $meta['status'] ?? null,
            'total_ms' => $totalMs,
            'controller' => $this->controllerName,
            'controller_ms' => $this->controllerDuration !== null ? round($this->controllerDuration, 3) : null,
            'mem_peak' => $this->memPeak,
        ];
    }


    public function clear(): void
    {
        $this->entries = [];
        $this->dbSlow = [];
        $this->meta = null;
        $this->controllerName = null;
        $this->controllerDuration = null;
        $this->memPeak = 0;
    }
}
