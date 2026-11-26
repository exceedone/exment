<?php

namespace Exceedone\Exment\Exceptions;

/**
 * A storage conversion step (eligibility, physical schema, state transition, guard) refused to
 * proceed. Carries the structured reasons (blockers, missing protections, schema facts).
 */
class StorageConversionException extends \RuntimeException
{
    /** @var array<string, mixed> */
    private $details;

    /** @var string the reason without the structured details (for a screen) */
    private $reason;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, array $details = [])
    {
        $this->details = $details;
        $this->reason = $message;
        parent::__construct($message . (empty($details) ? '' : ' ' . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    /** The reason alone: a screen shows this, not the structured details. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
