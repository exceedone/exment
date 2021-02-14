<?php

namespace Exceedone\Exment\Auth;

use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\PublicForm;

class PublicFormGuard extends \Illuminate\Auth\TokenGuard
{
    /**
     * Create a new authentication guard.
     *
     * @param  \Illuminate\Contracts\Auth\UserProvider  $provider
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $inputKey
     * @param  string  $storageKey
     * @param  bool  $hash
     * @return void
     */
    public function __construct(
        UserProvider $provider,
        Request $request)
    {
        $this->request = $request;
        $this->provider = $provider;
        $this->storageKey = 'uuid';
    }

    /**
     * Get the token for the current request.
     *
     * @return string|null
     */
    public function getTokenForRequest()
    {
        return PublicForm::getUuidByRequest();
    }
}
