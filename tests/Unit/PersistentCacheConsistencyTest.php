<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * What the persistent cache (EXMENT_USE_CACHE=true) does with data read inside a transaction and after a change
 * of the metadata. Not transactional itself: it opens its own transactions. Isolated database only.
 */
class PersistentCacheConsistencyTest extends UnitTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['exment.use_cache' => true, 'cache.default' => 'array']);
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        config(['exment.use_cache' => false]);
        System::clearRequestSession();
        parent::tearDown();
    }

    /** a value read inside a transaction that is rolled back must not be served to a later request */
    public function testValueReadInATransactionThatIsRolledBackIsNotKept(): void
    {
        DB::beginTransaction();
        System::cache('probe_rollback', function () {
            return 'uncommitted';
        });
        DB::rollBack();
        System::clearRequestSession(); // a later request
        $this->assertSame('fresh', System::cache('probe_rollback', function () {
            return 'fresh';
        }), 'the uncommitted value was kept in the store');
    }

    /** a value read outside a transaction is kept for the next request (the cache works at all) */
    public function testValueReadOutsideATransactionIsKept(): void
    {
        System::cache('probe_kept', function () {
            return 'one';
        });
        System::clearRequestSession();
        $this->assertSame('one', System::cache('probe_kept', function () {
            return 'two';
        }));
    }

    /** a saved custom view must be visible to the next request (persistent cache of the view list) */
    public function testSavedCustomViewIsSeenByTheNextRequest(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $before = CustomView::allRecordsCache()->count(); // fills the store
        System::clearRequestSession();
        $view = CustomView::create(['custom_table_id' => $table->id, 'view_view_name' => 'probe-view', 'view_type' => ViewType::SYSTEM, 'view_kind_type' => ViewKindType::DEFAULT]);
        System::clearRequestSession(); // the next request
        try {
            $this->assertSame($before + 1, CustomView::allRecordsCache()->count(), 'the view list in the store is stale');
        } finally {
            $view->delete();
            System::clearCache();
        }
    }
}
