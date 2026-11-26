<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

/**
 * The attachments (ISS02927): how many and how large, and the files of data that is no more
 * (deleted for good) that no page shows.
 */
class AttachmentTuning extends DataTuningBase
{
    protected function key(): string
    {
        return 'attachments';
    }

    /**
     * @return array{count: int, bytes: int, bytes_partial: bool, orphans: int}
     */
    protected function files(): array
    {
        $files = (array) ($this->stats()['files'] ?? []);
        return [
            'count' => intval($files['count'] ?? 0),
            'bytes' => intval($files['bytes'] ?? 0),
            'bytes_partial' => boolval($files['bytes_partial'] ?? false),
            'orphans' => intval($files['orphans'] ?? 0),
        ];
    }

    protected function warns(): bool
    {
        return $this->files()['orphans'] > 0;
    }

    protected function found(): string
    {
        $files = $this->files();
        $text = exmtrans($files['bytes_partial'] ? 'tuning.item.attachments.total_partial' : 'tuning.item.attachments.total', [
            'count' => number_format($files['count']),
            'size' => static::bytes($files['bytes']),
            'limit' => number_format(DataStats::FILE_SIZE_LIMIT),
        ]);
        if ($files['orphans'] > 0) {
            $text .= ' / ' . exmtrans('tuning.item.attachments.orphans', ['count' => number_format($files['orphans'])]);
        }
        return $text;
    }
}
