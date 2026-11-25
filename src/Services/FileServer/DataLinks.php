<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\FileDataLink;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileWorkspace;
use Illuminate\Support\Collection;

/**
 * File server, phase 4 (ISS00378): folders and files linked to the data of
 * Exment (a customer, a project, a contract...).
 *
 * Those who can edit a folder or file, and see the data, make a link. The
 * file shows its data (only those the user may see); the screen of the data
 * shows its files (only those the user may see).
 */
class DataLinks
{
    protected Library $library;

    public function __construct(Library $library)
    {
        $this->library = $library;
    }

    /**
     * The tables whose data a user may link to.
     *
     * @return Collection<int, CustomTable>
     */
    public static function tables(): Collection
    {
        if (!isset(\Exment::user()->base_user)) {
            return collect();
        }
        /** @var Collection<int, CustomTable> $tables */
        $tables = CustomTable::filterList(null, ['permissions' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE]);
        return $tables->values();
    }

    /**
     * The data of a table that match the words (for the picker).
     *
     * @return array<int, array{id: int, label: string}>
     */
    public static function lookup(string $tableName, string $q, int $limit = 20): array
    {
        $table = CustomTable::getEloquent($tableName);
        if (!isset($table) || !$table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
            throw FileServerException::forbidden();
        }
        $q = trim($q);
        if ($q !== '' && ctype_digit($q)) {
            $value = $table->getValueModel(intval($q));
            if (isset($value) && $table->hasPermissionData($value) === true) {
                return [['id' => intval($value->id), 'label' => static::label($value)]];
            }
        }
        if ($q === '') {
            $values = $table->getValueModel()->query()->orderByDesc('id')->limit($limit)->get();
        } else {
            $values = $table->searchValue($q, ['maxCount' => $limit]) ?? collect();
        }
        $rows = [];
        foreach ($values as $value) {
            if ($value instanceof CustomValue) {
                $rows[] = ['id' => intval($value->id), 'label' => static::label($value)];
            }
        }
        return $rows;
    }

    public static function label(CustomValue $value): string
    {
        $label = strval($value->getLabel());
        return $label !== '' ? $label : ('#' . $value->id);
    }

    /**
     * Link a folder or file to a data.
     */
    public function add(FileWorkspace $workspace, FileNode $node, string $tableName, int $dataId): FileDataLink
    {
        if ($node->workspace_id !== $workspace->id || $node->isTrashed()) {
            throw FileServerException::notFound();
        }
        $this->library->access()->authorize($workspace, $node, Level::EDIT);
        $table = CustomTable::getEloquent($tableName);
        if (!isset($table)) {
            throw FileServerException::notFound();
        }
        $value = $table->getValueModel($dataId);
        if (!isset($value) || $table->hasPermissionData($value) !== true) {
            throw new FileServerException(exmtrans('file_server.error_data_not_found'), 404);
        }
        $link = FileDataLink::query()->where('node_id', $node->id)->where('table_name', $table->table_name)->where('data_id', $dataId)->first();
        if (isset($link)) {
            return $link;
        }
        $link = new FileDataLink([
            'workspace_id' => $workspace->id,
            'node_id' => $node->id,
            'table_name' => $table->table_name,
            'data_id' => $dataId,
        ]);
        $link->save();
        FileSearch::sync([$node->id]);
        return $link;
    }

    public function remove(FileWorkspace $workspace, FileNode $node, FileDataLink $link): void
    {
        if ($link->node_id !== $node->id || $node->workspace_id !== $workspace->id) {
            throw FileServerException::notFound();
        }
        $this->library->access()->authorize($workspace, $node, Level::EDIT);
        $link->delete();
        FileSearch::sync([$node->id]);
    }

    /**
     * The data of a folder or file that the user may see.
     *
     * @return array<int, array{id: int, table_name: string, table_label: string, data_id: int, label: string, url: string}>
     */
    public static function dataOf(FileNode $node): array
    {
        $rows = [];
        foreach (FileDataLink::query()->where('node_id', $node->id)->orderBy('id')->get() as $link) {
            $table = CustomTable::getEloquent($link->table_name);
            if (!isset($table) || !$table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                continue;
            }
            $value = $table->getValueModel($link->data_id);
            if (!isset($value) || $table->hasPermissionData($value) !== true) {
                continue;
            }
            $rows[] = [
                'id' => $link->id,
                'table_name' => $table->table_name,
                'table_label' => strval($table->table_view_name),
                'data_id' => $link->data_id,
                'label' => static::label($value),
                'url' => strval($value->getUrl()),
            ];
        }
        return $rows;
    }

    /**
     * The folders and files linked to a data that the user may see (the
     * screen of the data).
     *
     * @return Collection<int, FileNode>
     */
    public function nodesOf(string $tableName, int $dataId): Collection
    {
        $access = $this->library->access();
        $ids = FileDataLink::query()->where('table_name', $tableName)->where('data_id', $dataId)->pluck('node_id')->all();
        if (count($ids) === 0) {
            return collect();
        }
        return FileNode::query()->whereIn('id', $ids)->whereNull('trashed_at')->orderBy('name')->get()
            ->filter(function (FileNode $node) use ($access) {
                $workspace = $node->workspace;
                return isset($workspace) && $access->can($workspace, $node, Level::VIEW);
            })->values();
    }
}
