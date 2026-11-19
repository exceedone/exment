<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Controllers\FileController;

/**
 * BUG-3 (NOT FIXED - intentionally): a parentless file bypasses the permission check.
 *
 * We do NOT fix it by "blocking every parentless file" because:
 *   System assets (favicon/logo/login background) are stored with
 *   ExmentFile::storeAs(FileType::SYSTEM, ...) (src/Model/System.php) WITHOUT a parent,
 *   and are served PUBLICLY. Blocking parentless files would break favicon/logo.
 *
 * This test:
 *  - Records the current behavior (parentless => checkParentPermission returns true).
 *  - GUARDS a future fix: asserts that system assets are parentless,
 *    so any BUG-3 fix must use a file_type allowlist, NOT block all parentless files.
 */
class Bug3FileAuthzConstraintTest extends SecurityRegressionTestCase
{
    public function test_current_behavior_parentless_file_is_still_permitted(): void
    {
        $uuid = make_uuid();
        \DB::table('files')->insert([
            'uuid'           => $uuid,
            'local_dirname'  => 'sec_poc',
            'local_filename' => $uuid . '.txt',
            'filename'       => 'poc.txt',
            'parent_id'      => null,
            'parent_type'    => null,
        ]);
        $data = \DB::table('files')->where('uuid', $uuid)->first();

        $method = new \ReflectionMethod(FileController::class, 'checkParentPermission');
        $method->setAccessible(true);

        // Current: parentless => true (not fixed). This is a recorded risk, intentionally deferred.
        $this->assertTrue(
            $method->invoke(null, $data, []) === true,
            'Current behavior: a parentless file is granted access (BUG-3 still open).'
        );
    }

    public function test_constraint_system_assets_are_stored_without_parent(): void
    {
        // Evidence in the source for why we must NOT block all parentless files.
        $src = $this->exmentSource('Model/System.php');
        $this->assertStringContainsString(
            'storeAs(FileType::SYSTEM',
            $src,
            'System assets are stored as FileType::SYSTEM with no parent => must stay downloadable.'
        );
    }
}
