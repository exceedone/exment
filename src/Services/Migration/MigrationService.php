<?php

namespace Exceedone\Exment\Services\Migration;

use Carbon\Carbon;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Services\Migration\Sources\FileSource;
use Exceedone\Exment\Services\Migration\Sources\SourceInterface;

/**
 * Brings the contents of another system into Exment.
 *
 * The run is deliberately two halves with the disk in between:
 *
 *   fetch   source API  ->  storage/app/migration/<run>/<stream>.jsonl
 *   apply   those files ->  Exment tables and records
 *
 * That split is the single most useful decision in here, and it is not about
 * tidiness. Fetching is slow, rate limited and often only possible during a
 * window somebody had to arrange. Mapping is where every mistake lives and it
 * takes half a dozen attempts to get right. Keeping them together means paying
 * the fetch again for every attempt; keeping them apart means the fetch happens
 * once, and the six attempts are six local re-reads. It also leaves the raw
 * dump behind, which is the only thing that can answer "did the old system
 * really say that" once the old system is switched off.
 *
 * Everything written carries a migration key - "backlog:issue:DEMO-42" - so a
 * second run updates what the first run wrote instead of duplicating it.
 */
class MigrationService
{
    use Concerns\MapsValues;
    use Concerns\ResolvesReferences;
    use Concerns\CollectsChoices;

    /** Written alongside the dump so a directory explains itself later. */
    public const MANIFEST = '_manifest.json';

    /** Records between progress callbacks. */
    public const TICK = 200;

    /** @var array<string, mixed> */
    protected $preset;

    /** @var string */
    protected $directory;

    /** @var bool */
    protected $dry = false;

    /** @var string[] */
    protected $notes = [];

    /** @var array<string, array<string, int>> */
    protected $counters = [];

    /** @var callable|null */
    protected $progress = null;

    /**
     * table name => [migration key => record id]
     *
     * @var array<string, array<string, int>>
     */
    protected $keyMaps = [];

    /**
     * lower-cased email => Exment user record id
     *
     * @var array<string, int>|null
     */
    protected $userMap = null;

    /**
     * Indexes built from a staged stream, for translating one field to another.
     *
     * @var array<string, array<string, string>>
     */
    protected $viaMaps = [];

    /**
     * References that could not be resolved on the first pass, patched at the
     * end once every stream has landed.
     *
     * @var array<int, array<string, mixed>>
     */
    protected $pending = [];

    /**
     * @param array<string, mixed> $preset
     * @param string $directory staging directory, absolute or a name under storage/app/migration
     */
    public function __construct(array $preset, string $directory)
    {
        $this->preset = $preset;
        $this->directory = $this->resolveDirectory($directory);
    }

    /**
     * @param bool $dry
     * @return $this
     */
    public function dry(bool $dry = true)
    {
        $this->dry = $dry;
        return $this;
    }

    /**
     * @param callable|null $callback function(string $stream, int $done, string $phase)
     * @return $this
     */
    public function onProgress($callback)
    {
        $this->progress = $callback;
        return $this;
    }

    /**
     * @return string[]
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function counters(): array
    {
        return $this->counters;
    }

    /**
     * @return string
     */
    public function directory(): string
    {
        return $this->directory;
    }

    // -------------------------------------------------------------- fetch ---

    /**
     * Pull every stream to disk. Touches no Exment table.
     *
     * @param SourceInterface $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function fetch(SourceInterface $source, array $options = []): array
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new \Exception('could not create ' . $this->directory);
        }

        $streams = $this->streamsToFetch($source, $options);
        $counts = [];

        foreach ($streams as $stream) {
            $written = $this->stage($source, $stream, $options);
            $counts[$stream] = $written;
            $this->tick($stream, $written, 'fetch');
        }

        foreach ($source->notes() as $note) {
            $this->note($note);
        }

        $manifest = [
            'source' => $source->name(),
            'preset' => array_get($this->preset, 'name'),
            'fetched_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'counts' => $counts,
            'notes' => $this->notes,
        ];

        file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . static::MANIFEST,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $manifest;
    }

    /**
     * One stream to one jsonl file.
     *
     * Written a line at a time straight from the generator, so the memory cost
     * of a stream is one record whether it holds ten or ten million.
     *
     * @param SourceInterface $source
     * @param string $stream
     * @param array<string, mixed> $options
     * @return int
     */
    protected function stage(SourceInterface $source, string $stream, array $options): int
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $stream . '.jsonl';
        $temp = $path . '.part';

        $handle = fopen($temp, 'w');
        if ($handle === false) {
            throw new \Exception('could not write ' . $temp);
        }

        $written = 0;

        try {
            foreach ($source->fetch($stream, $this->streamOptions($stream, $options)) as $row) {
                fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                $written++;

                if ($written % static::TICK == 0) {
                    $this->tick($stream, $written, 'fetch');
                }
            }
        } finally {
            fclose($handle);
        }

