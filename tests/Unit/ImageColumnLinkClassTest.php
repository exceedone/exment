<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomColumns\Image;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;

/**
 * #1548: the link around the image of an image column (list and detail pages) has the class
 * "image-link", so a plugin script or style can find it (for a lightbox etc.).
 *
 * Nothing is saved: the column and the file are models that are not stored.
 */
class ImageColumnLinkClassTest extends UnitTestBase
{
    private const UUID = '0a1b2c3d-1548-4e5f-8a9b-0c1d2e3f4a5b';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testImageLinkHasTheImageLinkClass(): void
    {
        $url = admin_url('files/' . self::UUID);

        $html = $this->imageHtml();

        // <a href="(url)" class="image-link" target="_blank"><img ... /></a>
        $this->assertMatchesRegularExpression('#^\s*<a\s[^>]*class="image-link"[^>]*>#', $html);
        $this->assertMatchesRegularExpression('#^\s*<a\s[^>]*href="' . preg_quote($url, '#') . '"[^>]*>#', $html);
        $this->assertMatchesRegularExpression('#^\s*<a\s[^>]*target="_blank"[^>]*>#', $html);
        // the image itself is unchanged
        $this->assertStringContainsString('<img src="' . $url . '" class="mw-100 image_html" />', $html);
    }

    /**
     * The html of an image column for a stored image, as the list and detail pages print it.
     */
    private function imageHtml(): string
    {
        // a column that is not saved, attached to a system table that always exists
        $custom_table = CustomTable::getEloquent('information');
        $custom_column = new CustomColumn([
            'column_name' => 'issue1548_image',
            'column_view_name' => 'issue1548_image',
            'column_type' => ColumnType::IMAGE,
        ]);
        $custom_column->custom_table_id = $custom_table->id;
        $custom_column->options = [];
        $custom_column->setRelation('custom_table', $custom_table);

        // File::getUrl() takes the file model as it is, so no file has to be stored
        $file = new ExmentFile();
        $file->uuid = self::UUID;

        $item = new Image($custom_column, null);
        $method = new \ReflectionMethod(Image::class, '_html');
        $method->setAccessible(true);
        $html = $method->invoke($item, $file);
        $this->assertIsString($html);

        return $html;
    }
}
