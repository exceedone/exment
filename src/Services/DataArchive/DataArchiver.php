<?php

namespace Exceedone\Exment\Services\DataArchive;

use Carbon\Carbon;
use Exceedone\Exment\Model\DataArchive;
use Exceedone\Exment\Model\DataArchiveEvent;
use Exceedone\Exment\Model\System;

/**
 * The data archive (ISS02970, part 1): old rows of the tables of Exment that grow go into a zip
 * (a manifest and parts of 10,000 rows as gzip JSON lines), and are deleted from the database only
 * after the file in its place was read again and holds them all (the count and the hash of the ids).
 *
 * - An archive is made from a preview: when the number of rows changed since (more than 1%), it stops.
 * - A restore puts back the rows that are not in the table (the others are counted, never written
 *   over). It refuses when a column of the archive is no longer in the table.
 * - Deleting an archive puts it in its trash; it is purged after the days of its source (90 by
 *   default). The system administrators are told before. A legal hold keeps it.
 * - Every step is written to data_archive_events.
 */
class DataArchiver
{
    public const FEATURE = 'data_archive';

    public const PART_ROWS = 10000;

    public const DELETE_CHUNK = 1000;

    public const RESTORE_CHUNK = 500;

    /** The rows changed since the preview by more than this: the archive stops. */
    public const COUNT_TOLERANCE = 0.01;

    public const DEFAULT_TRASH_DAYS = 90;

    /** The system administrators are told this many days before a purge. */
    public const NOTICE_DAYS = 7;

    public const FORMAT = 1;

    public const LOCK = 'exment_data_archive_run';

    /** @var Carbon|null "now" in the tests */
    public static $now;

    /** @var \Closure|null in the tests: called with the archive once its file is in its place */
    public static $afterPut;

    public static function now(): Carbon
    {
        return isset(static::$now) ? static::$now->copy() : Carbon::now();
    }

    /** The most rows one archive takes (the rest is for the next run). */
    public static function maxRows(): int
    {
        return max(1, intval(config('exment.data_archive.max_rows', 1000000)));
    }

    // ============================================================ the policies

    /**
     * The policy of a source: older_days (0: not archived by the schedule), trash_days.
     *
     * @return array{older_days: int, trash_days: int}
     */
    public static function policy(string $source): array
    {
        $all = (array)json_decode(strval(json_encode(System::data_archive_policies())), true);
        $row = (array)($all[$source] ?? []);
        return [
            'older_days' => max(0, intval($row['older_days'] ?? 0)),
            'trash_days' => max(1, intval($row['trash_days'] ?? static::DEFAULT_TRASH_DAYS)),
        ];
    }

    /**
     * @param array<string, array{older_days?: int, trash_days?: int}> $policies
     */
    public static function savePolicies(array $policies): void
    {
        $out = [];
        foreach (Sources::all() as $key => $source) {
            $row = (array)($policies[$key] ?? []);
            $out[$key] = [
                'older_days' => max(0, min(36500, intval($row['older_days'] ?? 0))),
                'trash_days' => max(1, min(36500, intval($row['trash_days'] ?? static::DEFAULT_TRASH_DAYS))),
            ];
        }
        System::data_archive_policies($out);
    }

    // ============================================================ preview

