<?php

namespace Exceedone\Exment\Services\Sync;

use Carbon\Carbon;
use Exceedone\Exment\Jobs\SyncRunJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\SyncLink;
use Exceedone\Exment\Model\SyncMapping;
use Exceedone\Exment\Model\SyncRun;
use Exceedone\Exment\Model\SyncRunError;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Services\Features;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The data sync (ISS00113): a run of a mapping takes the items changed in the service since the
 * last run (the cursor of the mapping), a part at a time (a job each part, SyncRunJob), and
 * writes each one to its record (the links: never twice), as the rules of the mapping say. A dry
 * run goes the same way and writes nothing: it counts, and lists what cannot be written.
 *
 * The import only (7.3). The writes have no notification, and keep the times of the service when
 * the mapping says so. One run of a mapping at a time (a lock that ends). A failed run, or one
 * with errors, is a bell to the system administrators; the failures in a row widen the interval.
 */
class SyncEngine
{
    public const FEATURE = 'data_sync';

    /** The items of a part of a run. */
    public const LIMIT = 200;

    /** The items a dry run reads at most. */
    public const DRY_LIMIT = 1000;

    /** The seconds the lock of a mapping is held at most (a part that dies lets it go). */
    public const LOCK_SECONDS = 900;

    /** The errors of a run kept at most. */
    public const MAX_ERRORS = 500;

    public static function enabled(): bool
    {
        return Features::enabled(static::FEATURE);
    }

    /**
     * Start a run (or a dry run) of a mapping: the first part now or by the queue.
     */
    public static function start(SyncMapping $mapping, bool $dry, string $trigger, ?int $userId = null): SyncRun
    {
        $run = new SyncRun();
        $run->sync_mapping_id = $mapping->id;
        $run->dry_run = $dry;
        $run->trigger = $trigger;
        $run->status = SyncRun::STATUS_RUNNING;
        $run->counts = array_fill_keys(SyncRun::COUNTS, 0);
        $run->cursor = $mapping->cursor;
        $run->started_at = Carbon::now();
        $run->created_user_id = $userId;
        $run->save();

        dispatch(new SyncRunJob(intval($run->id)));
        return $run;
    }

    /**
     * One part of a run. True: the run is over.
     */
    public static function step(SyncRun $run): bool
    {
        /** @var SyncMapping|null $mapping */
        $mapping = SyncMapping::query()->find($run->sync_mapping_id);
        if (!isset($mapping)) {
            static::finish($run, null, SyncRun::STATUS_FAILED, 'The mapping is gone.');
            return true;
        }
        $lock = Cache::lock('exment_sync_mapping_' . $mapping->id, static::LOCK_SECONDS);
        if (!$lock->get()) {
            static::finish($run, $mapping, SyncRun::STATUS_FAILED, strval(exmtrans('sync.message.already_running')));
            return true;
        }
        try {
            return static::part($run, $mapping);
        } catch (\Throwable $e) {
            static::finish($run, $mapping, SyncRun::STATUS_FAILED, static::safeMessage($e));
            return true;
        } finally {
            $lock->release();
        }
    }

    protected static function part(SyncRun $run, SyncMapping $mapping): bool
    {
        $connection = $mapping->connection;
        $connector = isset($connection) ? $connection->connectorInstance() : null;
        $table = $mapping->customTable();
        if (!isset($connection, $connector, $table)) {
            static::finish($run, $mapping, SyncRun::STATUS_FAILED, strval(exmtrans('sync.message.not_ready')));
            return true;
        }
        if (!boolval(array_get($connector->capabilities($mapping->remote_object), 'import', false))) {
            static::finish($run, $mapping, SyncRun::STATUS_FAILED, strval(exmtrans('sync.message.cannot_import')));
            return true;
        }

        $limit = max(1, min(1000, intval($mapping->option('limit', static::LIMIT))));
        $page = $connector->changes($connection, $mapping->remote_object, $run->cursor, $limit);
        $mapper = new FieldMapper();
        foreach ($page->items as $item) {
            static::item($run, $mapping, $table, $item, $mapper);
        }
        $run->cursor = $page->cursor;

        $read = array_sum(array_map('intval', (array)$run->counts));
        if ($page->done || ($run->dry_run && $read >= static::DRY_LIMIT) || count($page->items) === 0) {
            if (!$run->dry_run && boolval($mapping->option('check_deletes', true)) && static::deletesDue($mapping)) {
                static::deletes($run, $mapping, $table);
            }
            static::finish($run, $mapping, $run->count('errors') > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCEEDED, null);
            return true;
        }
        $run->save();
        if (!$run->dry_run) {
            // the next run goes on from here, even when this one stops
            $mapping->cursor = $run->cursor;
            $mapping->save();
        }
        return false;
    }

