<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\FileServer\Library;
use Exceedone\Exment\Services\FileServer\Uploader;
use Illuminate\Console\Command;

/**
 * File server: empty the trash of the files past the days of their workspace,
 * and remove the uploads in parts that were not finished (ISS00293).
 * Runs every day from the schedule.
 */
class FileServerPurgeCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'exment:file-server-purge';

    /**
     * @var string
     */
    protected $description = 'Remove the files of the file server that stayed in the trash too long, and the unfinished uploads.';

    public function handle(): int
    {
        $trash = Library::purgeExpired();
        $uploads = Uploader::removeExpired();
        $this->info("trash: {$trash}, uploads: {$uploads}");
        return 0;
    }
}
