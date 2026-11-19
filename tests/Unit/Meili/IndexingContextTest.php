<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Meili\IndexingContext;
use Exceedone\Exment\Tests\Unit\UnitTestBase;

/**
 * On the 'sync' queue driver the indexing jobs run inside the request of the
 * user who saved the record, so CustomValueModelScope filters the linked
 * records that user may not open and the document stores the "no permission"
 * text where the real label belongs. The index is read by everyone, so it is
 * always built as nobody.
 */
class IndexingContextTest extends UnitTestBase
{
    private function loginSomeone(): LoginUser
    {
        $user = LoginUser::first();
        if (!$user) {
            $this->markTestSkipped('no login user in the test database');
        }

        // Both halves matter: the guard keeps the user in a property AND reads
        // it back from the session when that property is empty.
        $guard = \Auth::guard(\Exment::getGuard());
        session()->put($guard->getName(), $user->getAuthIdentifier());
        $guard->setUser($user);

        return $user;
    }

    public function testTheBlockRunsWithNoViewer(): void
    {
        $user = $this->loginSomeone();
        $this->assertNotNull(\Exment::user());

        $inside = IndexingContext::run(fn () => \Exment::user());

        $this->assertNull($inside, 'the document must be built with no viewer');
        $this->assertNotNull(\Exment::user(), 'the viewer must be back afterwards');
        $this->assertSame(
            $user->getAuthIdentifier(),
            session()->get(\Auth::guard(\Exment::getGuard())->getName())
        );
    }

    /**
     * getValueModel() caches every record it resolves for the rest of the
     * request. Left behind, the unfiltered models this block resolves would be
     * served to the very screen the viewer is looking at.
     */
    public function testWhatTheBlockCachedDoesNotOutliveIt(): void
    {
        $this->loginSomeone();
        System::setRequestSession('meili_indexing_probe_before', 'kept');

        IndexingContext::run(function () {
            System::setRequestSession('meili_indexing_probe_inside', 'leaked');
            System::setRequestSession('meili_indexing_probe_before', 'overwritten');
        });

        $this->assertNull(System::requestSession('meili_indexing_probe_inside'));
        $this->assertSame('kept', System::requestSession('meili_indexing_probe_before'));
    }

    /**
     * getValueModel() caches the records it could NOT resolve too. Entering the
     * block with the viewer's cache still in place, the mapping reads back that
     * cached "not found" and writes the "no permission" text anyway.
     */
    public function testTheBlockStartsWithAnEmptyCache(): void
    {
        $this->loginSomeone();
        System::setRequestSession('meili_indexing_probe_stale', 'resolved for the viewer');

        $seen = IndexingContext::run(fn () => System::requestSession('meili_indexing_probe_stale'));

        $this->assertNull($seen, 'the mapping must not read what the viewer resolved');
        $this->assertSame('resolved for the viewer', System::requestSession('meili_indexing_probe_stale'));
    }

    public function testTheViewerComesBackWhenTheBlockThrows(): void
    {
        $this->loginSomeone();

        // the exception must reach the caller, and the viewer is back before it does
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('meilisearch is down');
        try {
            IndexingContext::run(function () {
                throw new \RuntimeException('meilisearch is down');
            });
        } finally {
            $this->assertNotNull(\Exment::user());
        }
    }

    /** A queue worker has no viewer to begin with: nothing to swap, nothing to clear. */
    public function testWithNoViewerTheCacheIsLeftAlone(): void
    {
        \Auth::guard(\Exment::getGuard())->logout();
        System::setRequestSession('meili_indexing_probe_worker', 'kept');

        IndexingContext::run(fn () => null);

        $this->assertSame('kept', System::requestSession('meili_indexing_probe_worker'));
    }
}
