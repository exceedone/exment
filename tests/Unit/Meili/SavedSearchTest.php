<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Model\MeiliSavedSearch;
use Exceedone\Exment\Services\Meili\SavedSearchService;
use PHPUnit\Framework\TestCase;

/**
 * Saved Search: whitelist the filter on save, sanitize against metadata on apply
 * (deleted column/table won't break it), and visibility rules by share scope.
 */
class SavedSearchTest extends TestCase
{
    // ---- filtersFromInput (whitelist on save) ----

    public function testFiltersFromInputWhitelistsAndDropsJunk(): void
    {
        $out = SavedSearchService::filtersFromInput([
            'tables' => ['a', '', 'b'],
            'users' => [1, 2],
            'facets' => ['status=open'],
            'date_from' => '2026-01-01',
            'date_to' => '',
            'range' => ['n_price' => ['from' => '10', 'to' => ''], 'bad' => 'x'],
            'query' => 'keyword',      // not part of filters
            'page' => 3,               // dropped
            '_token' => 'abc',         // dropped
        ]);

        $this->assertSame(['a', 'b'], $out['tables']);
        $this->assertSame(['1', '2'], $out['users']);
        $this->assertSame(['status=open'], $out['facets']);
        $this->assertSame('2026-01-01', $out['date_from']);
        $this->assertArrayNotHasKey('date_to', $out);
        $this->assertSame(['from' => '10'], $out['range']['n_price']);
        $this->assertArrayNotHasKey('bad', $out['range']);
        $this->assertArrayNotHasKey('query', $out);
        $this->assertArrayNotHasKey('page', $out);
        $this->assertArrayNotHasKey('_token', $out);
    }

    // ---- sanitizeWith (re-apply against current metadata) ----

    private function ctx(): array
    {
        return [
            'tables' => ['contract', 'customer'],
            'facet_columns' => ['status'],
            'range_fields' => ['n_price'],
            'user_ids' => [1, 2],
        ];
    }

    public function testSanitizeKeepsValidFilters(): void
    {
        $stored = [
            'tables' => ['contract'],
            'date_from' => '2026-01-01',
            'users' => ['2'],
            'facets' => ['status=open'],
            'range' => ['n_price' => ['from' => '10', 'to' => '99']],
        ];

        $out = SavedSearchService::sanitizeWith($stored, $this->ctx());

        $this->assertSame([], $out['dropped']);
        $this->assertSame(['contract'], $out['params']['tables']);
        $this->assertSame('2026-01-01', $out['params']['date_from']);
        $this->assertSame([2], $out['params']['users']);
        $this->assertSame(['status=open'], $out['params']['facets']);
        $this->assertSame(['from' => '10', 'to' => '99'], $out['params']['range']['n_price']);
    }

    public function testSanitizeDropsDeletedTableColumnUserRange(): void
    {
        $stored = [
            'tables' => ['contract', 'deleted_table'],
            'users' => [1, 999],
            'facets' => ['status=open', 'renamed_col=x'],
            'range' => ['n_price' => ['from' => '1'], 'n_gone' => ['to' => '9']],
            'date_from' => 'not-a-date',
        ];

        $out = SavedSearchService::sanitizeWith($stored, $this->ctx());

        $this->assertSame(['contract'], $out['params']['tables']);
        $this->assertSame([1], $out['params']['users']);
        $this->assertSame(['status=open'], $out['params']['facets']);
        $this->assertArrayHasKey('n_price', $out['params']['range']);
        $this->assertArrayNotHasKey('n_gone', $out['params']['range']);
        $this->assertArrayNotHasKey('date_from', $out['params']);
        $this->assertEqualsCanonicalizing(
            ['table:deleted_table', 'user:999', 'facet:renamed_col=x', 'range:n_gone', 'date_from:not-a-date'],
            $out['dropped']
        );
    }

    public function testSanitizeEmptyStored(): void
    {
        $out = SavedSearchService::sanitizeWith([], $this->ctx());

        $this->assertSame([], $out['params']);
        $this->assertSame([], $out['dropped']);
    }

    // ---- visibleToUser (share rules) ----

    private function record(array $attrs): object
    {
        return (object) ($attrs + ['owner_user_id' => 10, 'share_targets' => []]);
    }

    public function testOwnerAlwaysSees(): void
    {
        $r = $this->record(['share_type' => MeiliSavedSearch::SHARE_PERSONAL]);

        $this->assertTrue(MeiliSavedSearch::visibleToUser($r, 10, [], []));
        $this->assertFalse(MeiliSavedSearch::visibleToUser($r, 11, [], []));
    }

    public function testShareAll(): void
    {
        $r = $this->record(['share_type' => MeiliSavedSearch::SHARE_ALL]);

        $this->assertTrue(MeiliSavedSearch::visibleToUser($r, 999, [], []));
    }

    public function testShareRoleGroupRequiresMembership(): void
    {
        $r = $this->record(['share_type' => MeiliSavedSearch::SHARE_ROLE_GROUP, 'share_targets' => [5, 6]]);

        $this->assertTrue(MeiliSavedSearch::visibleToUser($r, 999, [6], []));
        $this->assertFalse(MeiliSavedSearch::visibleToUser($r, 999, [7], []));
    }

    public function testShareOrganizationRequiresMembership(): void
    {
        $r = $this->record(['share_type' => MeiliSavedSearch::SHARE_ORGANIZATION, 'share_targets' => [3]]);

        $this->assertTrue(MeiliSavedSearch::visibleToUser($r, 999, [], [3]));
        $this->assertFalse(MeiliSavedSearch::visibleToUser($r, 999, [], [4]));
    }

    public function testUnknownShareTypeIsPrivate(): void
    {
        $r = $this->record(['share_type' => 'weird']);

        $this->assertFalse(MeiliSavedSearch::visibleToUser($r, 999, [1], [1]));
    }
}
