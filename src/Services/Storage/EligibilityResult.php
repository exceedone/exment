<?php

namespace Exceedone\Exment\Services\Storage;

/**
 * Result of ConversionEligibility::check(): blockers, warnings, open-decision dependencies.
 */
final class EligibilityResult
{
    /** @var array<int, array<string, mixed>> */
    private $blockers;
    /** @var array<int, array<string, mixed>> */
    private $warnings;
    /** @var array<string, string> */
    private $dependencies;

    /**
     * @param array<int, array<string, mixed>> $blockers
     * @param array<int, array<string, mixed>> $warnings
     * @param array<string, string> $dependencies
     */
    public function __construct(array $blockers, array $warnings, array $dependencies)
    {
        $this->blockers = $blockers;
        $this->warnings = $warnings;
        $this->dependencies = $dependencies;
    }

    public function isEligible(): bool
    {
        return empty($this->blockers);
    }

    /** @return array<int, array<string, mixed>> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    /** @return array<int, string> */
    public function blockerCodes(): array
    {
        return array_values(array_unique(array_map(function ($b) {
            return (string)$b['code'];
        }, $this->blockers)));
    }

    /** @return array<int, array<string, mixed>> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, string> */
    public function dependencies(): array
    {
        return $this->dependencies;
    }
}
