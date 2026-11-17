<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DashboardBoxItems\SystemItems\News;
use Exceedone\Exment\Model\Define;
use PHPUnit\Framework\TestCase;

/**
 * The news of the dashboard come from a static file with every post (exment.net/api/posts.json),
 * so Exment picks the category, the order and the page itself.
 */
class DashboardNewsItemsTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function posts(): array
    {
        return [
            ['id' => 1, 'date' => '2025-09-02T12:33:00', 'categories' => [6], 'title' => ['rendered' => 'old news']],
            ['id' => 2, 'date' => '2026-11-17T15:00:00', 'categories' => [6], 'title' => ['rendered' => 'v7.0.1']],
            ['id' => 3, 'date' => '2026-10-22T11:03:28', 'categories' => [6], 'title' => ['rendered' => 'php']],
            ['id' => 4, 'date' => '2026-11-01T00:00:00', 'categories' => [3], 'title' => ['rendered' => 'model case']],
            'not a post',
            ['id' => 5, 'title' => ['rendered' => 'no date']],
        ];
    }

    public function testTheApiIsTheStaticFile(): void
    {
        $this->assertSame('https://exment.net/api/posts.json', Define::EXMENT_NEWS_API_URL);
    }

    public function testOnlyTheNewsCategoryNewestFirst(): void
    {
        $items = News::filterItems($this->posts(), 10);
        $this->assertSame([2, 3, 1], array_column($items, 'id'));
    }

    public function testThePageSize(): void
    {
        $this->assertSame([2, 3], array_column(News::filterItems($this->posts(), 2), 'id'));
        $this->assertSame([1], array_column(News::filterItems($this->posts(), 2, 2), 'id'));
        $this->assertSame([], News::filterItems($this->posts(), 2, 3));
    }

    public function testNoCategoryFilter(): void
    {
        $this->assertSame([2, 4, 3, 1], array_column(News::filterItems($this->posts(), 10, 1, null), 'id'));
    }

    public function testBadValues(): void
    {
        $this->assertSame([2], array_column(News::filterItems($this->posts(), 0, 0), 'id'));
        $this->assertSame([], News::filterItems([], 5));
    }
}
