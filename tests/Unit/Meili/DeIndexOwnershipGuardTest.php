<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use PHPUnit\Framework\TestCase;

/**
 * Disabling search on a table, or deleting it, makes ReindexMeiliTableJob wipe
 * every document carrying that table name. On an index another installation
 * owns those are ITS documents: the table name and record id are all a document
 * id carries, so the filter matches them just the same.
 *
 * The two ensureIndexExists() calls that claim the index for this installation
 * both sit on the indexing path, after the de-index branch has already
 * returned, so that branch used to reach Meilisearch unguarded.
 *
 * Checked against the source, because reaching the branch needs a Meilisearch.
 */
class DeIndexOwnershipGuardTest extends TestCase
{
    use MeiliSourceTrait;

    public function testTheDeIndexDeleteClaimsTheIndexFirst(): void
    {
        $body = $this->methodBody('Jobs/ReindexMeiliTableJob.php', 'public function handle(');

        $this->assertCallOrder(
            $body,
            'MeiliIndexOwnership::claim',
            'deleteDocuments',
            'handle() must claim the index before it deletes documents'
        );
    }

    /**
     * A foreign index must end the job, not be reported as "nothing to drop":
     * isNotFound() only answers for a 404, so the claim's exception has to stay
     * on the throw path the rest of the write sites already use.
     */
    public function testAForeignIndexIsNotSwallowedAsAMissingIndex(): void
    {
        $body = $this->methodBody('Jobs/ReindexMeiliTableJob.php', 'public function handle(');

        $this->assertStringNotContainsString(
            'MeiliForeignIndexException',
            $body,
            'the de-index branch must not catch the foreign-index error'
        );
    }
}
