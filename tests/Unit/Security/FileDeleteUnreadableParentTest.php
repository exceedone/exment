<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Controllers\FileController;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Middleware\Morph;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Deleting an attachment is gated on the record it hangs off, and a record
 * that cannot be read has to count as a refusal.
 *
 * FileController::checkParentPermission() resolves the parent with
 * getValueModel(), which reads through CustomValueModelScope: it answers null
 * both for a row that is not there and for one this user may not see. While
 * null was treated as "nothing to check", anyone holding a file's uuid could
 * delete the attachments of a record they cannot open - the file off disk, its
 * document row and its file row, none of it recoverable.
 *
 * The parentless case is a different question and is deliberately left alone;
 * see Bug3FileAuthzConstraintTest for why it must stay permitted.
 */
class FileDeleteUnreadableParentTest extends SecurityRegressionTestCase
{
    /**
     * Put a file on a record owned by somebody else, with no share.
     *
     * @return array<mixed> [$table, $parent, $file row]
     */
    protected function attachFileToARecordOfTheAdministrator()
    {
        Morph::defineMorphMap();
        System::clearCache();
        System::clearRequestSession();

        // Created by the administrator, so it is shared with nobody else.
        // user2 holds custom_value_edit on this table but no share on this
        // row, which is the shape CustomValueModelScope hides.
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $parent = $table->getValueModel();
        $parent->setValue('text', 'file_delete_permission_test');
        $parent->save();

        $uuid = make_uuid();
        \DB::table('files')->insert([
            'uuid' => $uuid,
            'local_dirname' => $table->table_name,
            'local_filename' => $uuid . '.txt',
            'filename' => 'attachment.txt',
            'parent_id' => $parent->id,
            'parent_type' => $table->table_name,
        ]);

        return [$table, $parent, \DB::table('files')->where('uuid', $uuid)->first()];
    }

    /**
     * @param mixed $custom_value
     * @param string|null $permission null shares nothing
     * @return void
     */
    protected function beUser2($custom_value = null, $permission = null)
    {
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);

        if (isset($custom_value) && isset($permission)) {
            \DB::table('custom_value_authoritables')->insert([
                'parent_id' => $custom_value->id,
                'parent_type' => $custom_value->custom_table->table_name,
                'authoritable_type' => $permission,
                'authoritable_user_org_type' => 'user',
                'authoritable_target_id' => $user->base_user_id,
                'created_at' => \Carbon\Carbon::now(),
                'updated_at' => \Carbon\Carbon::now(),
            ]);
        }

        // Permissions and resolved records are both memoised for the life of
        // the request, so a change here is invisible until the memo is gone.
        System::clearCache();
        System::clearRequestSession();
        $this->be($user);
    }

    /**
     * @param mixed $data the file row
     * @return int|null the status refused with, or null when it was allowed
     */
    protected function checkParentPermissionStatus($data)
    {
        $method = new \ReflectionMethod(FileController::class, 'checkParentPermission');
        $method->setAccessible(true);

        try {
            $method->invoke(null, $data, []);
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }

        return null;
    }

    public function test_a_file_on_an_unreadable_record_is_not_deletable(): void
    {
        list($table, $parent, $data) = $this->attachFileToARecordOfTheAdministrator();
        $this->beUser2();

        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $this->assertNull(
            $table->getValueModel($parent->id),
            'the record has to be out of reach for this test to be about anything'
        );

        $this->assertSame(
            403,
            $this->checkParentPermissionStatus($data),
            'deleting the attachment of a record the user cannot read must be refused'
        );
    }

    public function test_a_file_on_a_record_shared_for_edit_is_still_deletable(): void
    {
        list($table, $parent, $data) = $this->attachFileToARecordOfTheAdministrator();
        $this->beUser2($parent, Permission::CUSTOM_VALUE_EDIT);

        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $this->assertNotNull(
            $table->getValueModel($parent->id),
            'the record is shared, so it has to be readable'
        );

        $this->assertNull(
            $this->checkParentPermissionStatus($data),
            'and its attachment has to stay deletable - the fix must not close the legitimate case'
        );
    }
}
