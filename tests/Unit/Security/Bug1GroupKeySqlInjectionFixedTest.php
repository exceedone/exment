<?php

namespace Exceedone\Exment\Tests\Unit\Security;

/**
 * BUG-1 (FIXED): SQL Injection via the `group_key` parameter.
 *
 * Fix: src/DataItems/Grid/GridBase.php - switched from interpolating the value inside a
 *      single-quoted literal to using a bound parameter:
 *        BEFORE: $model->whereRaw("$column = '$query_value'");
 *        AFTER:  $model->whereRaw("$column = ?", [$query_value]);
 *      ($column is still an admin-defined, grammar-wrapped column expression - safe.)
 *
 * Tests:
 *  1) The real source now uses bindings, no raw value interpolation remains.
 *  2) On the real MariaDB: the parameterized form treats the payload as a literal -> no injection.
 */
class Bug1GroupKeySqlInjectionFixedTest extends SecurityRegressionTestCase
{
    public function test_source_now_uses_parameter_binding(): void
    {
        $src = $this->exmentSource('DataItems/Grid/GridBase.php');

        $this->assertStringContainsString(
            'whereRaw("$column = ?", [$filter_raw->view_filter_condition_value_text])',
            $src,
            'The is_multiple branch must use a binding.'
        );
        $this->assertStringContainsString(
            'whereRaw("$column = ?", [$query_value])',
            $src,
            'The group_condition branch must use a binding.'
        );

        $this->assertStringNotContainsString(
            "whereRaw(\"\$column = '\$query_value'\")",
            $src,
            'The raw value interpolation (old SQLi shape) must be gone.'
        );
        $this->assertStringNotContainsString(
            "whereRaw(\"\$column = '\$filter_raw->view_filter_condition_value_text'\")",
            $src,
            'The raw view_filter_condition_value_text interpolation must be gone.'
        );
    }

    public function test_parameterized_whereRaw_blocks_injection_on_real_db(): void
    {
        $conn = \DB::connection();

        $conn->statement('DROP TEMPORARY TABLE IF EXISTS _sec_fix_bug1');
        $conn->statement('CREATE TEMPORARY TABLE _sec_fix_bug1 (id INT, secret VARCHAR(64))');
        $conn->statement("INSERT INTO _sec_fix_bug1 (id, secret) VALUES (1, 'public'), (2, 'TOP_SECRET')");

        try {
            $column  = 'secret';
            $payload = "no-match' OR '1'='1";

            // FIXED form: parameterized. The payload is treated as a single literal string.
            $rows = $conn->table('_sec_fix_bug1')
                ->whereRaw("$column = ?", [$payload])
                ->get();

            $this->assertCount(
                0,
                $rows,
                'Parameterized: the payload is a literal, the WHERE clause is not broken (no SQLi).'
            );
        } finally {
            $conn->statement('DROP TEMPORARY TABLE IF EXISTS _sec_fix_bug1');
        }
    }
}
