<?php

namespace Exceedone\Exment\Tests\Integration\Meili;

use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Meilisearch\Client;
use Tests\TestCase;

/** Opt-in contract test against a random, isolated Meilisearch index. */
class AttachmentMeiliIntegrationTest extends TestCase
{
    public function testNestedAttachmentTextAndNameAreSearchableWithParentFiltering(): void
    {
        if (getenv('MEILISEARCH_INTEGRATION_TEST') !== '1') {
            $this->markTestSkipped('Set MEILISEARCH_INTEGRATION_TEST=1 to run against a real Meilisearch server.');
        }
        if (!class_exists(Client::class)) {
            $this->markTestSkipped('The Meilisearch PHP SDK is not installed.');
        }

        $client = MeiliClientFactory::make();
        if (!$client->isHealthy()) {
            $this->markTestSkipped('Configured Meilisearch server is unavailable.');
        }

        $indexName = 'exment_attachment_it_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $created = false;
        try {
            $this->wait($client, $client->createIndex($indexName, ['primaryKey' => 'id']));
            $created = true;
            $index = $client->index($indexName);
            $this->wait($client, $index->updateSettings([
                'searchableAttributes' => ['label', 'attachments.name', 'attachments.text'],
                'filterableAttributes' => ['table_name'],
            ]));

            $token = 'MEILIATTACHMENT' . strtoupper(bin2hex(random_bytes(5)));
            $this->wait($client, $index->addDocuments([
                [
                    'id' => 'attachment__101',
                    'table_name' => 'attachment_test',
                    'value_id' => 101,
                    'label' => 'Visible parent record',
                    'attachments' => [
                        ['file_uuid' => 'a', 'name' => '見積書★.pdf', 'text' => $token . ' 日本語・金額 ¥1,234'],
                        ['file_uuid' => 'b', 'name' => 'unparsed.docx', 'text' => ''],
                    ],
                ],
                [
                    'id' => 'attachment__102',
                    'table_name' => 'other_table',
                    'value_id' => 102,
                    'label' => 'Other table record',
                    'attachments' => [['file_uuid' => 'c', 'name' => 'other.docx', 'text' => $token]],
                ],
            ]));

            $hits = $index->search($token, ['filter' => "table_name = 'attachment_test'"])->getHits();
            $this->assertCount(1, $hits);
            $this->assertSame('attachment__101', $hits[0]['id']);
            $this->assertSame('見積書★.pdf', $hits[0]['attachments'][0]['name']);
            $this->assertStringContainsString('日本語・金額 ¥1,234', $hits[0]['attachments'][0]['text']);

            $nameHits = $index->search('unparsed', ['filter' => "table_name = 'attachment_test'"])->getHits();
            $this->assertSame(['attachment__101'], array_column($nameHits, 'id'));
        } finally {
            if ($created) {
                $this->wait($client, $client->deleteIndex($indexName));
            }
        }
    }

    /** @param array<string,mixed> $task */
    private function wait(Client $client, array $task): void
    {
        $id = $task['taskUid'] ?? $task['uid'] ?? null;
        $this->assertNotNull($id, 'Meilisearch did not return a task identifier.');
        $completed = $client->waitForTask($id, 30000);
        $this->assertSame('succeeded', $completed['status'] ?? null, json_encode($completed));
    }
}