    /**
     * What an archive of a source would take: the rows, by the month, the size (about).
     *
     * @return array{source: string, before: string, count: int, from: string|null, to: string|null, months: array<string, int>, bytes: int, compressed: int}
     */
    public static function preview(string $source, Carbon $before, ?int $userId = null, bool $record = true): array
    {
        $query = Sources::query($source, $before);
        if (!isset($query)) {
            throw new ArchiveException('source');
        }
        $date = strval(Sources::get($source)['date']);
        $count = (clone $query)->count();
        $from = $count > 0 ? (clone $query)->min($date) : null;
        $to = $count > 0 ? (clone $query)->max($date) : null;
        $months = [];
        if (isset($from)) {
            $month = Carbon::parse($from)->startOfMonth();
            $end = Carbon::parse($to)->startOfMonth();
            for ($i = 0; $i < 240 && $month->lte($end); $i++, $month->addMonth()) {
                $next = $month->copy()->addMonth();
                $n = (clone $query)->where($date, '>=', $month)->where($date, '<', $next)->count();
                if ($n > 0) {
                    $months[$month->format('Y-m')] = $n;
                }
            }
        }
        // the size: a sample of the rows as they are written
        $bytes = 0;
        if ($count > 0) {
            $sample = (clone $query)->select(Sources::columns($source))->limit(200)->get();
            $length = 0;
            foreach ($sample as $row) {
                $length += strlen(strval(json_encode((array)$row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))) + 1;
            }
            $bytes = intval($length / max(1, count($sample)) * $count);
        }
        if ($record) {
            static::event(null, $source, 'preview', $count, 'before ' . $before->format('Y-m-d'), $userId);
        }
        return [
            'source' => $source, 'before' => $before->format('Y-m-d H:i:s'), 'count' => $count,
            'from' => isset($from) ? strval($from) : null, 'to' => isset($to) ? strval($to) : null,
            'months' => $months, 'bytes' => $bytes, 'compressed' => intval($bytes * 0.2),
        ];
    }

    // ============================================================ archive

    /**
     * Archive the rows of a source older than $before. $expected: the count of the preview.
     */
    public static function archive(string $source, Carbon $before, ?int $expected = null, ?int $userId = null): DataArchive
    {
        $lock = \Cache::lock(static::LOCK, 3600);
        if (!$lock->get()) {
            throw new ArchiveException('busy');
        }
        $work = null;
        $archive = null;
        try {
            $query = Sources::query($source, $before);
            if (!isset($query)) {
                throw new ArchiveException('source');
            }
            $count = (clone $query)->count();
            if ($count === 0) {
                throw new ArchiveException('nothing');
            }
            if (isset($expected) && abs($count - $expected) > max(1, intval($expected * static::COUNT_TOLERANCE))) {
                throw new ArchiveException('changed', ['before' => $expected, 'now' => $count]);
            }
            $driver = ArchiveStore::driver();
            $uuid = (string)\Illuminate\Support\Str::uuid();
            $archive = DataArchive::create([
                'uuid' => $uuid, 'kind' => DataArchive::KIND_SYSTEM, 'source' => $source,
                'date_column' => strval(Sources::get($source)['date']), 'before' => $before,
                'disk' => $driver, 'path' => ArchiveStore::pathOf($source, $uuid), 'status' => DataArchive::STATUS_WRITING,
                'exment_version' => static::version(), 'created_user_id' => $userId,
            ]);
            $work = ArchiveStore::workDir();
            $written = static::write($archive, $query, $work);
            ArchiveStore::put($driver, $written['zip'], strval($archive->path));
            if (isset(static::$afterPut)) {
                (static::$afterPut)($archive);
            }
            $archive->update([
                'row_count' => $written['count'], 'ids_hash' => $written['ids_hash'],
                'range_from' => $written['from'], 'range_to' => $written['to'],
                'bytes' => filesize($written['zip']), 'sha256' => hash_file('sha256', $written['zip']),
            ]);
            static::event($archive, $source, 'archive', $written['count'], $archive->path, $userId);

            // read it again from its place: only then the rows go
            static::verify($archive, $userId);
            static::deleteRows($archive, $written['ids'], $userId);
            return $archive->refresh();
        } catch (\Throwable $e) {
            if (isset($archive) && $archive->status !== DataArchive::STATUS_COMPLETED) {
                // nothing was deleted from the database: the file is not needed
                if (in_array($archive->status, [DataArchive::STATUS_WRITING, DataArchive::STATUS_VERIFIED], true) && intval($archive->deleted_count) === 0) {
                    try {
                        ArchiveStore::delete(strval($archive->disk), strval($archive->path));
                    } catch (\Throwable $ignore) {
                    }
                }
                $archive->update(['status' => DataArchive::STATUS_FAILED, 'message' => mb_substr($e->getMessage(), 0, 1000)]);
                static::event($archive, $source, 'failed', null, $e->getMessage(), $userId);
            }
            throw $e;
        } finally {
            if (isset($work)) {
                ArchiveStore::removeDir($work);
            }
            $lock->release();
        }
    }

