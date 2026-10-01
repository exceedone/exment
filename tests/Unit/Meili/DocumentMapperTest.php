<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use PHPUnit\Framework\TestCase;

class DocumentMapperTest extends TestCase
{
    private DocumentMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new DocumentMapper();
    }

    public function testDocumentIdCombinesTableNameAndValueIdWithDoubleUnderscore(): void
    {
        $this->assertSame('products__42', $this->mapper->makeDocumentId('products', 42));
    }

    public function testDocumentIdSanitizesUnsafeCharactersInTableName(): void
    {
        // Meilisearch primary key only accepts [a-zA-Z0-9_-].
        $this->assertSame('order_items__7', $this->mapper->makeDocumentId('Order Items', 7));
    }

    public function testBuildDocumentAssemblesExpectedShape(): void
    {
        $doc = $this->mapper->buildDocument(
            'products',
            'Products',
            42,
            'iPhone 15 Pro',
            ['name' => 'iPhone 15 Pro', 'description' => 'High-end phone']
        );

        $this->assertSame([
            'id' => 'products__42',
            'value_id' => 42,
            'table_name' => 'products',
            'table_label' => 'Products',
            'label' => 'iPhone 15 Pro',
            'fields' => ['name' => 'iPhone 15 Pro', 'description' => 'High-end phone'],
            'attachments' => [],
        ], $doc);
    }

    public function testBuildDocumentDropsNullAndEmptyFieldValues(): void
    {
        $doc = $this->mapper->buildDocument(
            'products',
            'Products',
            42,
            'iPhone',
            ['name' => 'iPhone', 'description' => null, 'note' => '']
        );

        $this->assertSame(['name' => 'iPhone'], $doc['fields']);
    }

    public function testMapSkipsFileAndImageUrlsButKeepsStructuredAttachments(): void
    {
        $record = new class {
            public int $id = 7;
            public string $label = 'Customer 007';
            public array $readColumns = [];

            public function getValue($column, $label = false)
            {
                $this->readColumns[] = $column->column_name;
                if ($column->column_name === 'notes') {
                    return 'Active customer';
                }
                throw new \LogicException('File and image accessors must not be called');
            }
        };
        $columns = [
            (object) ['column_name' => 'file', 'column_type' => 'file'],
            (object) ['column_name' => 'files', 'column_type' => 'file'],
            (object) ['column_name' => 'avatar', 'column_type' => 'image'],
            (object) ['column_name' => 'notes', 'column_type' => 'textarea'],
        ];
        $attachments = [7 => ['attachments' => [
            ['file_uuid' => 'first', 'name' => 'report.pdf', 'text' => 'PDF report'],
            ['file_uuid' => 'second', 'name' => 'budget.xlsx', 'text' => 'Excel budget'],
        ]]];

        $doc = $this->mapper->map($record, $columns, 'customer', 'Customer', [], [], [], $attachments);

        $this->assertSame(['notes'], $record->readColumns);
        $this->assertSame(['notes' => 'Active customer'], $doc['fields']);
        $this->assertSame($attachments[7]['attachments'], $doc['attachments']);
        $this->assertStringNotContainsString('http://', json_encode($doc));
    }

    public function testMapDropsFileFieldsEvenWithoutAnAttachmentPayload(): void
    {
        $record = new class {
            public int $id = 8;
            public string $label = 'Customer 008';

            public function getValue($column, $label = false)
            {
                throw new \LogicException('File accessor must not be called');
            }
        };

        $doc = $this->mapper->map(
            $record,
            [(object) ['column_name' => 'file', 'column_type' => 'file']],
            'customer',
            'Customer',
            [],
            [],
            [],
            [8 => []]
        );

        $this->assertSame([], $doc['fields']);
        $this->assertSame([], $doc['attachments']);
        $this->assertArrayNotHasKey('attachment_names', $doc);
    }

    public function testFacetTokenUsesColumnNameAsPrefix(): void
    {
        $this->assertSame(['status=完了'], DocumentMapper::facetTokens('status', '完了'));
    }

    public function testFacetTokenAliasPrefixMergesColumns(): void
    {
        // Alias normalization: 2 differently named columns (status / contract_status)
        // sharing alias 'state' both produce the token "state=<value>" -> merged into one filter group.
        $this->assertSame(['state=完了'], DocumentMapper::facetTokens('state', '完了'));
        $this->assertSame(
            ['state=完了'],
            DocumentMapper::facetTokens('state', ['完了']),
        );
    }

    public function testFacetTokensSkipValuesThatAreNotScalar(): void
    {
        // A related record flattened by toArray() carries a nested `value` array.
        // Casting it to string is an E_WARNING that Laravel turns into an
        // ErrorException, which used to stop the whole reindex/sync job.
        $this->assertSame(
            ['manager=JapanAdmin', 'manager=1'],
            DocumentMapper::facetTokens('manager', ['JapanAdmin', ['customer' => 'x'], new \stdClass(), true]),
        );
    }

    public function testQualifyColumnKeepsTablesApart(): void
    {
        // column_name is not unique across tables, so an unaliased prefix carries
        // the table: two "status" columns must not collapse into one filter group.
        $this->assertSame('contract::status', DocumentMapper::qualifyColumn('contract', 'status'));
        $this->assertNotSame(
            DocumentMapper::qualifyColumn('contract', 'status'),
            DocumentMapper::qualifyColumn('customer', 'status'),
        );
    }

    public function testSplitColumnPrefixQualified(): void
    {
        $this->assertSame(
            ['table' => 'contract', 'column' => 'status'],
            DocumentMapper::splitColumnPrefix('contract::status'),
        );
    }

    public function testSplitColumnPrefixBareIsAnAlias(): void
    {
        // An aliased prefix has no qualifier -> no owning table.
        $this->assertSame(
            ['table' => null, 'column' => 'state'],
            DocumentMapper::splitColumnPrefix('state'),
        );
    }

    public function testRangeFieldIsTableQualified(): void
    {
        // Same reason as facet tokens: a bare n_amount would be ONE shared axis
        // for every table owning an `amount` column.
        $this->assertSame('n_contract::amount', DocumentMapper::rangeField('contract', 'amount'));
        $this->assertNotSame(
            DocumentMapper::rangeField('contract', 'amount'),
            DocumentMapper::rangeField('customer', 'amount'),
        );
    }

    /**
     * RANGE_FIELD_PATTERN is an injection guard: the field name is concatenated
     * into a Meilisearch filter expression, so it must admit nothing but
     * n_<table>::<column>.
     */
    public function testRangeFieldPatternRejectsAnythingButAQualifiedField(): void
    {
        $this->assertMatchesRegularExpression(
            DocumentMapper::RANGE_FIELD_PATTERN,
            DocumentMapper::rangeField('meili_contract', 'amount')
        );

        // Exment allows "-" in a table_name and a column_name, so a range field
        // built from such names must pass the guard (it used to be dropped, and
        // the filter silently did nothing).
        $this->assertMatchesRegularExpression(
            DocumentMapper::RANGE_FIELD_PATTERN,
            DocumentMapper::rangeField('Location-HardFuniture', 'Desk_HardFurniture')
        );
        $this->assertMatchesRegularExpression(
            DocumentMapper::RANGE_FIELD_PATTERN,
            DocumentMapper::rangeField('contract', 'unit-price')
        );

        foreach ([
            'n_amount',            // pre-qualification leftover
            'n_a::b OR 1=1',
            'n_a::b; DROP',
            'n_a::b" OR "1',       // tries to escape the quotes we wrap it in
            'n_a::b AND x',
            'nn_a::b',
            'n_::b',
            'n_a::',
            '',
        ] as $bad) {
            $this->assertDoesNotMatchRegularExpression(
                DocumentMapper::RANGE_FIELD_PATTERN,
                $bad,
                "should reject: {$bad}"
            );
        }
    }

    public function testSplitColumnPrefixOnlySplitsAtTheFirstQualifier(): void
    {
        // Defensive: neither table_name nor column_name can contain "::",
        // but the split must stay deterministic if one ever did.
        $this->assertSame(
            ['table' => 'contract', 'column' => 'a::b'],
            DocumentMapper::splitColumnPrefix('contract::a::b'),
        );
    }
}
