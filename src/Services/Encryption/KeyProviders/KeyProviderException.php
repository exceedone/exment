<?php

namespace Exceedone\Exment\Services\Encryption\KeyProviders;

/**
 * The keys could not be read from the provider (it is not reached, it refused, or the SDK is
 * not installed). The message never holds a key, a token or a secret.
 */
class KeyProviderException extends \RuntimeException
{
}
