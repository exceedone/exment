<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\FileNodeVersion;
use Exceedone\Exment\Services\FileServer\FileSearch;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * File server, phase 4 (ISS00378): take the text of a version of a file for
 * the search (PDF, Office, text; OCR when it is set). A failure is written to
 * the log; the file is still found by its name and its values.
 */
class FileServerTextJob implements ShouldQueue
{
    use JobTrait;

    protected int $versionId;

    public function __construct(int $versionId)
    {
        $this->versionId = $versionId;
        $this->tries = 2;
        $this->timeout = 600;
        $this->afterCommit();
    }

    public function handle(): void
    {
        $version = FileNodeVersion::find($this->versionId);
        if (!isset($version)) {
            return;
        }
        FileSearch::makeText($version);
    }
}
