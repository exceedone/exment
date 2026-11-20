<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\Studio\RelationDiagram;
use PHPUnit\Framework\TestCase;

/**
 * The layout of the relation diagram on the Studio page (ISS00231 phase 1).
 */
class StudioRelationDiagramTest extends TestCase
{
    /**
     * @return array<int, array{id: int, name: string}>
     */
    protected function tables(int ...$ids): array
    {
        $tables = [];
        foreach ($ids as $id) {
            $tables[] = ['id' => $id, 'name' => 'table' . $id];
        }
        return $tables;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    protected function byId(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $result[$node['id']] = $node;
        }
        return $result;
    }

    public function testNoRelationDrawsNothingAndListsTheTables(): void
    {
        $layout = RelationDiagram::layout($this->tables(1, 2), []);

        $this->assertSame([], $layout['nodes']);
        $this->assertSame([], $layout['edges']);
        $this->assertSame(['table1', 'table2'], $layout['others']);
        $this->assertSame(0, $layout['width']);
        $this->assertSame(0, $layout['height']);
    }

    public function testAParentIsLeftOfItsChildAndAMasterLeftOfTheTableThatPicksFromIt(): void
    {
        // 1 is the parent of 2; 2 has a column that picks a row of 3 (a master)
        $layout = RelationDiagram::layout($this->tables(1, 2, 3), [
            ['from' => 1, 'to' => 2, 'type' => 'one_to_many', 'label' => ''],
            ['from' => 2, 'to' => 3, 'type' => 'select_table', 'label' => 'status'],
        ]);
        $nodes = $this->byId($layout['nodes']);

        $this->assertLessThan($nodes[2]['x'], $nodes[1]['x']);
        $this->assertLessThan($nodes[2]['x'], $nodes[3]['x']);
        $this->assertCount(2, $layout['edges']);
        $this->assertSame('status', $layout['edges'][1]['label']);
    }

    public function testTablesNotLinkedToEachOtherAreDrawnAsSeparateGroups(): void
    {
        $layout = RelationDiagram::layout($this->tables(1, 2, 3, 4, 5), [
            ['from' => 1, 'to' => 2, 'type' => 'one_to_many', 'label' => ''],
            ['from' => 3, 'to' => 4, 'type' => 'many_to_many', 'label' => ''],
        ]);
        $nodes = $this->byId($layout['nodes']);

        // the second group starts below the first one, and both start at the left
        $this->assertSame($nodes[1]['x'], $nodes[3]['x']);
        $this->assertGreaterThan($nodes[2]['y'] + RelationDiagram::BOX_HEIGHT, $nodes[3]['y']);
        $this->assertSame(['table5'], $layout['others']);
        foreach ($layout['nodes'] as $node) {
            $this->assertLessThanOrEqual($layout['height'], $node['y'] + RelationDiagram::BOX_HEIGHT);
            $this->assertLessThanOrEqual($layout['width'], $node['x'] + RelationDiagram::BOX_WIDTH);
        }
    }

    public function testACycleOfRelationsEnds(): void
    {
        // two tables that pick from each other, and a relation of a table to itself
        $layout = RelationDiagram::layout($this->tables(1, 2), [
            ['from' => 1, 'to' => 2, 'type' => 'select_table', 'label' => ''],
            ['from' => 2, 'to' => 1, 'type' => 'select_table', 'label' => ''],
            ['from' => 1, 'to' => 1, 'type' => 'one_to_many', 'label' => ''],
        ]);

        $this->assertCount(2, $layout['nodes']);
        // the relation of a table to itself is not drawn
        $this->assertCount(2, $layout['edges']);
    }

    public function testARelationToATableNotListedIsLeftOut(): void
    {
        // table 9 is not listed: the user may not set it
        $layout = RelationDiagram::layout($this->tables(1), [
            ['from' => 1, 'to' => 9, 'type' => 'one_to_many', 'label' => ''],
        ]);

        $this->assertSame([], $layout['nodes']);
        $this->assertSame(['table1'], $layout['others']);
    }
}
