<?php

namespace Exceedone\Exment\Services\PluginDistribution;

/**
 * A release of a plugin that cannot be trusted or used here (ReleaseVerifier).
 * The reason: format, field, value, key, signature, zip, config, version.
 */
class ReleaseException extends \RuntimeException
{
    protected string $reason;

    public function __construct(string $reason, string $detail = '')
    {
        $this->reason = $reason;
        parent::__construct(strval(exmtrans('plugin_distribution.error_' . $reason, ['detail' => $detail])));
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