        // only swap the finished file into place, so a run that dies halfway
        // leaves the previous good dump intact rather than a truncated one
        // that would import as "most of the tickets"
        if (file_exists($path)) {
            unlink($path);
        }
        rename($temp, $path);

        return $written;
    }

    // -------------------------------------------------------------- apply ---

    /**
     * Read the staged files and write them into Exment.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function apply(array $options = []): array
    {
        $file = new FileSource(['directory' => $this->directory]);

        $check = $file->check();
        if (!array_get($check, 'ok')) {
            return ['ok' => false, 'problems' => [array_get($check, 'message')]];
        }

        // 1. narrow the preset to what is actually on disk. A ServiceNow run
        // configured for incidents only should not leave empty change request
        // and problem tables behind for somebody to wonder about later.
        $this->preset = $this->presetFor($file);

        // 2. what choices does the data actually contain
        $choices = $this->collectChoices($file);

        // 3. make the tables match
        $blueprint = new Blueprint($this->preset, $choices);
        $problems = $blueprint->sanity();
        if (!empty($problems)) {
            return ['ok' => false, 'problems' => $problems];
        }

        $schema = ['created' => [], 'existing' => []];
        if (!$this->dry) {
            $schema = $blueprint->apply(boolval(array_get($options, 'update_schema', true)));
            if (!array_get($schema, 'ok')) {
                return ['ok' => false, 'problems' => array_get($schema, 'problems', [])];
            }
        }

        foreach ($blueprint->notes() as $note) {
            $this->note($note);
        }

        // 4. the records, masters first because a ticket points at them
        foreach ($blueprint->streams() as $name => $stream) {
            $this->importStream($file, $name, $stream);
        }

        // 5. anything that pointed forwards
        $patched = $this->resolvePending();

        foreach ($file->notes() as $note) {
            $this->note($note);
        }

        return [
            'ok' => true,
            'dry' => $this->dry,
            'schema' => $schema,
            'counters' => $this->counters,
            'patched' => $patched,
            'notes' => $this->notes,
        ];
    }

    /**
     * @param FileSource $file
     * @param string $name
     * @param array<string, mixed> $stream
     * @return void
     */
    protected function importStream(FileSource $file, string $name, array $stream)
    {
        $tableName = strval(array_get($stream, 'table'));
        $counter = ['read' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        $table = $this->dry ? null : CustomTable::getEloquent($tableName);
        if (!$this->dry && !isset($table)) {
            $this->note(sprintf('table %s does not exist, stream "%s" skipped', $tableName, $name));
            $this->counters[$name] = $counter;
            return;
        }

        $keyMap = $this->dry ? [] : $this->keyMap($table);

        $seenFields = [];

        foreach ($file->fetch($name) as $row) {
            $counter['read']++;

            // cheap union of every key the source sent, so the run can say
            // what it left behind rather than leaving somebody to find out
            $seenFields += array_flip(array_keys($row));

            $key = $this->externalKey($name, $stream, $row);
            if ($key === null) {
                $counter['skipped']++;
                $this->note(sprintf('a "%s" record has no %s, skipped', $name, array_get($stream, 'key')));
                continue;
            }

            try {
                $values = $this->mapRow($name, $stream, $row);
            } catch (\Throwable $e) {
                $counter['failed']++;
                $this->note(sprintf('%s %s could not be mapped: %s', $name, $key, $e->getMessage()));
                continue;
            }

            $values[Blueprint::KEY_COLUMN] = $key;

            if (array_get($stream, 'keep_raw', array_get($this->preset, 'keep_raw', true))) {
                $values[Blueprint::RAW_COLUMN] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }

            $existingId = array_get($keyMap, $key);

            if ($this->dry) {
                $counter[$existingId ? 'updated' : 'created']++;
            } else {
                try {
                    $id = $this->write($table, $existingId, $values);
                    $keyMap[$key] = $id;
                    $counter[$existingId ? 'updated' : 'created']++;
                    $this->rememberPending($name, $stream, $row, $tableName, $id);
                } catch (\Throwable $e) {
                    $counter['failed']++;
                    $this->note(sprintf('%s %s could not be saved: %s', $name, $key, $e->getMessage()));
                }
            }

            if ($counter['read'] % static::TICK == 0) {
                $this->tick($name, $counter['read'], 'apply');
            }
        }

        if (!$this->dry) {
            $this->keyMaps[$tableName] = $keyMap;
        }

        $this->reportUnmapped($name, $stream, array_keys($seenFields));

        $this->counters[$name] = $counter;
        $this->tick($name, $counter['read'], 'apply');
    }

    /**
     * Say which source fields no column claimed.
     *
     * The question every migration has to answer is not "did it run" but "what
     * did I lose", and the honest answer is knowable: it is the difference
     * between the keys the source sent and the ones the preset reads. Printing
     * it turns a silent omission into a decision somebody makes on purpose.
     *
     * @param string $name
     * @param array<string, mixed> $stream
     * @param string[] $seen
     * @return void
     */
    protected function reportUnmapped(string $name, array $stream, array $seen)
    {
        $mapped = [strval(array_get($stream, 'key', 'id'))];

        foreach ((array)array_get($stream, 'columns', []) as $column => $definition) {
            $mapped[] = strval(array_get((array)$definition, 'from', $column));
        }

        // compare on the first segment: "priority.display_value" claims
        // "priority"
        $mapped = array_map(function ($path) {
            return explode('.', $path)[0];
        }, $mapped);

        $unmapped = array_values(array_diff($seen, $mapped, ['__table']));

        if (empty($unmapped)) {
            return;
        }

        sort($unmapped);
        $shown = array_slice($unmapped, 0, 25);

        $this->note(sprintf(
            'stream "%s": %d source field(s) not mapped: %s%s',
            $name,
            count($unmapped),
            implode(', ', $shown),
            count($unmapped) > count($shown) ? ', ...' : ''
        ));
    }

    /**
     * The preset with every stream that has no staged file switched off.
     *
     * @param FileSource $file
     * @return array<string, mixed>
     */
    protected function presetFor(FileSource $file): array
    {
        $available = $file->streams();
        $preset = $this->preset;

        foreach ((array)array_get($preset, 'streams', []) as $name => $stream) {
            if (array_get($stream, 'skip')) {
                continue;
            }
            if (!in_array($name, $available)) {
                $preset['streams'][$name]['skip'] = true;
                $this->note(sprintf('no "%s" file in the dump, that table is not created', $name));
            }
        }

        return $preset;
    }

    /**
     * Save one record, notifications off.
     *
     * A migration that emails every watcher about fifty thousand "new" tickets
     * is a migration that gets rolled back within the hour, so this uses the
     * same switch Exment's own csv import uses.
     *
     * @param CustomTable $table
     * @param int|null $existingId
     * @param array<string, mixed> $values
     * @return int
     */
    protected function write(CustomTable $table, $existingId, array $values): int
    {
        $record = $existingId ? $table->getValueModel($existingId) : $table->getValueModel();

        if (!isset($record)) {
            $record = $table->getValueModel();
        }

        $record->saved_notify(false);
        $record->setValue($values);
        $record->save();

        return intval($record->id);
    }

    // ------------------------------------------------------------ mapping ---

    /**
     * @param string $stream
     * @param array<string, mixed> $stream_config
     * @param array<string, mixed> $row
     * @return string|null
     */
    protected function externalKey(string $stream, array $stream_config, array $row)
    {
        $path = strval(array_get($stream_config, 'key', 'id'));
        $value = $this->pick($row, $path);

        if (is_nullorempty($value) || is_array($value)) {
            return null;
        }

        return $this->buildKey($stream, strval($value));
    }

    /**
     * @param string $stream
     * @param string $value
     * @return string
     */
    protected function buildKey(string $stream, string $value): string
    {
        return sprintf('%s:%s:%s', array_get($this->preset, 'name', 'migration'), $stream, $value);
    }

    /**
     * @param SourceInterface $source
     * @param array<string, mixed> $options
     * @return string[]
     */
    protected function streamsToFetch(SourceInterface $source, array $options): array
    {
        $wanted = array_filter((array)array_get($options, 'streams', []));
        $available = $source->streams();

        if (empty($wanted)) {
            return $available;
        }

        $missing = array_diff($wanted, $available);
        foreach ($missing as $stream) {
            $this->note(sprintf('"%s" is not a stream %s has', $stream, $source->name()));
        }

        return array_values(array_intersect($available, $wanted));
    }

    /**
     * @param string $stream
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function streamOptions(string $stream, array $options): array
    {
        $perStream = (array)array_get($options, 'stream_options.' . $stream, []);

        return $perStream + $options;
    }

    /**
     * @param string $directory
     * @return string
     */
    protected function resolveDirectory(string $directory): string
    {
        if (preg_match('/^([a-zA-Z]:[\\\\\/]|[\\\\\/])/', $directory)) {
            return rtrim($directory, '/\\');
        }

        return storage_path(path_join('app', 'migration', $directory));
    }

    /**
     * @param string $message
     * @return void
     */
    protected function note(string $message)
    {
        if (!in_array($message, $this->notes)) {
            $this->notes[] = $message;
        }
    }

    /**
     * @param string $stream
     * @param int $done
     * @param string $phase
     * @return void
     */
    protected function tick(string $stream, int $done, string $phase)
    {
        if (is_callable($this->progress)) {
            call_user_func($this->progress, $stream, $done, $phase);
        }
    }
}
