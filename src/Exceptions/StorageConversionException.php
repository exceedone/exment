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

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $message, array $details = [])
    {
        $this->details = $details;
        parent::__construct($message . (empty($details) ? '' : ' ' . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
