<?php

namespace Exceedone\Exment\Tests\Unit\Security;

/**
 * BUG-5 (FIXED): array_splice() inside a foreach removed the wrong/incomplete elements for duplicates.
 *
 * Fix: src/Controllers/FileController.php (the asApi branch of deleteFile)
 *      BEFORE: foreach(...) { if (match) array_splice($current_val, $key, 1); }
 *      AFTER:  $current_val = array_values(array_filter($current_val, fn => $value != $target));
 */
class Bug5FileDeleteArraySpliceFixedTest extends SecurityRegressionTestCase
{
    public function test_fixed_logic_removes_all_matching_entries(): void
    {
        // Reproduce the FIXED logic.
        $removeTarget = 'tbl/dup.txt';
        $current_val  = ['tbl/dup.txt', 'tbl/other.txt', 'tbl/dup.txt']; // two duplicate entries

        $current_val = array_values(array_filter($current_val, function ($value) use ($removeTarget) {
            return $value != $removeTarget;
        }));

        $this->assertSame(['tbl/other.txt'], $current_val);
        $this->assertNotContains($removeTarget, $current_val);
    }

    public function test_source_no_longer_uses_array_splice_in_loop(): void
    {
        $src = $this->exmentSource('Controllers/FileController.php');

        $this->assertStringNotContainsString(
            'array_splice($current_val, $key, 1)',
            $src,
            'array_splice inside the loop must be gone.'
        );
        $this->assertStringContainsString(
            'array_filter($current_val',
            $src,
            'Must use array_filter to remove correctly and completely.'
        );
    }
}
