<?php

namespace Exceedone\Exment\Services\Storage;

/**
 * One converted cell: the typed value for the physical column (null = SQL NULL) and whether the
 * public representation is reconstructible from it (render(typed) === original JSON value).
 * When it is not, the original JSON value goes to value_original (02 §15.2, §15.4).
 */
final class ConvertedCell
{
    /** @var int|string|null */
    private $typed;
    /** @var bool */
    private $lossless;
    /** @var string|null */
    private $reason;

    /**
     * @param int|string|null $typed
     */
    public function __construct($typed, bool $lossless, ?string $reason = null)
    {
        $this->typed = $typed;
        $this->lossless = $lossless;
        $this->reason = $reason;
    }

    /** @return int|string|null value bound to the physical column */
    public function typed()
    {
        return $this->typed;
    }

    public function isLossless(): bool
    {
        return $this->lossless;
    }

    /** Why the cell is not lossless (report / L5 categories), null when lossless. */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
