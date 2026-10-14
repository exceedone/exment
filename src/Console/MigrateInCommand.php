<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Migration\MigrationService;
use Exceedone\Exment\Services\Migration\Preset;
use Exceedone\Exment\Services\Migration\Sources\BacklogSource;
use Exceedone\Exment\Services\Migration\Sources\FileSource;
use Exceedone\Exment\Services\Migration\Sources\ServiceNowSource;
use Illuminate\Console\Command;

/**
 * Bring another system's data into Exment.
 *
 * The order somebody actually uses these in:
 *
 *   exment:migrate-in --source=backlog --check          does it connect
 *   exment:migrate-in --source=backlog --fetch-only     pull to disk, once
 *   exment:migrate-in --from-file --dry                 what would happen
 *   exment:migrate-in --from-file                       do it
 *
 * The middle two are the point. Fetching is slow and rate limited; mapping is
 * where the mistakes are. Doing them separately means the fetch is paid for
 * once and the mapping can be argued about all afternoon.
 */
class MigrateInCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:migrate-in
        {--source= : backlog, servicenow or file}
        {--preset= : mapping preset to use (default: same name as the source)}
        {--dir= : staging folder under storage/app/migration (default: the preset name)}
        {--check : only test the connection and stop}
        {--presets : list the available presets and stop}
        {--publish= : copy a preset into storage so it can be edited, then stop}
        {--fetch-only : pull to disk and stop, writing nothing to Exment}
        {--from-file : skip the fetch and import what is already staged}
        {--dry : map and count, but write nothing}
        {--limit= : at most this many records per stream}
        {--streams= : comma separated list of streams, default all}
        {--projects= : backlog only - project keys, comma separated}
        {--tables= : servicenow only - tables to pull, comma separated}
        {--since= : only records changed on or after this date}
        {--as= : login user id to record as the author of the imported rows}';

    /**
     * @var string
     */
    protected $description = 'Import data from Backlog or ServiceNow into Exment';

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    /**
     * @return int
     */
    public function handle()
    {
        try {
            if ($this->option('presets')) {
                return $this->listPresets();
            }

            if ($name = $this->option('publish')) {
                $this->info('copied to ' . Preset::publish(strval($name)));
                $this->line('Edit that copy - it wins over the one in the package and survives updates.');
                return 0;
            }

            return $this->migrate();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    /**
     * @return int
     */
    protected function listPresets(): int
    {
        $rows = [];
        foreach (Preset::names() as $name => $path) {
            $preset = Preset::load($name);
            $tables = [];
            foreach ((array)array_get($preset, 'streams', []) as $stream) {
                if (!array_get($stream, 'skip') && array_get($stream, 'table')) {
                    $tables[] = array_get($stream, 'table');
                }
            }
            $rows[] = [$name, array_get($preset, 'label', ''), implode(', ', $tables)];
        }

        if (empty($rows)) {
            $this->warn('no presets found');
            return 0;
        }

        $this->table(['preset', 'label', 'tables it creates'], $rows);

        return 0;
    }

    /**
     * @return int
     */
    protected function migrate(): int
    {
        $fromFile = boolval($this->option('from-file'));
        $sourceName = strval($this->option('source') ?: ($fromFile ? 'file' : ''));

        if ($sourceName === '') {
            $this->error('say where the data comes from: --source=backlog, --source=servicenow, or --from-file');
            return 1;
        }

        $presetName = strval($this->option('preset') ?: ($sourceName == 'file' ? '' : $sourceName));
        if ($presetName === '') {
            $this->error('--from-file needs --preset= so it knows how to map what it finds');
            return 1;
        }

        $preset = Preset::load($presetName);
        $directory = strval($this->option('dir') ?: $presetName);

        $this->authenticate();

        $service = (new MigrationService($preset, $directory))
            ->dry(boolval($this->option('dry')))
            ->onProgress(function ($stream, $done, $phase) {
                // rewritten in place so a long run shows movement without
                // scrolling a thousand lines past
                $this->output->write(sprintf("\r  %-24s %-6s %8d", $stream, $phase, $done));
            });

        $this->line('preset    : ' . $presetName . ' (' . array_get($preset, 'label', '') . ')');
        $this->line('staging   : ' . $service->directory());

        // ------------------------------------------------------------ fetch --
        if (!$fromFile) {
            $source = $this->source($sourceName);

            $check = $source->check();
            $this->line('source    : ' . array_get($check, 'message'));

            if (!array_get($check, 'ok')) {
                return 1;
            }

            if ($this->option('check')) {
                return 0;
            }

            $this->info('fetching...');
            $manifest = $service->fetch($source, $this->fetchOptions());
            $this->clearProgress();

            $rows = [];
            foreach ((array)array_get($manifest, 'counts', []) as $stream => $count) {
                $rows[] = [$stream, $count];
            }
            $this->table(['stream', 'records fetched'], $rows);
            $this->line(sprintf('%d api call(s), %ds waited on rate limits', $source->calls(), $source->waited()));

            if ($this->option('fetch-only')) {
                $this->info('stopped after the fetch. Nothing was written to Exment.');
                $this->line('Next: php artisan exment:migrate-in --from-file --preset=' . $presetName . ' --dir=' . $directory . ' --dry');
                return 0;
            }
        } elseif ($this->option('check')) {
            $check = (new FileSource(['directory' => $service->directory()]))->check();
            $this->line('staged    : ' . array_get($check, 'message'));
            return array_get($check, 'ok') ? 0 : 1;
        }

        // ------------------------------------------------------------ apply --
        $this->info($this->option('dry') ? 'dry run...' : 'importing...');

        $result = $service->apply();
        $this->clearProgress();

        if (!array_get($result, 'ok')) {
            $this->error('stopped before writing anything:');
            foreach ((array)array_get($result, 'problems', []) as $problem) {
                $this->error('  - ' . $problem);
            }
            return 1;
        }

        $this->report($result, $service);

        return 0;
    }

    /**
     * @param array<string, mixed> $result
     * @param MigrationService $service
     * @return void
     */
    protected function clearProgress()
    {
        $this->output->write("\r" . str_repeat(' ', 48) . "\r");
    }

    /**
     * @param array<string, mixed> $result
     * @param MigrationService $service
     * @return void
     */
    protected function report(array $result, MigrationService $service)
    {
        $created = (array)array_get($result, 'schema.created', []);
        if (!empty($created)) {
            $this->info('tables created: ' . implode(', ', $created));
        }

        $rows = [];
        $totals = ['read' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ((array)array_get($result, 'counters', []) as $stream => $counter) {
            $rows[] = [
                $stream,
                array_get($counter, 'read', 0),
                array_get($counter, 'created', 0),
                array_get($counter, 'updated', 0),
                array_get($counter, 'skipped', 0),
                array_get($counter, 'failed', 0),
            ];
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + intval(array_get($counter, $key, 0));
            }
        }

        $rows[] = new \Symfony\Component\Console\Helper\TableSeparator();
        $rows[] = ['total', $totals['read'], $totals['created'], $totals['updated'], $totals['skipped'], $totals['failed']];

        $this->table(['stream', 'read', 'created', 'updated', 'skipped', 'failed'], $rows);

        $patched = (array)array_get($result, 'patched', []);
        if (array_get($patched, 'pending')) {
            $this->line(sprintf(
                'links filled in on the second pass: %d of %d (%d still unresolved)',
                array_get($patched, 'patched', 0),
                array_get($patched, 'pending', 0),
                array_get($patched, 'unresolved', 0)
            ));
        }

        $notes = $service->notes();
        if (!empty($notes)) {
            $this->newLine();
            $this->comment('worth reading:');
            foreach ($notes as $note) {
                $this->line('  - ' . $note);
            }
        }

        if (array_get($result, 'dry')) {
            $this->newLine();
            $this->warn('dry run: no table and no record was written.');
        }

        if ($totals['failed'] > 0) {
            $this->newLine();
            $this->error(sprintf('%d record(s) failed. They are listed above; nothing else was rolled back.', $totals['failed']));
        }
    }

    /**
     * @param string $name
     * @return \Exceedone\Exment\Services\Migration\Sources\SourceInterface
     * @throws \Exception
     */
    protected function source(string $name)
    {
        switch ($name) {
            case 'backlog':
                return new BacklogSource([]);

            case 'servicenow':
                return new ServiceNowSource([
                    'tables' => $this->listOption('tables'),
                ]);

            case 'file':
                return new FileSource([
                    'directory' => strval($this->option('dir') ?: $this->option('preset')),
                ]);
        }

        throw new \Exception('no source called "' . $name . '". Use backlog, servicenow or file.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchOptions(): array
    {
        $options = [];

        if ($limit = $this->option('limit')) {
            $options['limit'] = intval($limit);
        }
        if ($since = $this->option('since')) {
            $options['since'] = strval($since);
        }
        if ($streams = $this->listOption('streams')) {
            $options['streams'] = $streams;
        }
        if ($projects = $this->listOption('projects')) {
            $options['projects'] = $projects;
        }

        return $options;
    }

    /**
     * @param string $name
     * @return string[]
     */
    protected function listOption(string $name): array
    {
        $value = $this->option($name);

        if (is_nullorempty($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', strval($value)))));
    }

    /**
     * Run as somebody, so the imported rows have an author.
     *
     * Without this every row is created by nobody, which is awkward the first
     * time an auditor asks where a few thousand tickets came from.
     *
     * @return void
     */
    protected function authenticate()
    {
        $id = $this->option('as');

        if (is_nullorempty($id)) {
            $admins = (array)System::system_admin_users();
            $login = LoginUser::query()
                ->when(!empty($admins), function ($query) use ($admins) {
                    return $query->whereIn('base_user_id', $admins);
                })
                ->orderBy('id')
                ->first();

            $id = $login ? $login->id : null;
        }

        if (is_nullorempty($id)) {
            $this->warn('no login user to run as: imported rows will have no author. Pass --as=<login user id> to fix that.');
            return;
        }

        $login = LoginUser::find($id);
        if (!isset($login)) {
            $this->warn('there is no login user ' . $id . '; imported rows will have no author.');
            return;
        }

        \Auth::guard(Define::AUTHENTICATE_KEY_WEB)->onceUsingId($login->id);

        $this->line('running as: ' . ($login->base_user->getLabel() ?? $login->id));
    }
}
