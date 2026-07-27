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
}