    /**
     * Write the zip: the parts of the rows, the ids (a file, for the delete) and the manifest.
     *
     * @return array{zip: string, ids: string, count: int, ids_hash: string, from: string|null, to: string|null}
     */
    protected static function write(DataArchive $archive, \Illuminate\Database\Query\Builder $query, string $work): array
    {
        $source = $archive->source;
        $columns = Sources::columns($source);
        $date = strval($archive->date_column);
        $zipPath = $work . '/archive.zip';
        $idsPath = $work . '/ids.txt';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new ArchiveException('write');
        }
        $ids = fopen($idsPath, 'wb');
        if ($ids === false) {
            throw new ArchiveException('write');
        }
        $hash = hash_init('sha256');
        $buffer = '';
        $inPart = 0;
        $parts = [];
        $count = 0;
        $from = null;
        $to = null;
        $max = static::maxRows();
        $flush = function () use (&$buffer, &$inPart, &$parts, $zip, $work) {
            if (static::none($inPart)) {
                return;
            }
            $name = sprintf('parts/%05d.ndjson.gz', count($parts) + 1);
            $file = $work . '/' . basename($name);
            file_put_contents($file, strval(gzencode($buffer, 6)));
            $zip->addFile($file, $name);
            $parts[] = ['name' => $name, 'rows' => $inPart, 'sha256' => hash_file('sha256', $file)];
            $buffer = '';
            $inPart = 0;
        };
        (clone $query)->select($columns)->chunkById(1000, function ($rows) use (&$buffer, &$inPart, &$count, &$from, &$to, $hash, $ids, $flush, $date, $max) {
            foreach ($rows as $row) {
                $data = (array)$row;
                $buffer .= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                hash_update($hash, $data['id'] . ',');
                fwrite($ids, $data['id'] . "\n");
                $at = isset($data[$date]) ? strval($data[$date]) : null;
                if (isset($at)) {
                    $from = is_null($from) || $at < $from ? $at : $from;
                    $to = is_null($to) || $at > $to ? $at : $to;
                }
                $count++;
                $inPart++;
                if ($inPart >= static::PART_ROWS) {
                    $flush();
                }
                if ($count >= $max) {
                    return false;
                }
            }
        }, 'id');
        $flush();
        fclose($ids);
        $idsHash = hash_final($hash);
        $zip->addFromString('manifest.json', strval(json_encode([
            'format' => static::FORMAT, 'uuid' => $archive->uuid, 'kind' => $archive->kind, 'source' => $source,
            'table' => Sources::table($source), 'columns' => $columns, 'date_column' => $date,
            'before' => optional($archive->before)->format('Y-m-d H:i:s'), 'count' => $count, 'ids_hash' => $idsHash,
            'from' => $from, 'to' => $to, 'exment_version' => $archive->exment_version,
            'created_at' => static::now()->format('Y-m-d H:i:s'), 'parts' => $parts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));
        if (!$zip->close()) {
            throw new ArchiveException('write');
        }
        return ['zip' => $zipPath, 'ids' => $idsPath, 'count' => $count, 'ids_hash' => $idsHash, 'from' => $from, 'to' => $to];
    }

    /**
     * Read an archive from its place (from S3 too): its manifest and, row by row, its parts.
     * Each part is checked against the sha256 of the manifest.
     *
     * @param \Closure|null $each function (array $row): void
     * @return array<string, mixed> the manifest
     */
    public static function read(DataArchive $archive, ?\Closure $each = null): array
    {
        $work = ArchiveStore::workDir();
        try {
            $local = $work . '/archive.zip';
            if (!ArchiveStore::fetch(strval($archive->disk), strval($archive->path), $local)) {
                throw new ArchiveException('missing');
            }
            if (isset($archive->sha256) && !hash_equals(strval($archive->sha256), strval(hash_file('sha256', $local)))) {
                throw new ArchiveException('broken');
            }
            $zip = new \ZipArchive();
            if ($zip->open($local) !== true) {
                throw new ArchiveException('broken');
            }
            $manifest = json_decode(strval($zip->getFromName('manifest.json')), true);
            if (!is_array($manifest) || !isset($manifest['parts']) || !is_array($manifest['parts'])) {
                $zip->close();
                throw new ArchiveException('broken');
            }
            if (isset($each)) {
                foreach ($manifest['parts'] as $part) {
                    $gz = $zip->getFromName(strval($part['name']));
                    if (!is_string($gz) || !hash_equals(strval($part['sha256']), hash('sha256', $gz))) {
                        $zip->close();
                        throw new ArchiveException('broken');
                    }
                    $lines = gzdecode($gz);
                    if ($lines === false) {
                        $zip->close();
                        throw new ArchiveException('broken');
                    }
                    foreach (explode("\n", $lines) as $line) {
                        if ($line === '') {
                            continue;
                        }
                        $row = json_decode($line, true);
                        if (!is_array($row)) {
                            $zip->close();
                            throw new ArchiveException('broken');
                        }
                        $each($row);
                    }
                }
            }
            $zip->close();
            return $manifest;
        } finally {
            ArchiveStore::removeDir($work);
        }
    }

    /**
     * The file in its place holds the rows: the count and the hash of the ids, as written.
     */
    public static function verify(DataArchive $archive, ?int $userId = null): void
    {
        $count = 0;
        $hash = hash_init('sha256');
        $manifest = static::read($archive, function (array $row) use (&$count, $hash) {
            $count++;
            hash_update($hash, $row['id'] . ',');
        });
        $idsHash = hash_final($hash);
        if ($count !== intval($archive->row_count) || intval($manifest['count'] ?? -1) !== $count
            || !hash_equals(strval($archive->ids_hash), $idsHash) || !hash_equals(strval($manifest['ids_hash'] ?? ''), $idsHash)) {
            throw new ArchiveException('verify', ['count' => $count, 'expected' => intval($archive->row_count)]);
        }
        $archive->update(['status' => DataArchive::STATUS_VERIFIED]);
        static::event($archive, $archive->source, 'verify', $count, 'sha256 ' . substr(strval($archive->sha256), 0, 12), $userId);
    }

    /**
     * Delete from the database the rows the archive holds (by their ids, still older than the
     * date of the archive), a part at a time.
     */
    protected static function deleteRows(DataArchive $archive, string $idsFile, ?int $userId = null): void
    {
        if ($archive->status !== DataArchive::STATUS_VERIFIED) {
            throw new ArchiveException('verify');
        }
        $table = Sources::table($archive->source);
        if (!isset($table)) {
            throw new ArchiveException('source');
        }
        $connection = Sources::connection($archive->source);
        $date = strval($archive->date_column);
        $deleted = 0;
        $chunk = [];
        $flush = function () use (&$chunk, &$deleted, $connection, $table, $date, $archive) {
            if (static::none($chunk)) {
                return;
            }
            $ids = $chunk;
            $deleted += intval($connection->transaction(function () use ($connection, $table, $date, $archive, $ids) {
                return $connection->table($table)->whereIn('id', $ids)->where($date, '<', $archive->before)->delete();
            }));
            $chunk = [];
        };
        $handle = fopen($idsFile, 'rb');
        while ($handle !== false && ($line = fgets($handle)) !== false) {
            $id = trim($line);
            if ($id !== '') {
                $chunk[] = $id;
            }
            if (count($chunk) >= static::DELETE_CHUNK) {
                $flush();
            }
        }
        if ($handle !== false) {
            fclose($handle);
        }
        $flush();
        $warning = $deleted !== intval($archive->row_count);
        $archive->update([
            'status' => DataArchive::STATUS_COMPLETED, 'deleted_count' => $deleted, 'warning' => $warning,
            'message' => $warning ? 'deleted ' . $deleted . ' of ' . $archive->row_count . ' (rows changed meanwhile)' : null,
        ]);
        static::event($archive, $archive->source, 'delete_rows', $deleted, null, $userId);
    }

    // ============================================================ restore

    /**
     * What a restore would do: the version that wrote the archive, the columns no longer in the
     * table (then it is refused), the rows to put back and those already there.
     *
     * @return array{version: string|null, written_at: string|null, count: int, existing: int, insert: int, missing_columns: array<int, string>, table: bool}
     */
    public static function restorePreview(DataArchive $archive): array
    {
        $ids = [];
        $manifest = static::read($archive, function (array $row) use (&$ids) {
            $ids[] = $row['id'];
        });
        $table = Sources::table($archive->source);
        $current = Sources::columns($archive->source);
        $missing = array_values(array_diff((array)($manifest['columns'] ?? []), $current));
        $existing = 0;
        if (isset($table)) {
            $connection = Sources::connection($archive->source);
            foreach (array_chunk($ids, static::DELETE_CHUNK) as $chunk) {
                $existing += $connection->table($table)->whereIn('id', $chunk)->count();
            }
        }
        return [
            'version' => isset($manifest['exment_version']) ? strval($manifest['exment_version']) : null,
            'written_at' => isset($manifest['created_at']) ? strval($manifest['created_at']) : null,
            'count' => count($ids), 'existing' => $existing, 'insert' => count($ids) - $existing,
            'missing_columns' => $missing, 'table' => isset($table),
        ];
    }

    /**
     * Put the rows of an archive back (those not in the table). The file stays.
     */
    public static function restore(DataArchive $archive, ?int $userId = null): int
    {
        $preview = static::restorePreview($archive);
        if (!$preview['table']) {
            throw new ArchiveException('source');
        }
        if (count($preview['missing_columns']) > 0) {
            throw new ArchiveException('columns', ['columns' => implode(', ', $preview['missing_columns'])]);
        }
        $table = strval(Sources::table($archive->source));
        $connection = Sources::connection($archive->source);
        $inserted = 0;
        $chunk = [];
        $flush = function () use (&$chunk, &$inserted, $connection, $table) {
            if (static::none($chunk)) {
                return;
            }
            $rows = $chunk;
            $chunk = [];
            $there = $connection->table($table)->whereIn('id', array_column($rows, 'id'))->pluck('id')->map(function ($id) {
                return strval($id);
            })->all();
            $there = array_flip($there);
            $rows = array_values(array_filter($rows, function ($row) use ($there) {
                return !isset($there[strval($row['id'])]);
            }));
            if (count($rows) > 0) {
                $connection->transaction(function () use ($connection, $table, $rows) {
                    $connection->table($table)->insert($rows);
                });
                $inserted += count($rows);
            }
        };
        // SQL Server: the ids are put as they were
        $identity = $connection->getDriverName() === 'sqlsrv';
        if ($identity) {
            $connection->statement('SET IDENTITY_INSERT ' . $connection->getQueryGrammar()->wrapTable($table) . ' ON');
        }
        try {
            static::read($archive, function (array $row) use (&$chunk, $flush) {
                $chunk[] = $row;
                if (count($chunk) >= static::RESTORE_CHUNK) {
                    $flush();
                }
            });
            $flush();
        } finally {
            if ($identity) {
                $connection->statement('SET IDENTITY_INSERT ' . $connection->getQueryGrammar()->wrapTable($table) . ' OFF');
            }
        }
        $archive->update(['status' => DataArchive::STATUS_RESTORED, 'restored_count' => intval($archive->restored_count) + $inserted]);
        static::event($archive, $archive->source, 'restore', $inserted, 'already there: ' . $preview['existing'], $userId);
        return $inserted;
    }

    // ============================================================ the trash, the hold, the purge

    public static function trash(DataArchive $archive, ?int $userId = null): void
    {
        if ($archive->legal_hold) {
            throw new ArchiveException('held');
        }
        $days = static::policy($archive->source)['trash_days'];
        $archive->update(['deleted_at' => static::now(), 'purge_at' => static::now()->addDays($days), 'purge_notified_at' => null]);
        static::event($archive, $archive->source, 'trash', null, 'purge after ' . $days . ' days', $userId);
    }

    public static function untrash(DataArchive $archive, ?int $userId = null): void
    {
        $archive->update(['deleted_at' => null, 'purge_at' => null, 'purge_notified_at' => null]);
        static::event($archive, $archive->source, 'untrash', null, null, $userId);
    }

    public static function hold(DataArchive $archive, bool $hold, ?int $userId = null): void
    {
        $archive->update(['legal_hold' => $hold]);
        static::event($archive, $archive->source, $hold ? 'hold' : 'unhold', null, null, $userId);
    }

    /**
     * Tell the system administrators of the purges to come (once), and purge those whose day has
     * come (not held).
     *
     * @return array{notified: int, purged: int}
     */
    public static function purge(): array
    {
        $now = static::now();
        $notified = 0;
        $soon = DataArchive::query()->whereNotNull('deleted_at')->where('legal_hold', false)->whereNull('purge_notified_at')
            ->where('purge_at', '<=', $now->copy()->addDays(static::NOTICE_DAYS))->get();
        if ($soon->count() > 0) {
            static::notifyAdmins($soon->count(), optional($soon->min('purge_at'))->format('Y-m-d'));
            foreach ($soon as $archive) {
                $archive->update(['purge_notified_at' => $now]);
                static::event($archive, $archive->source, 'purge_notice', $archive->row_count, 'purge at ' . optional($archive->purge_at)->format('Y-m-d'), null);
                $notified++;
            }
        }
        $purged = 0;
        $due = DataArchive::query()->whereNotNull('deleted_at')->where('legal_hold', false)->whereNotNull('purge_notified_at')
            ->where('purge_at', '<=', $now)->get();
        foreach ($due as $archive) {
            try {
                ArchiveStore::delete(strval($archive->disk), strval($archive->path));
            } catch (\Throwable $e) {
                static::event($archive, $archive->source, 'failed', null, 'purge: ' . $e->getMessage(), null);
                continue;
            }
            static::event($archive, $archive->source, 'purge', $archive->row_count, $archive->path, null);
            $archive->delete();
            $purged++;
        }
        return ['notified' => $notified, 'purged' => $purged];
    }

    protected static function notifyAdmins(int $count, ?string $date): void
    {
        $subject = strval(exmtrans('data_archive.notice_subject', ['count' => $count]));
        $body = strval(exmtrans('data_archive.notice_body', ['count' => $count, 'date' => $date ?? '', 'url' => admin_urls('data_archive') . '?tab=trash']));
        foreach (\Exceedone\Exment\Services\PluginDistribution\AdminNotices::administrators() as $user) {
            try {
                $navbar = new \Exceedone\Exment\Model\NotifyNavbar();
                $navbar->fill(['notify_id' => 0, 'target_user_id' => $user->id, 'notify_subject' => mb_substr($subject, 0, 200), 'notify_body' => mb_substr($body, 0, 2000), 'trigger_user_id' => null]);
                $navbar->save();
            } catch (\Throwable $e) {
                \Log::warning('[Exment] data archive: the notice to the administrators: ' . $e->getMessage());
            }
        }
    }

    // ============================================================ the schedule

    /**
     * Every night: the sources with a policy are archived (older than their days), then the purge.
     *
     * @return array<string, mixed>
     */
    public static function runPolicies(): array
    {
        $done = [];
        foreach (array_keys(Sources::all()) as $source) {
            $days = static::policy($source)['older_days'];
            if ($days <= 0) {
                continue;
            }
            $before = static::now()->startOfDay()->subDays($days);
            $query = Sources::query($source, $before);
            if (!isset($query)) {
                continue;
            }
            try {
                $count = $query->count();
                if ($count > 0) {
                    $done[$source] = static::archive($source, $before, null, null)->row_count;
                }
            } catch (\Throwable $e) {
                $done[$source] = 'failed: ' . $e->getMessage();
                \Log::warning('[Exment] data archive: ' . $source . ': ' . $e->getMessage());
            }
        }
        $done['purge'] = static::purge();
        return $done;
    }

    // ============================================================ helpers

    public static function event(?DataArchive $archive, ?string $source, string $action, ?int $rows, ?string $detail, ?int $userId): void
    {
        try {
            DataArchiveEvent::query()->insert([
                'data_archive_id' => optional($archive)->id, 'source' => $source, 'action' => $action, 'rows' => $rows,
                'detail' => isset($detail) ? mb_substr($detail, 0, 500) : null, 'user_id' => $userId, 'created_at' => static::now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('[Exment] data archive: the record of ' . $action . ': ' . $e->getMessage());
        }
    }

    /**
     * Nothing to write yet (a count of rows, or the rows), for the closures that fill them.
     */
    protected static function none(mixed $value): bool
    {
        return is_array($value) ? count($value) === 0 : intval($value) === 0;
    }

    protected static function version(): ?string
    {
        try {
            $version = \Exment::version(false);
            return is_string($version) ? mb_substr($version, 0, 32) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
