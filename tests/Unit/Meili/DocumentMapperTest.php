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
