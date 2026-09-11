<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\Workflow\WorkflowTaskService;
use Tests\TestCase;

/**
 * Guard test for the task_key contract of the "un-actioned workflow task" feature.
 *
 * task_key is the ONLY link between three places:
 *   - WorkflowTaskService::taskKey()       builds it (list rows, dropdown links)
 *   - WorkflowTaskService::parseTaskKey()  turns it back into database columns
 *   - WorkflowTaskController::read()       the GET endpoint that writes with it
 *
 * It carries the record identity only. The workflow status is deliberately NOT in it:
 * WorkflowAction::forwardWorkflowValue() deletes the read rows of a record on every status
 * change, which is what makes a task unseen again (see WorkflowTaskWiringTest).
 *
 * Pure unit test: no DB rows required.
 */
class WorkflowTaskKeyTest extends TestCase
{
    /**
     * The key is "{custom_table_id}:{morph_id}", nothing else.
     *
     * @return void
     */
    public function testKeyShape()
    {
        $this->assertSame('12:345', WorkflowTaskService::taskKey(12, 345));
        // ids come back from the DB as strings on some drivers - same key
        $this->assertSame('12:345', WorkflowTaskService::taskKey('12', '345'));
    }

    /**
     * Different tables / different records must never collide.
     *
     * @return void
     */
    public function testKeysDoNotCollide()
    {
        $keys = [
            WorkflowTaskService::taskKey(1, 23),
            WorkflowTaskService::taskKey(12, 3),
            WorkflowTaskService::taskKey(1, 233),
            WorkflowTaskService::taskKey(11, 23),
        ];

        $this->assertCount(count($keys), array_unique($keys), 'task_key must be unique per (table, record)');
    }

    /**
     * Every key the service produces must survive the round trip, otherwise real links
     * silently stop marking anything as seen.
     *
     * @return void
     */
    public function testGeneratedKeysParseBack()
    {
        $cases = [
            [1, 1],
            [12, 345],
            // widest ids that are still storable: custom_table_id is int unsigned,
            // morph_id is bigint unsigned but is handled as a PHP int
            [4294967295, PHP_INT_MAX],
        ];

        foreach ($cases as [$customTableId, $morphId]) {
            $key = WorkflowTaskService::taskKey($customTableId, $morphId);
            $parsed = WorkflowTaskService::parseTaskKey($key);

            $this->assertIsArray($parsed, "the service cannot parse a key it generated: {$key}");
            $this->assertSame((int)$customTableId, $parsed[0]);
            $this->assertSame((int)$morphId, $parsed[1]);
        }
    }

    /**
     * parseTaskKey() is the input filter of a GET endpoint that writes to the database,
     * so it has to reject everything that is not a real task_key.
     *
     * @return void
     */
    public function testParseRejectsCraftedKeys()
    {
        $rejected = [
            null,
            123,                      // not a string
            ['1:1'],                  // "?key[]=1:1" - explode() on an array is a TypeError
            '',
            'foobar',
            'user:1',                 // table name instead of an id
            '1:',                     // no record id
            ':1',                     // no table id
            '1:1:wv2',                // the old three-part format
            '-1:1',
            '1:1 ',                   // trailing space
            "1:1\n1:2",               // newline injection
            "1:1\n",                  // PHP "$" matches before a trailing newline; \z must not
            '01:1',                   // leading zero: a second spelling of the same task
            '1:01',
            '0:1',                    // ids start at 1
            '1:0',
            '4294967296:1',           // one over custom_table_id (int unsigned)
            '9999999999:1',           // same number of digits, still over the column
            '1:9999999999999999999',  // over PHP_INT_MAX, casting would silently saturate
            str_repeat('9', 12) . ':1',
        ];

        foreach ($rejected as $key) {
            $this->assertNull(
                WorkflowTaskService::parseTaskKey($key),
                'this key must be rejected: ' . json_encode($key)
            );
        }
    }

    /**
     * Anything the parser accepts must be storable in the columns it feeds, so a crafted but
     * "valid looking" key cannot overflow custom_table_id / morph_id on a strict-mode MySQL.
     *
     * @return void
     */
    public function testAcceptedKeysFitTheColumns()
    {
        // widest key that is still accepted
        $widest = '4294967295:' . PHP_INT_MAX;
        $parsed = WorkflowTaskService::parseTaskKey($widest);

        $this->assertIsArray($parsed, 'the widest storable key must still be accepted');
        // custom_table_id: int unsigned, max 4294967295
        $this->assertLessThanOrEqual(4294967295, $parsed[0], 'custom_table_id would overflow');
        // morph_id: bigint unsigned in the DB, but a PHP int here
        $this->assertLessThanOrEqual(PHP_INT_MAX, $parsed[1], 'morph_id would overflow');
        $this->assertGreaterThan(0, $parsed[1], 'a saturated cast would have wrapped or clipped');
    }
}
