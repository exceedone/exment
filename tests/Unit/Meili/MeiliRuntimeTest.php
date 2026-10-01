<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Controllers\SearchController;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\Meili\MeiliConfig;
use Exceedone\Exment\Services\Meili\MeiliRuntime;
use Tests\TestCase;

class MeiliRuntimeTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['enabled', 'global_search', 'realtime_sync', 'repair_enabled'] as $key) {
            $this->original[$key] = config("meilisearch.{$key}");
        }
        $this->original['search_document'] = config('exment.search_document');
    }

    protected function tearDown(): void
    {
        foreach (['enabled', 'global_search', 'realtime_sync', 'repair_enabled'] as $key) {
            config(["meilisearch.{$key}" => $this->original[$key]]);
        }
        config(['exment.search_document' => $this->original['search_document']]);
        parent::tearDown();
    }

    public function testGlobalSearchSwitchControlsOnlyTheReadPath(): void
    {
        config([
            // An obsolete value from an older deployment must not gate reads.
            'meilisearch.enabled' => false,
            'meilisearch.global_search' => false,
            'meilisearch.realtime_sync' => true,
            'exment.search_document' => false,
        ]);

        $probe = new class extends SearchController {
            public function enabledForTest(): bool
            {
                return $this->meiliEnabled();
            }

            public function activeForTest(): bool
            {
                return $this->meiliActive();
            }
        };

        $this->assertFalse($probe->enabledForTest());
        $this->assertFalse($probe->activeForTest());
        $this->assertTrue(MeiliRuntime::realtimeSyncEnabled());

        config(['meilisearch.global_search' => true]);
        $this->assertTrue($probe->enabledForTest());
        $this->assertTrue($probe->activeForTest());
        config(['exment.search_document' => true]);
        $this->assertTrue($probe->enabledForTest());
        $this->assertFalse($probe->activeForTest());
    }

    public function testRealtimeSyncUsesOnlyItsOwnFlag(): void
    {
        config(['meilisearch.enabled' => false, 'meilisearch.global_search' => false, 'meilisearch.realtime_sync' => false]);
        $this->assertFalse(MeiliRuntime::realtimeSyncEnabled());
        config(['meilisearch.realtime_sync' => true]);
        $this->assertTrue(MeiliRuntime::realtimeSyncEnabled());
        $this->assertArrayNotHasKey('meili_master_enabled', MeiliConfig::MAP);
        $this->assertArrayNotHasKey('meili_master_enabled', Define::SYSTEM_SETTING_NAME_VALUE);
    }

    public function testSyncQueueDetectionReadsDriverEvenWhenConnectionHasAnotherName(): void
    {
        $default = config('queue.default');
        $connection = config('queue.connections.inline_uploads');
        try {
            config(['queue.default' => 'inline_uploads', 'queue.connections.inline_uploads' => ['driver' => 'sync']]);
            $this->assertTrue(MeiliRuntime::queueUsesSyncDriver());
            config(['queue.connections.inline_uploads.driver' => 'database']);
            $this->assertFalse(MeiliRuntime::queueUsesSyncDriver());
        } finally {
            config(['queue.default' => $default, 'queue.connections.inline_uploads' => $connection]);
        }
    }

    public function testReconcileScheduleUsesOnlyRepairSwitch(): void
    {
        config(['meilisearch.enabled' => false, 'meilisearch.global_search' => false, 'meilisearch.repair_enabled' => false]);
        $this->assertFalse(MeiliRuntime::reconcileScheduleEnabled());
        config(['meilisearch.repair_enabled' => true]);
        $this->assertTrue(MeiliRuntime::reconcileScheduleEnabled());
    }
}
