<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * ExtendedBuilder::paginate() builds an "id only" sub query (clone + select(id as sid))
 * and inlines the bindings into it. The bindings must be the ones of the clone:
 * select() removes the "select" bindings, so a query having select bindings
 * (withCount / selectRaw with "?" / selectSub ...) combined with a where binding
 * returned wrong (usually empty) pages while total was correct.
 */
class ExtendedBuilderPaginateTest extends UnitTestBase
{
    use DatabaseTransactions;

    /**
     * @var string
     */
    protected $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'pgtest_' . short_uuid() . '_';
        foreach (range(1, 3) as $i) {
            RoleGroup::create([
                'role_group_name' => $this->prefix . $i,
                'role_group_view_name' => $this->prefix . $i,
                'role_group_order' => $i,
            ]);
        }
    }

    /**
     * withCount (select bindings) + where (where binding)
     */
    public function testPaginateWithCountAndWhere(): void
    {
        $paginator = RoleGroup::withCount([
            'role_group_users as users_count' => function ($query) {
                $query->where('role_group_user_org_type', SystemTableName::USER);
            },
        ])
            ->where('role_group_name', 'like', $this->prefix . '%')
            ->orderBy('role_group_order')
            ->paginate(2);

        $this->assertSame(3, $paginator->total());
        $this->assertCount(2, $paginator->items(), 'page 1 must contain perPage items (was empty when bindings were shifted)');

        $names = collect($paginator->items())->pluck('role_group_name')->all();
        $this->assertSame([$this->prefix . '1', $this->prefix . '2'], $names);

        foreach ($paginator->items() as $item) {
            $this->assertSame(0, (int) $item->users_count);
        }
    }

    /**
     * selectRaw with "?" (select binding) + where (where binding)
     */
    public function testPaginateSelectRawBindingAndWhere(): void
    {
        $paginator = RoleGroup::selectRaw('role_groups.*, ? as marker', ['mk'])
            ->where('role_group_name', 'like', $this->prefix . '%')
            ->orderBy('role_group_order')
            ->paginate(2, ['*'], 'page', 2);

        $this->assertSame(3, $paginator->total());
        $this->assertCount(1, $paginator->items());
        $this->assertSame($this->prefix . '3', $paginator->items()[0]->role_group_name);
        $this->assertSame('mk', $paginator->items()[0]->marker);
    }

    /**
     * withCount + where, 2nd page.
     */
    public function testPaginateWithCountSecondPage(): void
    {
        $paginator = RoleGroup::withCount([
            'role_group_users as users_count' => function ($query) {
                $query->where('role_group_user_org_type', SystemTableName::USER);
            },
        ])
            ->where('role_group_name', 'like', $this->prefix . '%')
            ->orderBy('role_group_order')
            ->paginate(2, ['*'], 'page', 2);

        $this->assertSame(3, $paginator->total());
        $this->assertCount(1, $paginator->items());
        $this->assertSame($this->prefix . '3', $paginator->items()[0]->role_group_name);
        $this->assertSame(0, (int) $paginator->items()[0]->users_count);
    }

    /**
     * Query without any binding (count($bindings) == 0 branch) with select bindings only.
     */
    public function testPaginateWithCountWithoutWhereBinding(): void
    {
        $paginator = RoleGroup::withCount([
            'role_group_users as users_count' => function ($query) {
                $query->where('role_group_user_org_type', SystemTableName::USER);
            },
        ])
            ->whereRaw('1 = 1')
            ->orderBy('id')
            ->paginate(2);

        $this->assertGreaterThanOrEqual(3, $paginator->total());
        $this->assertCount(2, $paginator->items());
        foreach ($paginator->items() as $item) {
            $this->assertIsNumeric($item->users_count);
        }
    }

    /**
     * groupBy query takes the plain forPage()->get() branch (unchanged).
     */
    public function testPaginateWithGroupBy(): void
    {
        $paginator = RoleGroup::selectRaw('role_groups.*, ? as marker', ['mk'])
            ->where('role_group_name', 'like', $this->prefix . '%')
            ->groupBy('role_groups.id')
            ->orderBy('role_group_order')
            ->paginate(2);

        $this->assertSame(3, $paginator->total());
        $this->assertCount(2, $paginator->items());
        $this->assertSame('mk', $paginator->items()[0]->marker);
    }

    /**
     * Regression guard: a plain query (no select bindings) still paginates the same.
     */
    public function testPaginatePlainQuery(): void
    {
        $paginator = RoleGroup::where('role_group_name', 'like', $this->prefix . '%')
            ->orderBy('role_group_order')
            ->paginate(2);

        $this->assertSame(3, $paginator->total());
        $this->assertCount(2, $paginator->items());
        $this->assertSame(
            [$this->prefix . '1', $this->prefix . '2'],
            collect($paginator->items())->pluck('role_group_name')->all()
        );
    }
}
