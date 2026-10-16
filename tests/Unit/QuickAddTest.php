<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\ApiTableController;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;

/**
 * The quick add beside a select-table column creates a master record from a
 * label alone. It must still pass the checks a record created through the
 * api passes, or it leaves rows behind that the master's own form would have
 * refused.
 */
class QuickAddTest extends UnitTestBase
{
    use DatabaseTransactions;

    private const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * The label fills the table's label column (index_text in the fixture),
     * so once nothing else is required the quick add creates the record.
     *
     * @return void
     */
    public function testQuickAddCreatesTheRecordWhenTheLabelIsAllItNeeds()
    {
        $this->setColumnOptions('text', ['required' => '0']);

        [$status, $body] = $this->quickAdd('quick add label');

        $this->assertSame(200, $status, (string)json_encode($body));
        $this->assertTrue($body['created']);
        $this->assertSame('quick add label', $body['label']);
        $record = $this->table()->getValueModel()->query()->withoutGlobalScopes()->findOrFail((int)$body['id']);
        $this->assertSame('quick add label', $record->getValue('index_text'));
    }

    /**
     * The fixture also requires the text column, which the label does not
     * fill. The quick add is refused with that reason and stores nothing.
     *
     * @return void
     */
    public function testQuickAddRefusesARecordTheTableWouldNotAccept()
    {
        $before = $this->table()->getValueModel()->query()->withoutGlobalScopes()->count();

        [$status, $body] = $this->quickAdd('missing a required value');

        $this->assertSame(400, $status, (string)json_encode($body));
        $this->assertSame(ErrorCode::VALIDATION_ERROR()->getValue(), $body['code'] ?? null);
        $this->assertArrayHasKey('text', $body['errors'] ?? [], 'The missing required column must be named.');
        $this->assertSame(
            $before,
            $this->table()->getValueModel()->query()->withoutGlobalScopes()->count(),
            'No record may be created.'
        );
    }

    private function table(): CustomTable
    {
        return CustomTable::getEloquent(self::TABLE);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function setColumnOptions(string $column_name, array $options): void
    {
        $column = CustomColumn::getEloquent($column_name, $this->table());
        \DB::table('custom_columns')->where('id', $column->id)
            ->update(['options' => json_encode(array_merge($column->options ?? [], $options))]);
        System::clearCache();
    }

    /**
     * Post a label the way quickadd.js does.
     *
     * @return array{0: int, 1: array<mixed>}
     */
    private function quickAdd(string $label): array
    {
        System::clearRequestSession();
        $request = Request::create('/admin/webapi/' . self::TABLE . '/quickadd', 'POST', ['label' => $label]);
        $this->app->instance('request', $request);
        $response = (new ApiTableController($this->table(), $request))->quickAdd($request);

        return [$response->getStatusCode(), json_decode((string)$response->getContent(), true) ?? []];
    }
}
