<?php

namespace App\Plugins\TestPluginCrud;

use Exceedone\Exment\Enums\PluginCrudAuthType;
use Exceedone\Exment\Services\Plugin\PluginCrudBase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * CRUD plugin of the test data (tests/Feature/PluginCrudTest.php). Its rows stand in for an
 * external data source and are kept in the cache under CACHE_KEY, so a test starts from the
 * two default rows once it forgets the key. It asks for an OAuth login only when a login
 * setting is linked to it (plugin option crud_auth_oauth).
 */
class Plugin extends PluginCrudBase
{
    public const CACHE_KEY = 'test_plugin_crud_rows';

    protected const DEFAULT_ROWS = [
        1 => ['id' => '1', 'name' => 'crud row 1'],
        2 => ['id' => '2', 'name' => 'crud row 2'],
    ];

    /**
     * @var string
     */
    protected $title = 'TestPluginCrud';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFieldDefinitions()
    {
        return [
            ['key' => 'id', 'label' => 'ID', 'primary' => true, 'grid' => 1, 'show' => 1, 'edit' => 1],
            ['key' => 'name', 'label' => 'Name', 'grid' => 2, 'show' => 2, 'create' => 1, 'edit' => 2],
        ];
    }

    /**
     * @param array<mixed> $options
     * @return LengthAwarePaginator<int, array<string, string>>
     */
    public function getPaginate(array $options = []): ?LengthAwarePaginator
    {
        $rows = $this->getList($options);
        $page = (int) ($options['page'] ?? 1);

        return new LengthAwarePaginator($rows->forPage($page, 20)->values(), $rows->count(), 20, $page);
    }

    /**
     * @param array<mixed> $options
     * @return Collection<int, array<string, string>>
     */
    public function getList(array $options = []): Collection
    {
        return collect($this->rows())->values();
    }

    /**
     * @param mixed $id
     * @param array<mixed> $options
     * @return array<string, string>
     */
    public function getData($id, array $options = [])
    {
        return $this->rows()[(int) $id] ?? [];
    }

    /**
     * @param array<mixed> $posts
     * @param array<mixed> $options
     * @return string the id of the new row
     */
    public function postCreate(array $posts, array $options = [])
    {
        $rows = $this->rows();
        $id = 1;
        foreach (array_keys($rows) as $key) {
            $id = max($id, $key + 1);
        }
        $rows[$id] = ['id' => (string) $id, 'name' => (string) ($posts['name'] ?? null)];
        $this->saveRows($rows);

        return (string) $id;
    }

    /**
     * @param mixed $id
     * @param array<mixed> $posts
     * @param array<mixed> $options
     * @return string
     */
    public function putEdit($id, array $posts, array $options = [])
    {
        $rows = $this->rows();
        $rows[(int) $id]['name'] = (string) ($posts['name'] ?? null);
        $this->saveRows($rows);

        return (string) $id;
    }

    /**
     * @param mixed $id
     * @param array<mixed> $options
     * @return bool
     */
    public function delete($id, array $options = [])
    {
        $rows = $this->rows();
        unset($rows[(int) $id]);
        $this->saveRows($rows);

        return true;
    }

    public function getAuthType(): ?string
    {
        return is_nullorempty($this->plugin->getOption('crud_auth_oauth')) ? null : PluginCrudAuthType::OAUTH;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function rows(): array
    {
        /** @var array<int, array<string, string>> $rows */
        $rows = Cache::get(static::CACHE_KEY, static::DEFAULT_ROWS);

        return $rows;
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    protected function saveRows(array $rows): void
    {
        Cache::forever(static::CACHE_KEY, $rows);
    }
}