    /**
     * One item to its record.
     */
    protected static function item(SyncRun $run, SyncMapping $mapping, CustomTable $table, RemoteItem $item, FieldMapper $mapper): void
    {
        /** @var SyncLink|null $link */
        $link = SyncLink::query()->where('sync_mapping_id', $mapping->id)->where('remote_id', $item->id)->first();
        $hash = $item->hash();
        /** @var CustomValue|null $record */
        $record = isset($link, $link->custom_value_id) ? $table->getValueModel()->query()->withTrashed()->find($link->custom_value_id) : null;

        if (isset($record, $link) && $link->hash === $hash) {
            $run->add('unchanged');
            return;
        }
        // changed in Exment since the sync wrote it: the rule of the mapping
        if (isset($record, $link, $link->local_updated_at) && $record->updated_at instanceof Carbon && $record->updated_at->gt($link->local_updated_at)) {
            $rule = $mapping->conflict_rule;
            if ($rule === SyncMapping::CONFLICT_LOCAL || ($rule === SyncMapping::CONFLICT_NEWER && isset($item->updatedAt) && $record->updated_at->gte($item->updatedAt))) {
                $run->add('skipped');
                return;
            }
        }

        [$values, $notes] = $mapper->map($mapping, $table, $item);
        $model = $record ?? $table->getValueModel();
        $validator = $table->validateValue(['value' => $values], $model, [
            'systemColumn' => false,
            'checkCustomValueExists' => !isset($record),
            'validateLock' => false,
        ]);
        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->getMessages() as $key => $list) {
                $messages[] = $key . ': ' . implode(' ', (array)$list);
            }
            static::error($run, $item->id, implode(' / ', array_merge($messages, $notes)));
            return;
        }
        foreach ($notes as $note) {
            // written, with a value left out
            static::error($run, $item->id, $note, false);
        }

        if ($run->dry_run) {
            $run->add(isset($record) ? 'updated' : 'created');
            return;
        }

        try {
            static::write($mapping, $model, $values, $item);
            if ($item->hasAttachments) {
                static::attachments($mapping, $model, $item);
            }
        } catch (\Throwable $e) {
            static::error($run, $item->id, static::safeMessage($e));
            return;
        }

        $link = $link ?? new SyncLink(['sync_mapping_id' => $mapping->id, 'remote_id' => $item->id]);
        $link->custom_value_id = intval($model->id);
        $link->remote_url = $item->url;
        $link->remote_updated_at = $item->updatedAt;
        $fresh = $table->getValueModel()->query()->withTrashed()->find($model->id);
        $link->local_updated_at = $fresh instanceof CustomValue ? $fresh->updated_at : Carbon::now();
        $link->hash = $hash;
        $link->remote_deleted_at = null;
        $link->save();
        $run->add(isset($record) ? 'updated' : 'created');
    }

    /**
     * @param array<string, mixed> $values
     */
    protected static function write(SyncMapping $mapping, CustomValue $model, array $values, RemoteItem $item): void
    {
        $model->setValue($values);
        // the sync is not a change a person made: no notification
        $model->saved_notify(false);
        if (boolval($mapping->option('keep_times', false))) {
            $model->timestamps = false;
            if (!$model->exists) {
                $model->created_at = $item->createdAt ?? $item->updatedAt ?? Carbon::now();
            }
            $model->updated_at = $item->updatedAt ?? Carbon::now();
        }
        $model->save();
    }

    /**
     * The attachments of the item, as the attachments of the record (one of the same name is not
     * added again).
     */
    protected static function attachments(SyncMapping $mapping, CustomValue $model, RemoteItem $item): void
    {
        $connection = $mapping->connection;
        $connector = isset($connection) ? $connection->connectorInstance() : null;
        if (!isset($connection, $connector)) {
            return;
        }
        $table = $model->custom_table;
        $have = $model->getDocuments(['count' => 1000])->map(function ($document) {
            return strval($document->getValue('document_name'));
        })->all();
        foreach ($connector->attachments($connection, $mapping->remote_object, $item) as $name => $contents) {
            $name = strval($name);
            if ($name === '' || in_array($name, $have, true)) {
                continue;
            }
            ExmentFile::storeAs(FileType::CUSTOM_VALUE_DOCUMENT, $contents, $table->table_name, $name)
                ->saveCustomValue($model->id, null, $table)
                ->saveDocumentModel($model, $name);
            $have[] = $name;
        }
    }

    protected static function deletesDue(SyncMapping $mapping): bool
    {
        return !isset($mapping->deletes_checked_at) || $mapping->deletes_checked_at->lt(Carbon::now()->subDay());
    }

    /**
     * The items gone from the service: marked (and deleted when the mapping says so).
     */
    protected static function deletes(SyncRun $run, SyncMapping $mapping, CustomTable $table): void
    {
        $connection = $mapping->connection;
        $connector = isset($connection) ? $connection->connectorInstance() : null;
        $ids = isset($connection, $connector) ? $connector->allIds($connection, $mapping->remote_object) : null;
        if (!isset($ids)) {
            return;
        }
        $there = array_flip(array_map('strval', $ids));
        SyncLink::query()->where('sync_mapping_id', $mapping->id)->whereNull('remote_deleted_at')->orderBy('id')
            ->chunk(500, function ($links) use ($there, $run, $mapping, $table) {
                foreach ($links as $link) {
                    /** @var SyncLink $link */
                    if (isset($there[$link->remote_id])) {
                        continue;
                    }
                    $link->remote_deleted_at = Carbon::now();
                    $link->save();
                    $run->add('marked');
                    if ($mapping->delete_rule === SyncMapping::DELETE_DELETE && isset($link->custom_value_id)) {
                        $record = $table->getValueModel($link->custom_value_id);
                        if ($record instanceof CustomValue) {
                            $record->saved_notify(false);
                            $record->delete();
                            $run->add('deleted');
                        }
                    }
                }
            });
        $mapping->deletes_checked_at = Carbon::now();
    }

    protected static function error(SyncRun $run, ?string $remoteId, string $message, bool $count = true): void
    {
        if ($count) {
            $run->add('errors');
        }
        if (SyncRunError::query()->where('sync_run_id', $run->id)->count() >= static::MAX_ERRORS) {
            return;
        }
        SyncRunError::query()->create([
            'sync_run_id' => $run->id,
            'remote_id' => $remoteId,
            'message' => Str::limit($message, 2000),
        ]);
    }

    /**
     * The end of a run: its state, the cursor and the next time of the mapping, and a bell when
     * it failed or had errors.
     */
    public static function finish(SyncRun $run, ?SyncMapping $mapping, string $status, ?string $message): void
    {
        $run->status = $status;
        $run->message = $message;
        $run->finished_at = Carbon::now();
        $run->save();
        if (!isset($mapping) || $run->dry_run) {
            if (isset($mapping) && $status === SyncRun::STATUS_FAILED) {
                static::bell($mapping, $run);
            }
            return;
        }
        $mapping->last_run_at = Carbon::now();
        if ($status === SyncRun::STATUS_FAILED) {
            $mapping->failures = $mapping->failures + 1;
            // failures in a row: a longer wait each time, a day at most
            $wait = min(1440, $mapping->interval_minutes * (2 ** min(5, $mapping->failures)));
            $mapping->next_run_at = Carbon::now()->addMinutes($wait);
        } else {
            $mapping->failures = 0;
            $mapping->cursor = $run->cursor;
            $mapping->next_run_at = Carbon::now()->addMinutes(max(1, $mapping->interval_minutes));
        }
        $mapping->save();
        if ($status !== SyncRun::STATUS_SUCCEEDED) {
            static::bell($mapping, $run);
        }
    }

    /**
     * A bell to the system administrators.
     */
    protected static function bell(SyncMapping $mapping, SyncRun $run): void
    {
        foreach (array_unique(array_map(fn ($id) => intval($id), (array)(System::system_admin_users() ?? []))) as $userId) {
            try {
                $bell = new NotifyNavbar();
                $bell->notify_id = 0;
                $bell->target_user_id = $userId;
                $bell->notify_subject = Str::limit(strval(exmtrans('sync.bell_subject', ['name' => $mapping->name])), 190);
                $bell->notify_body = Str::limit(strval(exmtrans('sync.bell_body', [
                    'status' => exmtrans('sync.status.' . $run->status),
                    'errors' => $run->count('errors'),
                    'message' => strval($run->message),
                    'url' => admin_urls('sync', 'runs', $run->id),
                ])), 1990);
                $bell->save();
            } catch (\Throwable $e) {
                \Log::error('[Exment] the bell of a sync was not saved: ' . $e->getMessage());
            }
        }
    }

    /**
     * A message of an exception that may be shown: a connector's own (it has no secret), else
     * its class only.
     */
    protected static function safeMessage(\Throwable $e): string
    {
        if ($e instanceof SyncException) {
            return $e->getMessage();
        }
        \Log::warning('[Exment] sync: ' . get_class($e) . ': ' . $e->getMessage());
        return get_class($e);
    }

    /**
     * The mappings whose time has come (the schedule, every 5 minutes): a run each.
     *
     * @return array<int, SyncRun>
     */
    public static function due(): array
    {
        if (!static::enabled()) {
            return [];
        }
        $runs = [];
        $mappings = SyncMapping::query()->where('enabled', true)
            ->where(function ($query) {
                $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', Carbon::now());
            })->get();
        foreach ($mappings as $mapping) {
            /** @var SyncMapping $mapping */
            $connection = $mapping->connection;
            if (!isset($connection) || !$connection->enabled) {
                continue;
            }
            // one at a time: a run not over is not started again
            if (SyncRun::query()->where('sync_mapping_id', $mapping->id)->where('status', SyncRun::STATUS_RUNNING)->where('started_at', '>', Carbon::now()->subSeconds(static::LOCK_SECONDS))->exists()) {
                continue;
            }
            $runs[] = static::start($mapping, false, 'schedule');
        }
        return $runs;
    }
}
