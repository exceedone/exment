<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\MeiliIndexOwnership;
use Meilisearch\Exceptions\ApiException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Two Exment installations pointed at one Meilisearch index share every
 * document whose table name and record id coincide - the document id carries no
 * installation - so each overwrites the other's documents, serves the other's
 * record content in search results, and deletes the other's documents as
 * orphans on exment:meili-reconcile. The owner marker is what stops that.
 */
class MeiliIndexOwnershipTest extends TestCase
{
    public function testMarkerLivesInAnIndexOfItsOwn(): void
    {
        // Not a document inside the search index: it would turn up as a hit and
        // as a table in the facet distribution, and purgeStaleTables would
        // delete it on the first run.
        $this->assertSame('exment_global__owner', MeiliIndexOwnership::markerIndexName('exment_global'));
    }

    /**
     * The read is "is there a marker?", and "no index" and "no document" are
     * both answers, not failures - that is how an index built before this guard
     * existed gets adopted instead of refused.
     */
    public function testNotFoundRecognisesA404(): void
    {
        $this->assertTrue(MeiliIndexOwnership::isNotFound($this->apiException(404)));
    }

    /**
     * getDocuments() catches everything and rethrows a plain exception whose
     * message blames the client version, so the real status is only reachable
     * through the cause chain.
     */
    public function testNotFoundSeesThroughTheSdksVersionHint(): void
    {
        $wrapped = new \Exception(
            "Hint: It might not be working because maybe you're not up to date with the Meilisearch version that `getDocuments` call requires.",
            0,
            $this->apiException(404)
        );

        $this->assertTrue(MeiliIndexOwnership::isNotFound($wrapped));
    }

    public function testAnotherApiErrorIsNotTakenForNotFound(): void
    {
        // 403 means the key may not read the marker: ownership is unknown, and
        // treating that as "unclaimed" would hand the index to this install.
        $this->assertFalse(MeiliIndexOwnership::isNotFound($this->apiException(403)));
        $this->assertFalse(MeiliIndexOwnership::isNotFound($this->apiException(500)));
    }

    public function testAConnectionFailureIsNotNotFound(): void
    {
        $this->assertFalse(MeiliIndexOwnership::isNotFound(
            new \Meilisearch\Exceptions\CommunicationException('cURL error 28')
        ));
    }

    private function apiException(int $status): ApiException
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getReasonPhrase')->willReturn('stub');

        return new ApiException($response, ['message' => 'stub', 'code' => 'index_not_found']);
    }
}
