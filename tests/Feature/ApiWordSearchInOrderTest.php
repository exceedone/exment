<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The search by words of the data API (api/data/{table}/query) reads its pages in one order:
 * every match once, the id going up, the same records as the search found before (when each
 * column has fewer matches than keyword_search_count), ISS02907.
 */
class ApiWordSearchInOrderTest extends ApiTestBase
{
    public function testPagesHaveEachMatchOnce(): void
    {
        foreach (['index', 'index_002'] as $q) {
            $ids = $this->allPages('custom_value_access_all', $q, 7);
            $this->assertGreaterThan(0, count($ids), $q);

            // each once, the id going up
            $sorted = $ids;
            sort($sorted);
            $this->assertSame($sorted, $ids, "$q: in the order of the id");
            $this->assertSame(count(array_unique($ids)), count($ids), "$q: no record twice");

            // the same records as the search of searchValue (every match: fewer than its limit)
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
            $before = [];
            foreach (CustomTable::getEloquent('custom_value_access_all')->searchValue($q, ['maxCount' => 100000]) ?? [] as $value) {
                $before[] = intval($value->id);
            }
            sort($before);
            $this->assertSame($before, $ids, "$q: the records the search found before");
        }
    }

    /**
     * Not stopped at keyword_search_count: the search read at most that many matches of each
     * column (in no order), so a page past it was empty or repeated others. With a small limit
     * the pages still have every match, each once (this fails without the fix).
     */
    public function testNotStoppedAtTheKeywordSearchCount(): void
    {
        $all = $this->allPages('custom_value_access_all', 'index', 20);
        $this->assertGreaterThan(10, count($all));

        config(['exment.keyword_search_count' => 5]);
        $limited = $this->allPages('custom_value_access_all', 'index', 3);
        $this->assertSame($all, $limited);
    }

    public function testTheAnswerKeepsItsForm(): void
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);
        \Auth::forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->get(admin_urls('api', 'data', 'custom_value_access_all', 'query') . '?q=index_002')
            ->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonStructure(['current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total']);
    }

    /**
     * The ids of all pages of a search, in the order they come.
     *
     * @return array<int, int>
     */
    protected function allPages(string $table, string $q, int $count): array
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);
        $ids = [];
        for ($page = 1; $page <= 200; $page++) {
            \Auth::forgetGuards();
            $json = $this->withHeaders(['Authorization' => "Bearer $token"])
                ->get(admin_urls('api', 'data', $table, 'query') . '?' . http_build_query(['q' => $q, 'count' => $count, 'page' => $page]))
                ->assertStatus(200)
                ->json();
            foreach ((array)($json['data'] ?? []) as $row) {
                $ids[] = intval($row['id']);
            }
            if (!isset($json['next_page_url'])) {
                break;
            }
        }
        return $ids;
    }
}
