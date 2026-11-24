<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * #1647: the comments of the data can be exported with the data.
 *
 * "exment:export --add_comment=1" and "All (with comments)" of the export dialog (add_comment=1)
 * add the sheet "comment". Without the option the export is the same as before.
 */
class ExportCommentTest extends FeatureTestBase
{
    use ImportTrait;
    use TestTrait;
    use DatabaseTransactions;

    private const TABLE_NAME = TestDefine::TESTDATA_TABLE_NAME_EDIT;
    private const COMMENT_FIRST = 'issue 1647 first comment';
    private const COMMENT_SECOND = 'issue 1647 second comment';
    private const COMMENT_OTHER_TABLE = 'issue 1647 comment of another table';

    /**
     * @var string
     */
    private $dirpath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $this->dirpath = storage_path(path_join_os('app', 'export', 'unittest_comment'));
        if (\Illuminate\Support\Facades\File::exists($this->dirpath)) {
            \Illuminate\Support\Facades\File::deleteDirectory($this->dirpath);
        }
        \Exceedone\Exment\Facades\ExmentFacade::makeDirectory($this->dirpath);
    }

    protected function tearDown(): void
    {
        if ($this->dirpath !== '' && \Illuminate\Support\Facades\File::exists($this->dirpath)) {
            \Illuminate\Support\Facades\File::deleteDirectory($this->dirpath);
        }
        parent::tearDown();
    }

    public function testCommentsAreExportedToTheirOwnSheet(): void
    {
        $custom_value = $this->getTargetValue();
        $user_name = $this->addComment($custom_value, self::TABLE_NAME, self::COMMENT_FIRST);
        $this->addComment($custom_value, self::TABLE_NAME, self::COMMENT_SECOND);
        // a comment of the same id in another table must not be exported
        $this->addComment($custom_value, TestDefine::TESTDATA_TABLE_NAME_VIEW, self::COMMENT_OTHER_TABLE);

        $sheets = $this->export(['--add_comment' => '1']);

        $this->assertArrayHasKey(self::TABLE_NAME, $sheets);
        $this->assertArrayHasKey(SystemTableName::COMMENT, $sheets);

        $rows = $this->rows($sheets[SystemTableName::COMMENT]);
        $this->assertSame(
            ['id', 'suuid', 'parent_id', 'parent_type', 'value.comment_detail', 'created_at', 'updated_at', 'created_user', 'updated_user'],
            $rows[0]
        );
        // the second row is the view names of the columns
        $this->assertCount(9, $rows[1]);

        $bodies = array_slice($rows, 2);
        $details = array_map(function (array $row) {
            return $row[4] ?? null;
        }, $bodies);
        $this->assertContains(self::COMMENT_FIRST, $details);
        $this->assertContains(self::COMMENT_SECOND, $details);
        $this->assertNotContains(self::COMMENT_OTHER_TABLE, $details);

        $first = null;
        foreach ($bodies as $row) {
            if (($row[4] ?? null) === self::COMMENT_FIRST) {
                $first = $row;
            }
        }
        $this->assertNotNull($first);
        $this->assertEquals($custom_value->id, $first[2]);
        $this->assertSame(self::TABLE_NAME, $first[3]);
        // the name of the user who wrote the comment, not the id
        $this->assertNotEmpty($user_name);
        $this->assertSame($user_name, $first[7]);
        $this->assertSame($user_name, $first[8]);
    }

    public function testCommentsAreNotExportedWithoutTheOption(): void
    {
        $custom_value = $this->getTargetValue();
        $this->addComment($custom_value, self::TABLE_NAME, self::COMMENT_FIRST);

        $sheets = $this->export([]);

        $this->assertArrayHasKey(self::TABLE_NAME, $sheets);
        $this->assertArrayNotHasKey(SystemTableName::COMMENT, $sheets);
    }

    public function testCommentsAreNotExportedWhenTheTableDoesNotUseComments(): void
    {
        $custom_value = $this->getTargetValue();
        $this->addComment($custom_value, self::TABLE_NAME, self::COMMENT_FIRST);
        $this->setCommentFlg(false);

        $sheets = $this->export(['--add_comment' => '1']);

        $this->assertArrayHasKey(self::TABLE_NAME, $sheets);
        $this->assertArrayNotHasKey(SystemTableName::COMMENT, $sheets);
    }

    public function testExportDialogHasAllWithComments(): void
    {
        $this->setCommentFlg(true);

        $response = $this->get(admin_urls('data', self::TABLE_NAME));
        $response->assertStatus(200);

        $this->assertStringContainsString('add_comment=1', (string) $response->getContent());
    }

    public function testExportDialogHasNoCommentsItemWhenTheTableDoesNotUseComments(): void
    {
        $this->setCommentFlg(false);

        $response = $this->get(admin_urls('data', self::TABLE_NAME));
        $response->assertStatus(200);

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('add_comment=1', $content);
        // the other items of the dialog are still there
        $this->assertStringContainsString('action=export', $content);
    }

    /**
     * Export the table as xlsx by the command, and get the rows of every sheet.
     *
     * @param array<string, string> $params
     * @return array<mixed>
     */
    private function export(array $params): array
    {
        $result = \Illuminate\Support\Facades\Artisan::call('exment:export', array_merge([
            'table_name' => self::TABLE_NAME,
            '--format' => 'xlsx',
            '--dirpath' => $this->dirpath,
        ], $params));
        $this->assertSame(0, $result);

        $file_path = path_join($this->dirpath, self::TABLE_NAME . '.xlsx');
        $this->assertTrue(\Illuminate\Support\Facades\File::exists($file_path));

        return $this->_getXlsxArray($file_path);
    }

    /**
     * The rows of a sheet, as lists of cells.
     *
     * @param mixed $sheet
     * @return array<int, array<int, mixed>>
     */
    private function rows($sheet): array
    {
        $this->assertIsArray($sheet);

        $rows = [];
        foreach ($sheet as $row) {
            $this->assertIsArray($row);
            $rows[] = array_values($row);
        }

        return $rows;
    }

    private function getTargetValue(): CustomValue
    {
        $custom_table = CustomTable::getEloquent(self::TABLE_NAME);
        $this->assertNotNull($custom_table);
        /** @var CustomValue|null $custom_value */
        $custom_value = $custom_table->getValueModel()->query()->orderBy('id')->first();
        $this->assertNotNull($custom_value);

        return $custom_value;
    }

    /**
     * Add a comment, and get the name of the user who wrote it.
     *
     * @return mixed
     */
    private function addComment(CustomValue $custom_value, string $parent_type, string $detail)
    {
        $comment_table = CustomTable::getEloquent(SystemTableName::COMMENT);
        $this->assertNotNull($comment_table);

        $comment = $comment_table->getValueModel();
        $comment->parent_id = $custom_value->id;
        $comment->parent_type = $parent_type;
        $comment->setValue(['comment_detail' => $detail]);
        $comment->save();

        return $comment->created_user;
    }

    private function setCommentFlg(bool $flg): void
    {
        $custom_table = CustomTable::getEloquent(self::TABLE_NAME);
        $this->assertNotNull($custom_table);
        $custom_table->setOption('comment_flg', $flg ? '1' : '0');
        $custom_table->save();
        System::clearCache();
    }
}
