<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use ReflectionMethod;
use Exceedone\Exment\Controllers\PluginMarketController;

class PluginMarketFilterTest extends TestCase
{
    /**
     * Invoke the protected PluginMarketController::filterPlugins() with the given keyword.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterByKeyword(iterable $plugins, string $keyword): array
    {
        $controller = (new \ReflectionClass(PluginMarketController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PluginMarketController::class, 'filterPlugins');
        $method->setAccessible(true);

        return $method->invoke($controller, collect($plugins), $keyword, null, null, null);
    }

    private function pluginNames(array $plugins): array
    {
        return array_map(fn ($p) => $p['plugin_name'], $plugins);
    }

    private function samplePlugins(): array
    {
        return [
            // user.name differs from author: the 作成者 column shows user.name.
            [
                'plugin_name' => 'plugin_a',
                'plugin_view_name' => 'Plugin A',
                'description' => 'desc a',
                'user' => ['name' => '山田太郎'],
                'author' => 'yamada_t',
            ],
            // author is an empty string: the column falls back to user.name.
            [
                'plugin_name' => 'plugin_b',
                'plugin_view_name' => 'Plugin B',
                'description' => 'desc b',
                'user' => ['name' => '田中'],
                'author' => '',
            ],
            // No user key at all: the column shows the author string.
            [
                'plugin_name' => 'plugin_c',
                'plugin_view_name' => 'Plugin C',
                'description' => 'desc c',
                'author' => 'Foo',
            ],
        ];
    }

    public function testKeywordMatchesDisplayedUserNameWhenAuthorDiffers(): void
    {
        // Regression for the customer report: the 作成者 column shows user.name,
        // so searching that displayed name must return the plugin.
        $result = $this->filterByKeyword($this->samplePlugins(), '山田太郎');

        $this->assertSame(['plugin_a'], $this->pluginNames($result));
    }

    public function testKeywordMatchesUserNameWhenAuthorIsEmptyString(): void
    {
        $result = $this->filterByKeyword($this->samplePlugins(), '田中');

        $this->assertSame(['plugin_b'], $this->pluginNames($result));
    }

    public function testKeywordStillMatchesAuthorStringWhenNoUser(): void
    {
        // No regression: the author field remains searchable on its own.
        $result = $this->filterByKeyword($this->samplePlugins(), 'Foo');

        $this->assertSame(['plugin_c'], $this->pluginNames($result));
    }

    public function testKeywordStillMatchesAuthorFieldWhenUserNameAlsoPresent(): void
    {
        // No regression: searching the raw author value still matches plugin_a.
        $result = $this->filterByKeyword($this->samplePlugins(), 'yamada_t');

        $this->assertSame(['plugin_a'], $this->pluginNames($result));
    }

    public function testKeywordStillMatchesPluginNameAndDescription(): void
    {
        // No regression on the other search branches.
        $byName = $this->filterByKeyword($this->samplePlugins(), 'plugin_c');
        $this->assertSame(['plugin_c'], $this->pluginNames($byName));

        $byDescription = $this->filterByKeyword($this->samplePlugins(), 'desc b');
        $this->assertSame(['plugin_b'], $this->pluginNames($byDescription));
    }
}
