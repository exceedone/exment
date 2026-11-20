<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Jobs\FileServerTextJob;
use Exceedone\Exment\Model\FileMetadataField;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileNodeText;
use Exceedone\Exment\Model\FileNodeVersion;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Illuminate\Support\Collection;

/**
 * File server, phase 4 (ISS00378): the search of the folders and files by
 * their name, the values of their columns and the text in them.
 *
 * Without more set up, the search runs in the database (LIKE). With
 * exment.file_server_meili = true, the folders and files are kept in an index
 * of Meilisearch (exment.file_server_meili_index; the host of
 * config/meilisearch.php) and searched there; the database is used when it
 * cannot be reached. Either way, only the folders and files the user may see
 * are shown: the workspaces of the user narrow the search, and each hit is
 * checked again.
 */
class FileSearch
{
    public const SNIPPET_LENGTH = 160;

    protected Library $library;

    public function __construct(Library $library)
    {
        $this->library = $library;
    }

    // ---------------------------------------------------------------- the text of the files

    /**
     * Take the text of a version in the queue (or now, with the sync queue).
     */
    public static function queueText(FileNodeVersion $version): void
    {
        $node = $version->node;
        if (!isset($node)) {
            return;
        }
        if (TextExtractor::method($node->name) === null) {
            static::putText($node, $version, null, FileNodeText::STATUS_NONE, null);
            return;
        }
        try {
            FileServerTextJob::dispatch($version->id);
        } catch (\Throwable $e) {
            \Log::warning('[FileServer] could not queue the text of version ' . $version->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Take the text of a version (the job). Only for the current version: an
     * older one that waited in the queue does nothing.
     */
    public static function makeText(FileNodeVersion $version): void
    {
        $node = $version->node;
        if (!isset($node) || $node->current_version_id !== $version->id) {
            return;
        }
        $library = ShareLinks::systemLibrary();
        $local = null;
        try {
            $local = $library->downloadToLocal($version);
            $result = TextExtractor::extract($local, $node->name);
            if ($result === null) {
                static::putText($node, $version, null, FileNodeText::STATUS_NONE, null);
            } else {
                static::putText($node, $version, $result[0], FileNodeText::STATUS_DONE, $result[1]);
            }
        } catch (\Throwable $e) {
            \Log::warning('[FileServer] could not take the text of version ' . $version->id . ': ' . $e->getMessage());
            static::putText($node, $version, null, FileNodeText::STATUS_FAILED, null);
        } finally {
            if (isset($local)) {
                @unlink($local);
            }
        }
    }

    protected static function putText(FileNode $node, FileNodeVersion $version, ?string $text, string $status, ?string $method): void
    {
        $row = FileNodeText::query()->where('node_id', $node->id)->first() ?? new FileNodeText(['node_id' => $node->id]);
        $row->version_id = $version->id;
        $row->content = $text;
        $row->status = $status;
        $row->method = $method;
        $row->save();
        static::sync([$node->id]);
    }

    // ---------------------------------------------------------------- Meilisearch

    public static function meiliEnabled(): bool
    {
        return boolval(config('exment.file_server_meili', false));
    }

    public static function indexName(): string
    {
        return strval(config('exment.file_server_meili_index', 'exment_files')) ?: 'exment_files';
    }

    /**
     * Put the folders and files into the index again (or take them away: in
     * the trash, removed). Nothing without Meilisearch.
     *
     * @param int[] $nodeIds
     */
    public static function sync(array $nodeIds): void
    {
        if (!static::meiliEnabled() || count($nodeIds) === 0) {
            return;
        }
        try {
            $index = MeiliClientFactory::make()->index(static::indexName());
            $nodes = FileNode::query()->whereIn('id', $nodeIds)->get()->keyBy('id');
            $documents = [];
            $remove = [];
            foreach ($nodeIds as $id) {
                $node = $nodes->get($id);
                if (!isset($node) || $node->isTrashed()) {
                    $remove[] = intval($id);
                    continue;
                }
                $documents[] = static::document($node);
            }
            if (count($documents) > 0) {
                $index->addDocuments($documents, 'id');
            }
            if (count($remove) > 0) {
                $index->deleteDocuments($remove);
            }
        } catch (\Throwable $e) {
            \Log::warning('[FileServer] could not update the search index: ' . $e->getMessage());
        }
    }

    /**
     * The settings of the index (the command of the reindex runs it).
     */
    public static function setUpIndex(): void
    {
        $client = MeiliClientFactory::make();
        try {
            $client->createIndex(static::indexName(), ['primaryKey' => 'id']);
        } catch (\Throwable $e) {
            // it is there
        }
        $index = $client->index(static::indexName());
        $index->updateSearchableAttributes(['name', 'metadata', 'content']);
        $index->updateFilterableAttributes(['workspace_id', 'node_type']);
        $index->updateSortableAttributes(['updated_at']);
        $locales = (array)config('meilisearch.settings.locales', []);
        if (count($locales) > 0) {
            try {
                $index->updateLocalizedAttributes([['attributePatterns' => ['*'], 'locales' => array_values($locales)]]);
            } catch (\Throwable $e) {
                // an older Meilisearch
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(FileNode $node): array
    {
        $text = FileNodeText::query()->where('node_id', $node->id)->value('content');
        return [
            'id' => $node->id,
            'workspace_id' => $node->workspace_id,
            'node_type' => $node->node_type,
            'name' => $node->name,
            'metadata' => static::metadataText($node),
            'content' => is_string($text) ? $text : '',
            'updated_at' => isset($node->updated_at) ? $node->updated_at->getTimestamp() : 0,
        ];
    }

    protected static function metadataText(FileNode $node): string
    {
        $values = [];
        foreach ((array)($node->metadata ?? []) as $value) {
            if (is_bool($value)) {
                continue;
            }
            $values[] = is_array($value) ? implode(' ', array_map('strval', $value)) : strval($value);
        }
        return implode(' ', $values);
    }

    // ---------------------------------------------------------------- the search

    /**
     * Search the folders and files the user may see.
     *
     * @param int|null $workspaceId only in one workspace
     * @return array{rows: array<int, array{node: FileNode, snippet: string, in_content: bool}>, engine: string}
     */
    public function search(string $q, ?int $workspaceId = null, int $limit = 50): array
    {
        $q = trim(preg_replace('/[\s\x{3000}]+/u', ' ', $q) ?? '');
        if ($q === '') {
            return ['rows' => [], 'engine' => 'none'];
        }
        $access = $this->library->access();
        $workspaces = $access->workspaces()->keyBy('id');
        $sharedWorkspaceIds = $access->sharedNodes()->pluck('workspace_id')->all();
        $ids = array_values(array_unique(array_merge($workspaces->keys()->map('intval')->all(), array_map('intval', $sharedWorkspaceIds))));
        if (isset($workspaceId)) {
            $ids = in_array($workspaceId, $ids, true) ? [$workspaceId] : [];
        }
        if (count($ids) === 0) {
            return ['rows' => [], 'engine' => 'none'];
        }

        $engine = 'database';
        $candidates = null;
        if (static::meiliEnabled()) {
            $candidates = $this->searchMeili($q, $ids, $limit * 4);
            if (isset($candidates)) {
                $engine = 'meilisearch';
            }
        }
        if (!isset($candidates)) {
            $candidates = $this->searchDatabase($q, $ids, $limit * 4);
        }

        $rows = [];
        foreach ($candidates as $node) {
            $workspace = $node->workspace;
            if (!isset($workspace) || $node->isTrashed() || !$access->can($workspace, $node, Level::VIEW)) {
                continue;
            }
            [$snippet, $inContent] = $this->snippet($node, $q);
            $rows[] = ['node' => $node, 'snippet' => $snippet, 'in_content' => $inContent];
            if (count($rows) >= $limit) {
                break;
            }
        }
        return ['rows' => $rows, 'engine' => $engine];
    }

    /**
     * @param int[] $workspaceIds
     * @return Collection<int, FileNode>|null null: Meilisearch cannot be reached
     */
    protected function searchMeili(string $q, array $workspaceIds, int $limit): ?Collection
    {
        try {
            $result = MeiliClientFactory::make()->index(static::indexName())->search($q, [
                'filter' => 'workspace_id IN [' . implode(',', array_map('intval', $workspaceIds)) . ']',
                'limit' => $limit,
                'attributesToRetrieve' => ['id'],
            ]);
            $ids = array_map(function ($hit) {
                return intval($hit['id'] ?? 0);
            }, $result->getHits());
        } catch (\Throwable $e) {
            \Log::warning('[FileServer] the search of Meilisearch failed: ' . $e->getMessage());
            return null;
        }
        if (count($ids) === 0) {
            return collect();
        }
        $nodes = FileNode::query()->whereIn('id', $ids)->whereNull('trashed_at')->get()->keyBy('id');
        return collect($ids)->map(function ($id) use ($nodes) {
            return $nodes->get($id);
        })->filter()->values();
    }

    /**
     * Every word in the name, the values or the text (AND), the names first.
     *
     * @param int[] $workspaceIds
     * @return Collection<int, FileNode>
     */
    protected function searchDatabase(string $q, array $workspaceIds, int $limit): Collection
    {
        $words = array_slice(array_values(array_filter(explode(' ', $q), function ($w) {
            return $w !== '';
        })), 0, 10);
        $query = FileNode::query()
            ->leftJoin('file_node_texts', 'file_node_texts.node_id', '=', 'file_nodes.id')
            ->whereIn('file_nodes.workspace_id', $workspaceIds)
            ->whereNull('file_nodes.trashed_at')
            ->select('file_nodes.*');
        foreach ($words as $word) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%';
            $query->where(function ($query) use ($like) {
                $query->where('file_nodes.name', 'like', $like)
                    ->orWhere('file_nodes.metadata', 'like', $like)
                    ->orWhere('file_node_texts.content', 'like', $like);
            });
        }
        $first = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $words[0] ?? '') . '%';
        return $query->orderByRaw('CASE WHEN file_nodes.name LIKE ? THEN 0 ELSE 1 END', [$first])
            ->orderByDesc('file_nodes.updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Where the words are: a part of the text around the first one, or the
     * values of the columns.
     *
     * @return array{0: string, 1: bool}
     */
    protected function snippet(FileNode $node, string $q): array
    {
        $words = array_values(array_filter(explode(' ', $q), function ($w) {
            return $w !== '';
        }));
        $text = FileNodeText::query()->where('node_id', $node->id)->value('content');
        if (is_string($text) && $text !== '') {
            foreach ($words as $word) {
                $pos = mb_stripos($text, $word);
                if ($pos !== false) {
                    $start = max(0, $pos - intval(static::SNIPPET_LENGTH / 3));
                    $part = mb_substr($text, $start, static::SNIPPET_LENGTH);
                    $part = preg_replace('/\s+/u', ' ', $part) ?? $part;
                    return [($start > 0 ? '…' : '') . $part . (mb_strlen($text) > $start + static::SNIPPET_LENGTH ? '…' : ''), true];
                }
            }
        }
        $meta = static::metadataText($node);
        return [$meta !== '' ? mb_substr($meta, 0, static::SNIPPET_LENGTH) : '', false];
    }

    /**
     * Put all the folders and files into the index again; take the text of
     * the files without it.
     *
     * @return array{indexed: int, texts: int}
     */
    public static function rebuild(bool $texts = true, ?\Closure $progress = null): array
    {
        $indexed = 0;
        $made = 0;
        if (static::meiliEnabled()) {
            static::setUpIndex();
        }
        FileNode::query()->whereNull('trashed_at')->orderBy('id')->chunk(200, function ($nodes) use (&$indexed, &$made, $texts, $progress) {
            if ($texts) {
                foreach ($nodes as $node) {
                    /** @var FileNode $node */
                    if (!$node->isFile() || !isset($node->current_version_id)) {
                        continue;
                    }
                    $row = FileNodeText::query()->where('node_id', $node->id)->first();
                    if (isset($row) && $row->version_id === $node->current_version_id && $row->status !== FileNodeText::STATUS_FAILED) {
                        continue;
                    }
                    $version = FileNodeVersion::find($node->current_version_id);
                    if (isset($version)) {
                        static::makeText($version);
                        $made++;
                    }
                }
            }
            static::sync($nodes->pluck('id')->map('intval')->all());
            $indexed += count($nodes);
            if (isset($progress)) {
                $progress($indexed);
            }
        });
        return ['indexed' => $indexed, 'texts' => $made];
    }

    /**
     * The values of a node shown in a list: label => value (only the columns shown in a list).
     *
     * @param Collection<int, FileMetadataField> $fields
     * @return array<string, string>
     */
    public static function listValues(FileNode $node, Collection $fields): array
    {
        $values = (array)($node->metadata ?? []);
        $result = [];
        foreach ($fields as $field) {
            if ($field->show_in_list) {
                $result[$field->name] = Metadata::display($field, array_get($values, $field->name));
            }
        }
        return $result;
    }
}
