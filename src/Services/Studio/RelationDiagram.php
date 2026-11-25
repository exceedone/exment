<?php

namespace Exceedone\Exment\Services\Studio;

/**
 * Lays out the relation diagram of the tables, drawn as SVG on the Studio page.
 *
 * Tables that are linked to each other are drawn together as one group, and
 * the groups are stacked from top to bottom, so a line stays inside its group.
 * In a group the tables go from left to right: a parent table left of its
 * children, and a table that other tables pick a row from (a master such as
 * "status") left of the tables that pick from it. Tables that have no
 * relation are not drawn; the page lists them under the diagram.
 */
class RelationDiagram
{
    public const BOX_WIDTH = 180;
    public const BOX_HEIGHT = 36;
    public const GAP_X = 90;
    public const GAP_Y = 18;
    public const GAP_GROUP = 34;
    public const PADDING = 12;

    /**
     * @param array<int, array{id: int, name: string}> $tables
     * @param array<int, array{from: int, to: int, type: string, label: string}> $relations
     * @return array{width: int, height: int, nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>, others: array<int, string>}
     */
    public static function layout(array $tables, array $relations): array
    {
        $names = [];
        foreach ($tables as $table) {
            $names[$table['id']] = $table['name'];
        }

        // the edges between two different listed tables, each written left -> right
        $edges = [];
        foreach ($relations as $edge) {
            if (!isset($names[$edge['from']], $names[$edge['to']]) || $edge['from'] === $edge['to']) {
                continue;
            }
            $edges[] = $edge + [
                // a column picks from a master: the master goes left
                'left' => $edge['type'] === 'select_table' ? $edge['to'] : $edge['from'],
                'right' => $edge['type'] === 'select_table' ? $edge['from'] : $edge['to'],
            ];
        }

        $groups = static::groups(array_keys($names), $edges);
        $level = static::levels($groups, $edges);

        // groups in the order of their first table in the table list; boxes in a
        // column in the order of the table list too
        $nodes = [];
        $top = static::PADDING;
        $maxCol = -1;
        $groupOf = [];
        foreach ($groups as $no => $members) {
            foreach ($members as $id) {
                $groupOf[$id] = $no;
            }
        }
        $placed = [];
        foreach ($tables as $table) {
            if (!isset($groupOf[$table['id']])) {
                continue;
            }
            $no = $groupOf[$table['id']];
            if (isset($placed[$no])) {
                continue;
            }
            $placed[$no] = true;

            $rows = [];
            foreach ($tables as $member) {
                if (($groupOf[$member['id']] ?? null) !== $no) {
                    continue;
                }
                $col = $level[$member['id']];
                $row = $rows[$col] ?? 0;
                $rows[$col] = $row + 1;
                $maxCol = max($maxCol, $col);
                $nodes[$member['id']] = [
                    'id' => $member['id'],
                    'name' => $member['name'],
                    'x' => static::PADDING + $col * (static::BOX_WIDTH + static::GAP_X),
                    'y' => $top + $row * (static::BOX_HEIGHT + static::GAP_Y),
                ];
            }
            $top += max(1, ...array_values($rows)) * (static::BOX_HEIGHT + static::GAP_Y) - static::GAP_Y + static::GAP_GROUP;
        }

        $lines = [];
        foreach ($edges as $edge) {
            $lines[] = static::path($nodes[$edge['from']], $nodes[$edge['to']]) + [
                'type' => $edge['type'],
                'label' => $edge['label'],
            ];
        }

        $others = [];
        foreach ($tables as $table) {
            if (!isset($groupOf[$table['id']])) {
                $others[] = $table['name'];
            }
        }

        $cols = $maxCol + 1;
        return [
            // half a gap more on the right: the lines between boxes of one column go round there
            'width' => $cols === 0 ? 0 : static::PADDING * 2 + $cols * static::BOX_WIDTH + ($cols - 1) * static::GAP_X + intdiv(static::GAP_X, 2),
            'height' => count($nodes) === 0 ? 0 : $top - static::GAP_GROUP + static::PADDING,
            'nodes' => array_values($nodes),
            'edges' => $lines,
            'others' => $others,
        ];
    }

    /**
     * The tables linked to each other, as groups. A table with no edge is in no group.
     *
     * @param array<int, int> $ids
     * @param array<int, array<string, mixed>> $edges
     * @return array<int, array<int, int>>
     */
    protected static function groups(array $ids, array $edges): array
    {
        $parent = [];
        $find = function ($id) use (&$parent, &$find) {
            if ($parent[$id] !== $id) {
                $parent[$id] = $find($parent[$id]);
            }
            return $parent[$id];
        };
        foreach ($edges as $edge) {
            foreach ([$edge['from'], $edge['to']] as $id) {
                $parent[$id] = $parent[$id] ?? $id;
            }
            $parent[$find($edge['from'])] = $find($edge['to']);
        }

        $groups = [];
        foreach ($ids as $id) {
            if (isset($parent[$id])) {
                $groups[$find($id)][] = $id;
            }
        }
        return array_values($groups);
    }

    /**
     * The column of each table: one more than the deepest table on its left.
     * The loop stops after as many rounds as there are tables in the group,
     * so a cycle (two tables picking from each other) cannot keep it going.
     *
     * @param array<int, array<int, int>> $groups
     * @param array<int, array<string, mixed>> $edges
     * @return array<int, int>
     */
    protected static function levels(array $groups, array $edges): array
    {
        $level = [];
        foreach ($groups as $members) {
            $size = count($members);
            $inGroup = array_fill_keys($members, true);
            foreach ($members as $id) {
                $level[$id] = 0;
            }
            for ($round = 0; $round < $size; $round++) {
                $changed = false;
                foreach ($edges as $edge) {
                    if (!isset($inGroup[$edge['left']])) {
                        continue;
                    }
                    $next = $level[$edge['left']] + 1;
                    if ($level[$edge['right']] < $next && $next < $size) {
                        $level[$edge['right']] = $next;
                        $changed = true;
                    }
                }
                if (!$changed) {
                    break;
                }
            }
        }
        return $level;
    }

    /**
     * The line from one box to another: between the facing sides when they
     * are in different columns; around the right sides when they are in one
     * column, so the line does not cross the boxes between them.
     *
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     * @return array{d: string, label_x: int, label_y: int}
     */
    protected static function path(array $from, array $to): array
    {
        $fy = $from['y'] + intdiv(static::BOX_HEIGHT, 2);
        $ty = $to['y'] + intdiv(static::BOX_HEIGHT, 2);

        if ($from['x'] !== $to['x']) {
            $toRight = $to['x'] > $from['x'];
            $fx = $toRight ? $from['x'] + static::BOX_WIDTH : $from['x'];
            $tx = $toRight ? $to['x'] : $to['x'] + static::BOX_WIDTH;
            $mid = intdiv($fx + $tx, 2);
            return [
                'd' => "M{$fx},{$fy} C{$mid},{$fy} {$mid},{$ty} {$tx},{$ty}",
                'label_x' => $mid,
                'label_y' => intdiv($fy + $ty, 2) - 4,
            ];
        }

        $fx = $from['x'] + static::BOX_WIDTH;
        $tx = $to['x'] + static::BOX_WIDTH;
        $out = $fx + intdiv(static::GAP_X, 2);
        return [
            'd' => "M{$fx},{$fy} C{$out},{$fy} {$out},{$ty} {$tx},{$ty}",
            'label_x' => $out,
            'label_y' => intdiv($fy + $ty, 2) - 4,
        ];
    }
}
