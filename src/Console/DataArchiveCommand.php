<?php

namespace Exceedone\Exment\Console;

use Carbon\Carbon;
use Exceedone\Exment\Model\DataArchive;
use Exceedone\Exment\Services\DataArchive\ArchiveException;
use Exceedone\Exment\Services\DataArchive\DataArchiver;
use Exceedone\Exment\Services\DataArchive\Sources;
use Exceedone\Exment\Services\Features;
use Illuminate\Console\Command;

/**
 * The data archive (ISS02970).
 *
 *   php artisan exment:data-archive preview --source=revisions --before=2024-01-01
 *   php artisan exment:data-archive run --source=revisions --before=2024-01-01 [--expected=N]
 *   php artisan exment:data-archive verify --id=1
 *   php artisan exment:data-archive restore --id=1 [--preview]
 *   php artisan exment:data-archive purge
 *   php artisan exment:data-archive schedule   -- the policies, then the purge (every night)
 */
class DataArchiveCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:data-archive
                            {action : preview, run, verify, restore, purge or schedule}
                            {--source= : The source (preview, run)}
                            {--before= : The rows older than this day, YYYY-MM-DD (preview, run)}
                            {--expected= : The count of the preview; stops when it changed (run)}
                            {--id= : The archive (verify, restore)}
                            {--preview : Only tell what a restore would do}';

    /**
     * @var string
     */
    protected $description = 'Archive old rows to files and delete them once the files are checked, restore them, purge the trash of the archives.';

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        $action = strval($this->argument('action'));
        try {
            switch ($action) {
                case 'preview':
                    $preview = DataArchiver::preview($this->source(), $this->before());
                    $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
                    return 0;
                case 'run':
                    $expected = $this->option('expected');
                    $archive = DataArchiver::archive($this->source(), $this->before(), is_numeric($expected) ? intval($expected) : null);
                    $this->info("archived {$archive->row_count}, deleted {$archive->deleted_count}: #{$archive->id} {$archive->path}");
                    return $archive->warning ? 2 : 0;
                case 'verify':
                    $archive = $this->archive();
                    DataArchiver::verify($archive);
                    $this->info("#{$archive->id}: {$archive->row_count} rows, readable");
                    return 0;
                case 'restore':
                    $archive = $this->archive();
                    if (boolval($this->option('preview'))) {
                        $this->line(json_encode(DataArchiver::restorePreview($archive), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
                        return 0;
                    }
                    $this->info('restored ' . DataArchiver::restore($archive));
                    return 0;
                case 'purge':
                    $this->line(json_encode(DataArchiver::purge()) ?: '');
                    return 0;
                case 'schedule':
                    if (!DataArchive::tableExists() || !Features::allowed(DataArchiver::FEATURE)) {
                        return 0;
                    }
                    $this->line(json_encode(DataArchiver::runPolicies(), JSON_UNESCAPED_UNICODE) ?: '');
                    return 0;
            }
        } catch (ArchiveException $e) {
            $this->error($e->getMessage());
            return 1;
        }
        $this->error('unknown action: ' . $action);
        return 1;
    }

    protected function source(): string
    {
        $source = strval($this->option('source'));
        if (is_null(Sources::get($source))) {
            throw new ArchiveException('source');
        }
        return $source;
    }

    protected function before(): Carbon
    {
        $before = strval($this->option('before'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $before) !== 1) {
            throw new ArchiveException('before');
        }
        $day = Carbon::createFromFormat('Y-m-d', $before);
        if (!($day instanceof Carbon)) {
            throw new ArchiveException('before');
        }
        return $day->startOfDay();
    }

    protected function archive(): DataArchive
    {
        $archive = DataArchive::find(intval($this->option('id')));
        if (!isset($archive)) {
            throw new ArchiveException('missing');
        }
        return $archive;
    }
}
