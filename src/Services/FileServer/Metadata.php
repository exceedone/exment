<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\FileMetadataField;
use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * File server, phase 4 (ISS00378): the columns of a workspace and their
 * values in each folder and file (the "columns" of a SharePoint library).
 *
 * The managers of the workspace make the columns; those who can edit a file
 * put its values. A value is kept in file_nodes.metadata (name => value), so
 * a column taken away leaves its values unused but not lost. The folders of
 * a folder can be narrowed, sorted and grouped by the values.
 */
class Metadata
{
    protected Library $library;

    public function __construct(Library $library)
    {
        $this->library = $library;
    }

    /**
     * @return Collection<int, FileMetadataField>
     */
    public static function fields(FileWorkspace $workspace): Collection
    {
        return FileMetadataField::query()->where('workspace_id', $workspace->id)->orderBy('order')->orderBy('id')->get();
    }

    /**
     * Put the columns of the workspace (all of them: those not in the list are
     * taken away).
     *
     * @param array<int, array<string, mixed>> $entries name, label, field_type, options, required, default_value, show_in_list
     * @return Collection<int, FileMetadataField>
     */
    public function saveFields(FileWorkspace $workspace, array $entries): Collection
    {
        $this->library->access()->authorize($workspace, null, Level::MANAGE);

        $rows = [];
        $names = [];
        foreach (array_values($entries) as $i => $entry) {
            $label = trim(strval(array_get($entry, 'label', '')));
            if ($label === '') {
                continue;
            }
            $name = strtolower(trim(strval(array_get($entry, 'name', ''))));
            if ($name === '') {
                $name = 'field_' . ($i + 1);
            }
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name) !== 1) {
                throw new FileServerException(exmtrans('file_server.error_field_name', ['name' => $name]));
            }
            if (in_array($name, $names, true)) {
                throw new FileServerException(exmtrans('file_server.error_field_name_dup', ['name' => $name]));
            }
            $type = strval(array_get($entry, 'field_type', FileMetadataField::TYPE_TEXT));
            if (!in_array($type, FileMetadataField::TYPES, true)) {
                $type = FileMetadataField::TYPE_TEXT;
            }
            $field = new FileMetadataField([
                'name' => $name,
                'label' => mb_substr($label, 0, 256),
                'field_type' => $type,
                'options' => trim(strval(array_get($entry, 'options', ''))) ?: null,
                'required' => boolval(array_get($entry, 'required', false)),
                'show_in_list' => boolval(array_get($entry, 'show_in_list', true)),
                'order' => $i,
            ]);
            if ($field->hasChoices() && count($field->choices()) === 0) {
                throw new FileServerException(exmtrans('file_server.error_field_choices', ['label' => $field->label]));
            }
            $default = strval(array_get($entry, 'default_value', ''));
            $field->default_value = $default !== '' ? mb_substr($default, 0, 1000) : null;
            if (isset($field->default_value)) {
                // the default has to be a value of the column
                $this->normalizeValue($field, $field->default_value);
            }
            $names[] = $name;
            $rows[] = $field;
        }

        DB::transaction(function () use ($workspace, $rows, $names) {
            FileMetadataField::query()->where('workspace_id', $workspace->id)->whereNotIn('name', count($names) > 0 ? $names : [''])->delete();
            foreach ($rows as $row) {
                $field = FileMetadataField::query()->where('workspace_id', $workspace->id)->where('name', $row->name)->first() ?? new FileMetadataField(['workspace_id' => $workspace->id, 'name' => $row->name]);
                foreach (['label', 'field_type', 'options', 'required', 'default_value', 'show_in_list', 'order'] as $key) {
                    $field->{$key} = $row->{$key};
                }
                $field->save();
            }
        });
        return static::fields($workspace);
    }

    /**
     * Put the values of a folder or file (only the columns given change).
     *
     * @param array<string, mixed> $input name => value
     */
    public function setValues(FileWorkspace $workspace, FileNode $node, array $input): FileNode
    {
        if ($node->workspace_id !== $workspace->id || $node->isTrashed()) {
            throw FileServerException::notFound();
        }
        $this->library->access()->authorize($workspace, $node, Level::EDIT);

        $values = (array)($node->metadata ?? []);
        foreach (static::fields($workspace) as $field) {
            if (!array_key_exists($field->name, $input)) {
                if ($field->required && static::isEmpty(array_get($values, $field->name))) {
                    throw new FileServerException(exmtrans('file_server.error_field_required', ['label' => $field->label]));
                }
                continue;
            }
            $value = $this->normalizeValue($field, $input[$field->name]);
            if ($field->required && static::isEmpty($value)) {
                throw new FileServerException(exmtrans('file_server.error_field_required', ['label' => $field->label]));
            }
            if (static::isEmpty($value)) {
                unset($values[$field->name]);
            } else {
                $values[$field->name] = $value;
            }
        }
        $node->metadata = count($values) > 0 ? $values : null;
        $node->save();
        FileSearch::sync([$node->id]);
        return $node;
    }

    /**
     * The defaults of the columns, put into a new file.
     */
    public static function applyDefaults(FileWorkspace $workspace, FileNode $node): void
    {
        $values = (array)($node->metadata ?? []);
        $changed = false;
        foreach (static::fields($workspace) as $field) {
            if (isset($field->default_value) && !array_key_exists($field->name, $values)) {
                try {
                    $values[$field->name] = (new self(ShareLinks::systemLibrary()))->normalizeValue($field, $field->default_value);
                    $changed = true;
                } catch (FileServerException $e) {
                    // a default that is no longer a value of the column
                }
            }
        }
        if ($changed) {
            $node->metadata = $values;
            $node->save();
        }
    }

    /**
     * A value of a column as it is kept: a string, a number, a Y-m-d date, a
     * choice, a list of choices or a boolean. Null: empty.
     *
     * @return string|int|float|bool|string[]|null
     */
    public function normalizeValue(FileMetadataField $field, mixed $value): mixed
    {
        switch ($field->field_type) {
            case FileMetadataField::TYPE_NUMBER:
                if ($value === null || $value === '') {
                    return null;
                }
                $value = str_replace([',', ' '], '', strval($value));
                if (!is_numeric($value)) {
                    throw new FileServerException(exmtrans('file_server.error_field_value', ['label' => $field->label]));
                }
                return strpos($value, '.') !== false ? floatval($value) : intval($value);
            case FileMetadataField::TYPE_DATE:
                if ($value === null || $value === '') {
                    return null;
                }
                $value = strval($value);
                if (preg_match('/\A\d{4}[-\/]\d{1,2}[-\/]\d{1,2}\z/', $value) !== 1) {
                    throw new FileServerException(exmtrans('file_server.error_field_value', ['label' => $field->label]));
                }
                try {
                    return \Carbon\Carbon::parse(str_replace('/', '-', $value))->format('Y-m-d');
                } catch (\Throwable $e) {
                    throw new FileServerException(exmtrans('file_server.error_field_value', ['label' => $field->label]));
                }
                // no break
            case FileMetadataField::TYPE_SELECT:
                if ($value === null || $value === '') {
                    return null;
                }
                $value = strval($value);
                if (!in_array($value, $field->choices(), true)) {
                    throw new FileServerException(exmtrans('file_server.error_field_value', ['label' => $field->label]));
                }
                return $value;
            case FileMetadataField::TYPE_MULTISELECT:
                $list = is_array($value) ? $value : (($value === null || $value === '') ? [] : preg_split('/\s*,\s*/', strval($value)));
                $choices = $field->choices();
                $result = [];
                foreach ((array)$list as $item) {
                    $item = strval($item);
                    if ($item === '') {
                        continue;
                    }
                    if (!in_array($item, $choices, true)) {
                        throw new FileServerException(exmtrans('file_server.error_field_value', ['label' => $field->label]));
                    }
                    $result[] = $item;
                }
                $result = array_values(array_unique($result));
                return count($result) > 0 ? $result : null;
            case FileMetadataField::TYPE_BOOLEAN:
                if ($value === null || $value === '') {
                    return null;
                }
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case FileMetadataField::TYPE_TEXTAREA:
                $value = trim(strval($value));
                return $value === '' ? null : mb_substr($value, 0, 10000);
            default:
                $value = trim(strval($value));
                return $value === '' ? null : mb_substr($value, 0, 1000);
        }
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * A value shown to the user.
     */
    public static function display(FileMetadataField $field, mixed $value): string
    {
        if (static::isEmpty($value)) {
            return '';
        }
        if ($field->field_type === FileMetadataField::TYPE_BOOLEAN) {
            return $value ? exmtrans('file_server.yes') : exmtrans('file_server.no');
        }
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        if ($field->field_type === FileMetadataField::TYPE_NUMBER && is_numeric($value)) {
            return number_format(floatval($value), is_float($value) ? max(0, strlen(substr(strrchr(strval($value), '.') ?: '', 1))) : 0);
        }
        return strval($value);
    }

    // ---------------------------------------------------------------- the list of a folder

    /**
     * Narrow the folders and files of a list by the values: name => value
     * (a select: the choice; a multiselect: has the choice; a boolean: 1 or 0;
     * the others: contains the words). The folders stay.
     *
     * @param Collection<int, FileNode> $nodes
     * @param Collection<int, FileMetadataField> $fields
     * @param array<string, mixed> $filters
     * @return Collection<int, FileNode>
     */
    public static function filter(Collection $nodes, Collection $fields, array $filters): Collection
    {
        $active = [];
        foreach ($fields as $field) {
            $value = array_get($filters, $field->name);
            if (!static::isEmpty($value) && is_scalar($value)) {
                $active[] = [$field, strval($value)];
            }
        }
        if (count($active) === 0) {
            return $nodes;
        }
        return $nodes->filter(function (FileNode $node) use ($active) {
            if ($node->isFolder()) {
                return true;
            }
            $values = (array)($node->metadata ?? []);
            foreach ($active as [$field, $want]) {
                if (!static::matches($field, array_get($values, $field->name), $want)) {
                    return false;
                }
            }
            return true;
        })->values();
    }

    protected static function matches(FileMetadataField $field, mixed $value, string $want): bool
    {
        if (static::isEmpty($value)) {
            return $want === '__none__';
        }
        if ($want === '__none__') {
            return false;
        }
        switch ($field->field_type) {
            case FileMetadataField::TYPE_SELECT:
            case FileMetadataField::TYPE_DATE:
                return strval($value) === $want;
            case FileMetadataField::TYPE_MULTISELECT:
                return in_array($want, (array)$value, true);
            case FileMetadataField::TYPE_BOOLEAN:
                return boolval($value) === filter_var($want, FILTER_VALIDATE_BOOLEAN);
            case FileMetadataField::TYPE_NUMBER:
                return is_numeric($want) && floatval($value) == floatval($want);
            default:
                return mb_stripos(strval($value), $want) !== false;
        }
    }

    /**
     * Sort a list by a column: the folders first, the files without a value last.
     *
     * @param Collection<int, FileNode> $nodes
     * @return Collection<int, FileNode>
     */
    public static function sort(Collection $nodes, FileMetadataField $field, string $direction): Collection
    {
        $desc = $direction === 'desc';
        $list = $nodes->all();
        usort($list, function (FileNode $a, FileNode $b) use ($field, $desc) {
            if ($a->isFolder() !== $b->isFolder()) {
                return $a->isFolder() ? -1 : 1;
            }
            $va = array_get((array)($a->metadata ?? []), $field->name);
            $vb = array_get((array)($b->metadata ?? []), $field->name);
            $ea = static::isEmpty($va);
            $eb = static::isEmpty($vb);
            if ($ea || $eb) {
                return $ea === $eb ? strnatcasecmp($a->name, $b->name) : ($ea ? 1 : -1);
            }
            if ($field->field_type === FileMetadataField::TYPE_NUMBER) {
                $cmp = floatval($va) <=> floatval($vb);
            } elseif ($field->field_type === FileMetadataField::TYPE_SELECT) {
                // the order of the choices
                $choices = $field->choices();
                $cmp = intval(array_search($va, $choices, true)) <=> intval(array_search($vb, $choices, true));
            } else {
                $cmp = strnatcasecmp(static::display($field, $va), static::display($field, $vb));
            }
            if ($cmp === 0) {
                return strnatcasecmp($a->name, $b->name);
            }
            return $desc ? -$cmp : $cmp;
        });
        return collect($list);
    }

    /**
     * Group a list by a column with choices (or a boolean): label => nodes, in
     * the order of the choices; the folders and the files without a value last.
     *
     * @param Collection<int, FileNode> $nodes
     * @return array<int, array{label: string, nodes: FileNode[]}>
     */
    public static function group(Collection $nodes, FileMetadataField $field): array
    {
        $groups = [];
        $keys = $field->field_type === FileMetadataField::TYPE_BOOLEAN ? ['1', '0'] : $field->choices();
        foreach ($keys as $key) {
            $label = $field->field_type === FileMetadataField::TYPE_BOOLEAN ? ($key === '1' ? exmtrans('file_server.yes') : exmtrans('file_server.no')) : $key;
            $groups[$key] = ['label' => $label, 'nodes' => []];
        }
        $none = ['label' => exmtrans('file_server.no_value'), 'nodes' => []];
        $folders = ['label' => exmtrans('file_server.folders'), 'nodes' => []];
        foreach ($nodes as $node) {
            if ($node->isFolder()) {
                $folders['nodes'][] = $node;
                continue;
            }
            $value = array_get((array)($node->metadata ?? []), $field->name);
            if (static::isEmpty($value)) {
                $none['nodes'][] = $node;
                continue;
            }
            $list = $field->field_type === FileMetadataField::TYPE_BOOLEAN ? [$value ? '1' : '0'] : (array)$value;
            $placed = false;
            foreach ($list as $item) {
                if (isset($groups[strval($item)])) {
                    $groups[strval($item)]['nodes'][] = $node;
                    $placed = true;
                }
            }
            if (!$placed) {
                $none['nodes'][] = $node;
            }
        }
        $result = array_values(array_filter($groups, function ($group) {
            return count($group['nodes']) > 0;
        }));
        if (count($none['nodes']) > 0) {
            $result[] = $none;
        }
        if (count($folders['nodes']) > 0) {
            array_unshift($result, $folders);
        }
        return $result;
    }
}
