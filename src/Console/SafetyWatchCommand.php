<?php

namespace Exceedone\Exment\Console;

use Carbon\Carbon;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\EarthquakeFeedInterface;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Polls the earthquake bulletin feed (EarthquakeFeedInterface) and auto-triggers
 * a safety-check (安否確認) event when a bulletin's NATIONWIDE max scale reaches
 * the configured threshold — no prefecture filtering (2026-08-11 decision).
 * Dedupes by `jma_event_id`, suppresses re-triggering for the SAME earthquake
 * (matching `quake_time`) within a cooldown window — a distinct quake always
 * triggers — and advances the feed cursor (`safety_check_last_feed_time`, bulletin receive time)
 * for every consumed item — including skipped ones — so the same bulletin is never
 * re-evaluated. Exception: when the event row itself fails to save, the cursor
 * stays put and the loop stops, so the next poll retries the bulletin.
 * Registered to run every minute, see ExmentServiceProvider::bootSchedule().
 */
class SafetyWatchCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:safetywatch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll the earthquake feed and auto-trigger safety-check (安否確認) events';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!boolval(System::safety_check_auto_enabled())) {
            return 0;
        }

        // intSetting: falls back to the Define default when the stored value is 0
        // (an emptied admin-UI field) — 0 here would mean "trigger on every quake" /
        // "no cooldown" / "every bulletin is stale" respectively.
        $minScale = SafetyCheckDefine::intSetting('safety_check_min_scale');
        $cooldown = SafetyCheckDefine::intSetting('safety_check_cooldown_minutes');
        // A bulletin older than this (minutes) is treated as stale and skipped, so a
        // first run (null cursor) or a re-enable after the watcher was off for a while
        // does not blast every user with a days-old qualifying quake.
        $maxAge = SafetyCheckDefine::intSetting('safety_check_max_bulletin_age_minutes');
        $last = System::safety_check_last_feed_time(); // null on first run
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        if (!$eventTable) {
            // Half-installed environment: the install migration was marked run while
            // SafetyCheckInstaller::ensureAll() no-oped (LINE template not imported).
            // Without this guard the every-minute schedule would crash-loop exactly
            // when a qualifying quake arrives. Recovery: php artisan db:seed
            // --class=Exceedone\\Exment\\Database\\Seeder\\InstallSeeder
            Log::error('safety check tables are not installed; skipping poll');
            return 0;
        }
        // clamp to [1, 100]: P2PQuake rejects limit > 100 with HTTP 400, which would
        // silently kill the whole feed on a misconfigured EXMENT_SAFETY_CHECK_FEED_LIMIT
        $feedLimit = min(100, max(1, (int) config('exment.safety_check.feed_limit', 10)));

        foreach (app(EarthquakeFeedInterface::class)->fetchRecent($feedLimit) as $item) {
            // Cursor runs on received_at (bulletin receive time, ms precision), NOT on
            // $item['time'] (quake occurred time): correction bulletins that upgrade the
            // max scale share the occurred time with the already-consumed prompt report.
            $receivedAt = $item['received_at'] ?? $item['time'];
            if (!is_nullorempty($last) && $receivedAt->lte(Carbon::parse($last))) {
                continue;
            }

            if ($receivedAt->lt(now()->subMinutes($maxAge))) {
                // stale bulletin: on a first run (null cursor) or after auto_enabled was
                // off for a while (cursor never advances while disabled), fetchRecent()
                // can return days-old items. Skip them rather than blasting every user
                // the moment the feature comes online; still advance the cursor so this
                // bulletin is never re-evaluated (same for every skip branch below).
                $newLast = $receivedAt;
                continue;
            }

            if ($item['max_scale'] < $minScale) {
                $newLast = $receivedAt;
                continue;
            }
            // nationwide max-scale comparison only; $affected is for quake_info text
            $affected = collect($item['points'])->filter(function ($p) use ($minScale) {
                return $p['scale'] >= $minScale;
            });

            if ($eventTable->getValueModel()->where('value->jma_event_id', $item['id'])->exists()) {
                $newLast = $receivedAt;
                continue;
            }

            // Cooldown is scoped to the SAME earthquake (shared occurred time): every
            // P2PQuake bulletin has its own id, so the jma_event_id dedupe above cannot
            // catch a correction/detail bulletin of an already-triggered quake — this
            // can. A DISTINCT quake (own occurred time), e.g. a bigger mainshock right
            // after a foreshock, must always trigger its own event.
            $recent = $eventTable->getValueModel()->where('value->trigger_type', SafetyCheckDefine::TRIGGER_JMA_AUTO)
                ->where('value->quake_time', $item['time']->format('Y-m-d H:i:s'))
                ->where('value->triggered_at', '>=', now()->subMinutes($cooldown)->format('Y-m-d H:i:s'))
                ->exists();
            if ($recent) {
                Log::info('safety check suppressed by cooldown', ['jma_event_id' => $item['id']]);
                $newLast = $receivedAt;
                continue;
            }

            $event = null;
            try {
                $event = $eventTable->getValueModel()->setValue([
                    'title' => exmtrans('safety.event_table_view_name') . '（' . $item['time']->format('Y-m-d H:i') . ' ' . $item['hypocenter'] . '）',
                    'trigger_type' => SafetyCheckDefine::TRIGGER_JMA_AUTO,
                    'event_status' => SafetyCheckDefine::EVENT_OPEN,
                    'triggered_at' => now()->format('Y-m-d H:i:s'),
                    'jma_event_id' => $item['id'],
                    'quake_time'   => $item['time']->format('Y-m-d H:i:s'),
                    'quake_info' => $item['hypocenter'] . ' / max scale ' . $item['max_scale'] . ' / ' . $affected->pluck('pref')->unique()->implode('・'),
                ]);
                $event->save();
                SafetyCheckSender::send($event);
            } catch (\Throwable $e) {
                Log::error('safety check auto trigger failed', ['jma_event_id' => $item['id'], 'exception' => $e]);
                if (!$event || !$event->exists) {
                    // The event row itself never got saved: nothing durable exists (no
                    // event, no answer rows, no dedupe key), so do NOT consume this
                    // bulletin — leave the cursor before it and stop; the next poll
                    // retries it. A permanently failing bulletin self-heals: it ages
                    // past max_bulletin_age and is then skipped with a cursor advance.
                    break;
                }
                // The event row was saved, only the send blew up mid-way: consume the
                // bulletin (jma_event_id dedupe would block a re-trigger anyway). The
                // event stays visible on the admin page and 再送 is the recovery path —
                // it re-pushes to every still-unanswered user AND recreates answer rows
                // whose creation failed here (see SafetyCheckSender::send).
            }
            $newLast = $receivedAt;
        }

        if (isset($newLast)) {
            // microseconds kept: two bulletins received within the same second must not
            // shadow each other via the lte() cursor comparison.
            // withoutEvents: System uses ClearCacheTrait, so a plain setting save fires
            // saved -> System::clearCache() -> Cache::flush() of the ENTIRE store when
            // exment.use_cache is on — every minute, evicting unrelated entries
            // including the schedule's withoutOverlapping mutex. Instead, skip the
            // model events and clear only the two cache keys the cursor read uses
            // (the per-setting key and the systems-table snapshot).
            $cursor = $newLast->format('Y-m-d H:i:s.u');
            System::withoutEvents(function () use ($cursor) {
                System::safety_check_last_feed_time($cursor);
            });
            System::clearCache(sprintf(Define::SYSTEM_KEY_SESSION_SYSTEM_CONFIG, 'safety_check_last_feed_time'));
            System::clearCache(sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, (new System())->getTable()));
        }

        return 0;
    }
}
