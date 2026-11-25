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
        // the file server not used (Feature management): its data stays as it is
        if (!Library::featureEnabled()) {
            $temp = Library::removeOldTempFiles();
            $this->info("the file server is not used: nothing removed (temp: {$temp})");
            return 0;
        }
        // the files past the days of their workspaces go to the trash first (ISS00379)
        $retention = \Exceedone\Exment\Services\FileServer\Governance::applyRetention();
        $trash = Library::purgeExpired();
        $uploads = Uploader::removeExpired();
        $temp = Library::removeOldTempFiles();
        $audit = \Exceedone\Exment\Services\FileServer\Audit::removeOld();
        $this->info("retention: {$retention}, trash: {$trash}, uploads: {$uploads}, temp: {$temp}, audit: {$audit}");
        return 0;
    }
}
