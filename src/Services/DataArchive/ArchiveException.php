<?php

namespace Exceedone\Exment\Services\DataArchive;

/**
 * Why the data archive stopped: a key of data_archive.errors, with its values.
 */
class ArchiveException extends \RuntimeException
{
    /** @var string */
    protected $reason;

    /** @var array<string, mixed> */
    protected $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(string $reason, array $values = [])
    {
        $this->reason = $reason;
        $this->values = $values;
        parent::__construct($reason . (count($values) > 0 ? ' ' . json_encode($values) : ''));
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** The message for the page, in the language of the user. */
    public function text(): string
    {
        return strval(exmtrans('data_archive.errors.' . $this->reason, $this->values));
    }
}
