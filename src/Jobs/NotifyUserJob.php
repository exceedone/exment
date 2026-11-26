<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Services\Notify\NotifyRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The notification of a saved record, made by the queue worker (NotifyRunner, ISS02906): finds
 * the recipients and tells them, as the save did before. The mails and bells it makes are jobs
 * of their own (MailSendJob retries and reports its failures).
 *
 * Once only: a second try would tell again the users told before the failure.
 */
class NotifyUserJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var int */
    public $tries = 1;

    /**
     * @param array<string, mixed> $options texts only (comment, attachment, prms)
     */
    public function __construct(
        public int $notifyId,
        public string $tableName,
        public int $customValueId,
        public array $options,
        public ?int $actorUserId
    ) {
        // after the save is committed: the worker must find the record (Queueable has the property)
        $this->afterCommit();
    }

    public function handle(): void
    {
        $notify = Notify::find($this->notifyId);
        $custom_table = CustomTable::getEloquent($this->tableName);
        if (!$notify || !$custom_table) {
            return;
        }
        // as the save saw it; a record deleted since is still told about
        $custom_value = getModelName($custom_table)::withTrashed()->find($this->customValueId);
        if (!$custom_value) {
            return;
        }

        // the user who saved (not told about their own save, as before)
        $guard = \Auth::guard(\Exment::getGuard());
        $before = $guard->user();
        $actor = isset($this->actorUserId) ? LoginUser::where('base_user_id', $this->actorUserId)->first() : null;
        if ($actor) {
            $guard->setUser($actor);
        }
        try {
            NotifyRunner::runNow($notify, $custom_value, $this->options);
        } finally {
            if ($before) {
                $guard->setUser($before);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }
}
