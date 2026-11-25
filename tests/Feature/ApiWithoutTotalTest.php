<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Tests\TestDefine;

/**
 * A list read with total=0 (no COUNT of the whole table, ISS02885) gives the same pages as
 * without it: the same records in the same order, the same page links; only the total, the
 * last page and its link are not there.
 */
class ApiWithoutTotalTest extends ApiTestBase
{
    /** the keys a list without the total does not have */
    protected const TOTAL_KEYS = ['total', 'last_page', 'last_page_url', 'links'];

    public function testDataList(): void
    {
        $this->assertSamePages(admin_urls('api', 'data', 'custom_value_access_all'), ['count' => 7]);
    }

    public function testDataListOrdered(): void
    {
        $this->assertSamePages(admin_urls('api', 'data', 'custom_value_access_all'), ['count' => 9, 'orderby' => 'id desc']);
    }

    public function testDataListAsUser(): void
    {
        // the records a user may see: the permissions filter both the same
        $token = $this->getUser1AccessToken([ApiScope::VALUE_READ]);
        $this->assertSamePages(admin_urls('api', 'data', 'custom_value_edit'), ['count' => 6], $token);
    }

    public function testDataQuery(): void
    {
        $this->assertSamePages(admin_urls('api', 'data', 'custom_value_access_all', 'query'), ['q' => 'index', 'count' => 8]);
    }

    public function testViewData(): void
    {
        $custom_view = CustomView::where('view_view_name', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL . '-view-all')->first();
        $this->assertNotNull($custom_view);
        $this->assertSamePages(admin_urls('api', 'viewdata', TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL, $custom_view->suuid), ['count' => 5]);
    }

    public function testWithTotalIsAsBefore(): void
    {
        // total=1, or no total: as it always was
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);
        foreach (['', '&total=1'] as $param) {
            $json = $this->getJsonOf(admin_urls('api', 'data', 'custom_value_access_all') . '?count=5' . $param, $token);
            $this->assertArrayHasKey('total', $json);
            $this->assertArrayHasKey('last_page', $json);
        }
    }

    /**
     * Every page with and without total=0 is the same, but for the total.
     *
     * @param array<string, string|int> $params
     */
    protected function assertSamePages(string $url, array $params, ?string $token = null): void
    {
        $token = $token ?? $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $pages = 0;
        for ($page = 1; $page <= 100; $page++) {
            $with = $this->getJsonOf($url . '?' . http_build_query($params + ['page' => $page]), $token);
            $without = $this->getJsonOf($url . '?' . http_build_query($params + ['page' => $page, 'total' => 0]), $token);

            foreach (static::TOTAL_KEYS as $key) {
                $this->assertArrayNotHasKey($key, $without, "page $page: $key");
            }
            $lastPage = intval($with['last_page']);

            foreach ($with as $key => $value) {
                if (in_array($key, static::TOTAL_KEYS, true)) {
                    continue;
                }
                $this->assertArrayHasKey($key, $without, "page $page: $key");
                if (str_ends_with((string)$key, '_url') || $key === 'path') {
                    $this->assertSame($this->withoutTotalParam($value), $this->withoutTotalParam($without[$key]), "page $page: $key");
                } else {
                    $this->assertSame($value, $without[$key], "page $page: $key");
                }
            }
            // the page links go on without the total
            if (isset($without['next_page_url'])) {
                $this->assertStringContainsString('total=0', $without['next_page_url']);
            }

            $pages++;
            if ($page >= $lastPage) {
                $this->assertNull($without['next_page_url'], 'the last page has no next');
                break;
            }
        }
        $this->assertGreaterThan(1, $pages, 'more than one page is compared');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getJsonOf(string $url, string $token): array
    {
        \Auth::forgetGuards();
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->get($url)->assertStatus(200);
        $json = json_decode((string)$response->getContent(), true);
        $this->assertIsArray($json);
        return $json;
    }

    /**
     * A page link without total=0 (the list without the total carries it on).
     *
     * @param mixed $url
     * @return mixed
     */
    protected function withoutTotalParam($url)
    {
        if (!is_string($url)) {
            return $url;
        }
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        unset($query['total']);
        ksort($query);
        return ($parts['path'] ?? '') . '?' . http_build_query($query);
    }
}
