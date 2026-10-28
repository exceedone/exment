<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * publicformapi/{form_key}/data/{tableKey}/... (PublicFormApiDataController), called as a guest
 * the way ApiAuthTest::testPublicFormApiAuthTrue does: the request runs as the public form's
 * proxy user. The API only serves the tables the form uses (its select_table targets and
 * parents), never the form's own table - so the calls read custom_value_edit through the
 * public form of all_columns_table_fortest. Every action delegates to ApiDataTrait and answers
 * json, never an export/download handler.
 */
class PublicFormApiDataCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /**
     * @return array{0: string, 1: string} [form_key, tableKey]
     */
    protected function publicFormAndTable(): array
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $custom_form = CustomForm::getDefault($custom_table);
        $public_form = PublicForm::where('custom_form_id', $custom_form->id)->first();
        $this->assertNotNull($public_form, 'seed data must have a public form for ' . TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);

        return [$public_form->uuid, TestDefine::TESTDATA_TABLE_NAME_EDIT];
    }

    public function testDataFind(): void
    {
        [$formKey, $tableKey] = $this->publicFormAndTable();

        $this->get("publicformapi/{$formKey}/data/{$tableKey}/5")
            ->assertStatus(200)
            ->assertJsonFragment(['id' => 5]);
    }

    public function testDataSelect(): void
    {
        [$formKey, $tableKey] = $this->publicFormAndTable();

        $this->get("publicformapi/{$formKey}/data/{$tableKey}/select?q=index_001_002")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function testDataSelectWithoutQIsAValidationError(): void
    {
        [$formKey, $tableKey] = $this->publicFormAndTable();

        $this->get("publicformapi/{$formKey}/data/{$tableKey}/select")
            ->assertStatus(400);
    }

    public function testColumnData(): void
    {
        [$formKey, $tableKey] = $this->publicFormAndTable();

        $response = $this->get("publicformapi/{$formKey}/data/{$tableKey}/column/index_text?query=index_001_002")
            ->assertStatus(200);
        $this->assertContains('index_001_002', $response->json());
    }

    public function testRelatedLinkageWithoutRequiredParamsReturnsEmptyArray(): void
    {
        [$formKey, $tableKey] = $this->publicFormAndTable();

        // No parent/child select-table params at all: _relatedLinkage() checks isset() before
        // anything else and answers [], it does not 400.
        $this->get("publicformapi/{$formKey}/data/{$tableKey}/relatedLinkage")
            ->assertStatus(200)
            ->assertExactJson([]);
    }
}
