<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Model\System;

/**
 * Build Meilisearch documents as nobody.
 *
 * DocumentMapper resolves a linked record through getValue(), which goes
 * through CustomValueModelScope: with a logged-in viewer the scope hides the
 * rows they may not open and the mapper stores exmtrans('common.message.
 * no_permission') where the real label belongs. On the 'sync' queue driver the
 * indexing jobs run inside the request of the user who saved the record, so
 * that is exactly what happens - and the document stays wrong for every
 * reader, including the ones who may see the linked record, until the next
 * reindex.
 *
 * The scope steps aside only when there is no user at all (see its apply()),
 * which is the state a queue worker and the CLI commands are already in. This
 * puts the sync driver in the same state for the length of the mapping.
 */
final class IndexingContext
{
    /**
     * Run $callback with no viewer, then put the viewer back.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public static function run(callable $callback)
    {
        $guard = \Auth::guard(\Exment::getGuard());
        $user = $guard->user();

        // A queue worker or a console command has no viewer to begin with.
        if ($user === null) {
            return $callback();
        }

        // The guard answers from a property, and reads the session back into
        // it when that property is empty - so both have to go.
        $sessionKey = $guard->getName();
        $sessionId = session()->get($sessionKey);

        // getValueModel() caches every record it resolves for the rest of the
        // request - including the ones it could NOT resolve. The block has to
        // start clean, or the mapping reads back the viewer's "not found" and
        // writes the "no permission" text anyway; and it has to end clean, or
        // the unfiltered models resolved here reach the screen this very
        // request is drawing.
        $requestSession = System::getRequestSessionAll();

        session()->forget($sessionKey);
        $guard->forgetUser();
        System::clearRequestSession();

        try {
            return $callback();
        } finally {
            if ($sessionId !== null) {
                session()->put($sessionKey, $sessionId);
            }
            $guard->setUser($user);
            System::setRequestSessionAll($requestSession);
        }
    }
}
