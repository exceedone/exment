<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Collection;

/**
 * "@code" mentions in comments, list review findings M-1 and M-2.
 *
 * M-1: the notice carries the record's label and the comment text, so a
 * mentioned user who may not open the record must not receive it.
 * M-2: a full stop or a hyphen right after a name is not part of the name.
 *
 * Everything a test writes is rolled back.
 */
class CommentMentionTest extends UnitTestBase
{
    use DatabaseTransactions;

    private const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT;

    /** fixture organization below dev, holding dev1-userC and dev1-userD */
    private const ORGANIZATION_DEV1 = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }

    /**
     * user1 may read every record. user2 and dev0-userB hold a role that
     * only reaches records shared with them, and this one is not - until it
     * is shared with dev0-userB's organization.
     */
    public function testMentionNotifiesOnlyThoseWhoMayOpenTheRecord(): void
    {
        $user1 = intval(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = intval(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $userB = intval(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $ids = [$user1, $user2, $userB];

        $record = $this->recordCreatedBy(CustomTable::getEloquent(self::TABLE), $user1);
        $this->assertSame([[SystemTableName::USER, $user1]], $this->sharesOf($record), 'fixture: the record is shared with its creator only');

        $before = $this->notices($record, $ids);
        $this->comment($record, '@user2 @dev0-userB please check this, @user1.');
        $after = $this->notices($record, $ids);

        $this->assertSame(0, $after[$user2] - $before[$user2], 'user2 may not open the record and is not told about it');
        $this->assertSame(0, $after[$userB] - $before[$userB], 'dev0-userB may not open the record and is not told about it');
        $this->assertSame(1, $after[$user1] - $before[$user1], 'a name followed by a full stop is still found');

        $this->share($record, SystemTableName::ORGANIZATION, intval(TestDefine::TESTDATA_ORGANIZATION_DEV));
        $this->comment($record, '@user2 @dev0-userB');
        $last = $this->notices($record, $ids);

        $this->assertSame(1, $last[$userB] - $after[$userB], 'a member of an organization the record is shared with is told');
        $this->assertSame(0, $last[$user2] - $after[$user2], 'user2 still may not open the record');
    }

    public function testMentionCodesLeaveOutATrailingStop(): void
    {
        $codes = (new \ReflectionMethod(CustomValue::class, 'extractMentionCodes'))
            ->invoke(null, "cc @user1. and @dev0-userB- also @a.b, and \u{FF20}user2");

        // Both readings of a name that ends in "." or "-" are looked up, so a
        // code that really ends that way still matches.
        $this->assertEqualsCanonicalizing(
            ['user1.', 'user1', 'dev0-userB-', 'dev0-userB', 'a.b', 'user2'],
            $codes
        );
    }

    /**
     * The readers worked out for somebody else are exactly the users the
     * record's own scope lets in, for every fixture user and every way a
     * record can be opened: a role over the whole table, a share by name, a
     * share with an organization (joined only, or with its upper and lower
     * organizations too), and a share of the parent record when the table
     * inherits its parent's permissions.
     */
    public function testReadersAreThoseTheRecordScopeLetsIn(): void
    {
        $user1 = intval(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = intval(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $userB = intval(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $edit = CustomTable::getEloquent(self::TABLE);

        $record = $this->recordCreatedBy($edit, $user1);
        $readers = $this->assertReadersMatchScope($record, 'shared with its creator only');
        $this->assertNotContains($user2, $readers);
        $this->assertNotContains($userB, $readers);

        $this->share($record, SystemTableName::ORGANIZATION, intval(TestDefine::TESTDATA_ORGANIZATION_DEV));
        $readers = $this->assertReadersMatchScope($record, 'shared with organization dev');
        $this->assertContains($userB, $readers);
        $this->assertNotContains($user2, $readers);

        $this->share($record, SystemTableName::USER, $user2);
        $readers = $this->assertReadersMatchScope($record, 'shared with user2 as well');
        $this->assertContains($user2, $readers);

        $other = $this->recordCreatedBy($edit, $user1, 1);
        $this->share($other, SystemTableName::ORGANIZATION, self::ORGANIZATION_DEV1);
        $this->assertReadersMatchScope($other, 'shared with organization dev1, joined organizations only');
        System::org_joined_type_custom_value(JoinedOrgFilterType::ALL);
        $this->assertReadersMatchScope($other, 'shared with organization dev1, upper and lower organizations too');
        System::org_joined_type_custom_value(JoinedOrgFilterType::ONLY_JOIN);

        $viewAll = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $this->assertReadersMatchScope($this->recordCreatedBy($viewAll, $user1), 'a table the role reads in full');

        // a child whose parent record is shared with dev1-userC by name
        $child = CustomTable::getEloquent('child_table');
        \DB::table('custom_tables')->where('id', $child->id)
            ->update(['options' => json_encode(array_merge($child->options ?? [], ['inherit_parent_permission' => '1']))]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $childRecord = $this->recordCreatedBy(CustomTable::getEloquent('child_table'), intval(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 6);
        $this->assertContains(
            [SystemTableName::USER, intval(TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC)],
            $this->sharesOf($childRecord->getParentValue()),
            'fixture: the parent record is shared with dev1-userC'
        );
        $readers = $this->assertReadersMatchScope($childRecord, 'a child that inherits its parent permissions');
        $this->assertContains(intval(TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC), $readers);
    }

    // helpers -----------------------------------------------------------

    /**
     * Compare the readers with what the record scope gives each fixture
     * user, and check the answer does not depend on who is writing.
     *
     * @return array<int, int> the readers
     */
    private function assertReadersMatchScope(CustomValue $record, string $state): array
    {
        $users = $this->allUsers();
        $table_name = $record->custom_table->table_name;

        $expected = [];
        foreach ($users as $user) {
            $this->login($user->id);
            if (CustomTable::getEloquent($table_name)->getValueModel()->newQuery()->where('id', $record->id)->exists()) {
                $expected[] = intval($user->id);
            }
        }

        $actual = null;
        foreach ([TestDefine::TESTDATA_USER_LOGINID_ADMIN, TestDefine::TESTDATA_USER_LOGINID_DEV_USERB] as $writer) {
            $this->login($writer);
            $readers = (new \ReflectionMethod(CustomValue::class, 'mentionReaderIds'))->invoke(null, $record, $users);
            sort($readers);
            if (isset($actual)) {
                $this->assertSame($actual, $readers, "$state: the readers do not depend on who writes");
            }
            $actual = $readers;
        }
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        sort($expected);
        $this->assertSame($expected, $actual, "$state: the readers are the users the record scope lets in");

        return $actual;
    }

    private function allUsers(): Collection
    {
        return CustomTable::getEloquent(SystemTableName::USER)->getValueModel()->newQuery()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->orderBy('id')
            ->get();
    }

    private function recordCreatedBy(CustomTable $table, int $user_id, int $nth = 0): CustomValue
    {
        $record = $table->getValueModel()->newQuery()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->where('created_user_id', $user_id)
            ->orderBy('id')
            ->skip($nth)
            ->first();
        $this->assertInstanceOf(CustomValue::class, $record, "fixture: {$table->table_name} has a record by user $user_id");

        return $record;
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    private function sharesOf(CustomValue $record): array
    {
        return \DB::table('custom_value_authoritables')
            ->where('parent_id', $record->id)
            ->where('parent_type', $record->custom_table->table_name)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [strval($row->authoritable_user_org_type), intval($row->authoritable_target_id)])
            ->all();
    }

    private function share(CustomValue $record, string $type, int $target_id): void
    {
        \DB::table('custom_value_authoritables')->insert([
            'parent_id' => $record->id,
            'parent_type' => $record->custom_table->table_name,
            'authoritable_type' => Permission::CUSTOM_VALUE_VIEW,
            'authoritable_user_org_type' => $type,
            'authoritable_target_id' => $target_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        System::clearRequestSession();
        System::clearCache();
    }

    /**
     * Post a comment the way the detail page does.
     */
    private function comment(CustomValue $record, string $text): void
    {
        $comment = CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel();
        $comment->parent_id = $record->id;
        $comment->parent_type = $record->custom_table->table_name;
        $comment->setValue(['comment_detail' => $text]);
        $comment->save();
    }

    /**
     * Mention notices on the record, per user.
     *
     * @param array<int, int> $user_ids
     * @return array<int, int>
     */
    private function notices(CustomValue $record, array $user_ids): array
    {
        $subject = exmtrans('custom_value.message.mention_notify', ['label' => $record->label]);

        $counts = [];
        foreach ($user_ids as $user_id) {
            $counts[$user_id] = NotifyNavbar::withoutGlobalScopes()
                ->where('target_user_id', $user_id)
                ->where('parent_id', $record->id)
                ->where('parent_type', $record->custom_table->table_name)
                ->where('notify_subject', $subject)
                ->count();
        }

        return $counts;
    }

    /**
     * @param int|string $user_id
     */
    private function login($user_id): LoginUser
    {
        $login = LoginUser::find($user_id);
        $this->assertInstanceOf(LoginUser::class, $login);
        $this->be($login, 'admin');
        System::clearRequestSession();
        System::clearCache();

        return $login;
    }
}
