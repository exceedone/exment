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

class SafetyWatchCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:safetywatch';

    /**
     * @var string
     */
    protected $description = 'Poll the earthquake feed and auto-trigger safety-check (安否確認) events';

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
        if (!boolval(System::safety_check_auto_enabled())) {
            return 0;
        }

        $minScale = SafetyCheckDefine::intSetting('safety_check_min_scale');
        $cooldown = SafetyCheckDefine::intSetting('safety_check_cooldown_minutes');
        $maxAge = SafetyCheckDefine::intSetting('safety_check_max_bulletin_age_minutes');
        $last = System::safety_check_last_feed_time();
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        if (!$eventTable) {
            Log::error('safety check tables are not installed; skipping poll');
            return 0;
        }
        $feedLimit = min(100, max(1, (int) config('exment.safety_check.feed_limit', 10)));

        foreach (app(EarthquakeFeedInterface::class)->fetchRecent($feedLimit) as $item) {
            $receivedAt = $item['received_at'] ?? $item['time'];
            if (!is_nullorempty($last) && $receivedAt->lte(Carbon::parse($last))) {
                continue;
            }

            if ($receivedAt->lt(now()->subMinutes($maxAge))) {
                if ($item['max_scale'] >= $minScale) {
                    Log::warning('safety check stale bulletin skipped', [
                        'jma_event_id' => $item['id'],
                        'received_at'  => $receivedAt->format('Y-m-d H:i:s'),
                        'max_scale'    => $item['max_scale'],
                        'max_bulletin_age_minutes' => $maxAge,
                    ]);
                }
                $newLast = $receivedAt;
                continue;
            }

            if ($item['max_scale'] < $minScale) {
                $newLast = $receivedAt;
                continue;
            }
            $affected = collect($item['points'])->filter(function ($p) use ($minScale) {
                return $p['scale'] >= $minScale;
            });

            if ($eventTable->getValueModel()->where('value->jma_event_id', $item['id'])->exists()) {
                $newLast = $receivedAt;
                continue;
            }

            $hypocenterKnown = trim((string) $item['hypocenter']) !== '';
            $hypocenter = $hypocenterKnown ? $item['hypocenter'] : exmtrans('safety.hypocenter_pending');
            $title = exmtrans('safety.event_table_view_name') . '（' . $item['time']->format('Y-m-d H:i') . ' ' . $hypocenter . '）';
            $quakeInfo = $hypocenter . ' / ' . SafetyCheckDefine::scaleLabel((int) $item['max_scale']) . ' / ' . $affected->pluck('pref')->unique()->implode('・');

            $recent = $eventTable->getValueModel()->where('value->trigger_type', SafetyCheckDefine::TRIGGER_JMA_AUTO)
                ->where('value->quake_time', $item['time']->format('Y-m-d H:i:s'))
                ->where('value->triggered_at', '>=', now()->subMinutes($cooldown)->format('Y-m-d H:i:s'))
                ->first();
            if ($recent) {
                Log::info('safety check suppressed by cooldown', ['jma_event_id' => $item['id']]);
                if ($hypocenterKnown && str_contains((string) $recent->getValue('quake_info'), exmtrans('safety.hypocenter_pending'))) {
                    $recent->setValue(['title' => $title, 'quake_info' => $quakeInfo])->save();
                }
                $newLast = $receivedAt;
                continue;
            }

            $event = null;
            try {
                $event = $eventTable->getValueModel()->setValue([
                    'title' => $title,
                    'trigger_type' => SafetyCheckDefine::TRIGGER_JMA_AUTO,
                    'event_status' => SafetyCheckDefine::EVENT_OPEN,
                    'triggered_at' => now()->format('Y-m-d H:i:s'),
                    'jma_event_id' => $item['id'],
                    'quake_time'   => $item['time']->format('Y-m-d H:i:s'),
                    'quake_info' => $quakeInfo,
                ]);
                $event->save();
                SafetyCheckSender::send($event);
            } catch (\Throwable $e) {
                Log::error('safety check auto trigger failed', ['jma_event_id' => $item['id'], 'exception' => $e]);
                if (!$event || !$event->exists) {
                    break;
                }
            }
            $newLast = $receivedAt;
        }

        if (isset($newLast)) {
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
