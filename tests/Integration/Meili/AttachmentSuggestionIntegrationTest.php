<?php

namespace Exceedone\Exment\Tests\Integration\Meili;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Meilisearch\Client;
use Tests\TestCase;

/** Opt-in test using an isolated temporary index; never alters Exment's index. */
class AttachmentSuggestionIntegrationTest extends TestCase
{
    public function testUrlIsNotSearchableButAttachmentNameAndCroppedContentAre(): void
    {
        if (getenv('MEILISEARCH_INTEGRATION_TEST') !== '1') {
            $this->markTestSkipped('Set MEILISEARCH_INTEGRATION_TEST=1 to use a real Meilisearch server.');
        }
        if (!class_exists(Client::class)) {
            $this->markTestSkipped('The Meilisearch PHP SDK is not installed.');
        }

        $client = MeiliClientFactory::make();
        if (!$client->isHealthy()) {
            $this->markTestSkipped('Configured Meilisearch server is unavailable.');
        }

        $indexName = 'exment_suggestion_it_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $created = false;
        try {
            $this->wait($client, $client->createIndex($indexName, ['primaryKey' => 'id']));
            $created = true;
            $index = $client->index($indexName);
            $this->wait($client, $index->updateSettings([
                'searchableAttributes' => ['label', 'fields', 'attachments.name', 'attachments.text'],
            ]));

            $urlToken = 'URLONLY' . strtoupper(bin2hex(random_bytes(7)));
            $contentToken = 'FILECONTENT' . strtoupper(bin2hex(random_bytes(7)));
            $secondToken = 'SECONDFILE' . strtoupper(bin2hex(random_bytes(7)));
            $record = new class($urlToken) {
                public int $id = 103;
                public string $label = 'Customer Record';

                public function __construct(private string $urlToken)
                {
                }

                public function getValue($column, $label = false): string
                {
                    return 'http://localhost/admin/files/' . $this->urlToken;
                }
            };
            // Simulate a document written by the old mapper before reindex.
            $this->wait($client, $index->addDocuments([[
                'id' => 'customer__103',
                'table_name' => 'customer',
                'value_id' => 103,
                'label' => 'Customer Record',
                'fields' => ['file' => 'http://localhost/admin/files/' . $urlToken],
                'attachment_names' => 'LEGACYONLYFILE.pdf',
                'attachment_text' => 'LEGACYONLYCONTENT',
            ]]));
            $document = (new DocumentMapper())->map(
                $record,
                [(object) ['column_name' => 'file', 'column_type' => 'file']],
                'customer',
                'Customer',
                [],
                [],
                [],
                [103 => ['attachments' => [
                    ['file_uuid' => 'file-a', 'name' => 'QuarterlyPlan.pdf', 'text' => str_repeat('ordinary context words ', 40) . $contentToken . ' approved'],
                    ['file_uuid' => 'file-b', 'name' => 'Other.docx', 'text' => 'Unrelated file content ' . $secondToken],
                ]]]
            );
            $this->wait($client, $index->addDocuments([$document]));

            $stored = $index->getDocument('customer__103');
            $this->assertArrayNotHasKey('attachment_names', $stored);
            $this->assertArrayNotHasKey('attachment_text', $stored);
            $this->assertSame('file-a', $stored['attachments'][0]['file_uuid']);

            $leanHits = $index->search($contentToken, [
                'attributesToRetrieve' => ['label', 'attachments.file_uuid', 'attachments.name'],
                'attributesToHighlight' => ['attachments.name', 'attachments.text'],
                'attributesToCrop' => ['attachments.text'],
                'cropLength' => 16,
            ])->getHits();
            $this->assertStringContainsString($contentToken, $leanHits[0]['_formatted']['attachments'][0]['text'] ?? '');

            $service = new MeiliSearchService($client, $indexName);
            $this->assertSame([], $service->searchHighlighted($urlToken, 10));

            $nameHits = $service->searchHighlighted('QuarterlyPlan', 10);
            $this->assertSame([103], array_column($nameHits, 'value_id'));
            $this->assertStringContainsString(MeiliSearchService::HIGHLIGHT_PRE, $nameHits[0]['snippet']);
            $this->assertStringContainsString('QuarterlyPlan.pdf', $this->withoutHighlight($nameHits[0]['snippet']));
            $this->assertStringNotContainsString('Other.docx', $nameHits[0]['snippet']);

            $contentHits = $service->searchHighlighted($contentToken, 10);
            $this->assertSame([103], array_column($contentHits, 'value_id'));
            $this->assertStringContainsString($contentToken, $this->withoutHighlight($contentHits[0]['snippet']));
            $this->assertStringContainsString('QuarterlyPlan.pdf', $this->withoutHighlight($contentHits[0]['snippet']));
            $this->assertStringNotContainsString('Other.docx', $contentHits[0]['snippet']);
            $this->assertLessThan(250, strlen($contentHits[0]['snippet']));

            $secondHits = $service->searchHighlighted($secondToken, 10);
            $this->assertSame([103], array_column($secondHits, 'value_id'));
            $this->assertStringContainsString('Other.docx', $secondHits[0]['snippet']);
            $this->assertStringNotContainsString('QuarterlyPlan.pdf', $secondHits[0]['snippet']);
        } finally {
            if ($created) {
                $this->wait($client, $client->deleteIndex($indexName));
            }
        }
    }

    private function wait(Client $client, array $task): void
    {
        $id = $task['taskUid'] ?? $task['uid'] ?? null;
        $this->assertNotNull($id);
        $completed = $client->waitForTask($id, 30000);
        $this->assertSame('succeeded', $completed['status'] ?? null, json_encode($completed));
    }

    private function withoutHighlight(string $snippet): string
    {
        return str_replace([MeiliSearchService::HIGHLIGHT_PRE, MeiliSearchService::HIGHLIGHT_POST], '', $snippet);
    }
}
