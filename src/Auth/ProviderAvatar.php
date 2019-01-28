<?php

namespace Exceedone\Exment\Auth;

interface ProviderAvatar
{
    /**
     * Get the User avatar.
     * @param mixed $token access token if necessary
     */
    public function getAvatar($token = null);
}
