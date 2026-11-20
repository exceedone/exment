<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\FileNodeVersion;
use Exceedone\Exment\Services\FileServer\Preview;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * File server: make the thumbnail and the PDF for the preview of a version
 * of a file (ISS00293, phase 2). A failure is written to the log; the file
 * can still be downloaded.
 */
class FileServerRenditionJob implements ShouldQueue
{
    use JobTrait;

    protected int $versionId;

    public function __construct(int $versionId)
    {
        $this->versionId = $versionId;
        $this->tries = 2;
        $this->timeout = 300;
        $this->afterCommit();
    }

    public function handle(): void
    {
        $version = FileNodeVersion::find($this->versionId);
        if (!isset($version)) {
            return;
        }
        Preview::makeRenditions($version);
    }
}
