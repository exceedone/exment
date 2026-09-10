<?php

namespace Exceedone\Exment\Exceptions;

/**
 * Thrown by LineSendJob::handle() when a push is rejected by the LINE API
 * (non-retryable status, or retryable but no retry possible) and the job runs on
 * the sync queue driver — the only way an inline caller (SafetyCheckSender) can
 * learn that the user was NOT reached. Carries the API result so failed() can
 * still log status/body. On a real queue the job logs and finishes quietly instead
 * (see LineSendJob::handle).
 */
class LineSendFailedException extends \RuntimeException
{
    /** @var array{status:int,ok:bool,body:array,raw:string} */
    protected $result;

    public function __construct(string $to, array $result)
    {
        parent::__construct(sprintf('LINE push to %s failed with status %s', $to, $result['status'] ?? 0));
        $this->result = $result;
    }

    public function getResult(): array
    {
        return $this->result;
    }
}
