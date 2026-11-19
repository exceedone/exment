<?php

namespace Exceedone\Exment\Tests\Unit;

use ExmentAdminCore\Admin\Form\Field;
use ExmentAdminCore\Admin\Form\Field\MultipleFile;

/**
 * Order of the saved files after a drag in the upload widget (L-38).
 *
 * The widget posts the preview keys of the files in their new order (_file_sort_[column]):
 * the index of each file by default, as in v6, or the key the field's fileIndex() gives
 * (Exment file columns use the file uuid). sortFiles() only compared the order with the
 * file paths, so a valid drag saved without changing anything.
 * These tests do not touch the database.
 */
class MultipleFileSortOrderTest extends UnitTestBase
{
    protected function tearDown(): void
    {
        request()->offsetUnset(Field::FILE_SORT_FLAG);

        parent::tearDown();
    }

    /**
     * Index keys (default, v6 behaviour): "2,0,1" puts the third file first.
     */
    public function testIndexOrderReordersTheFiles(): void
    {
        $field = $this->makeField(['a.txt', 'b.txt', 'c.txt']);

        $this->assertSame(['c.txt', 'a.txt', 'b.txt'], $this->sort($field, '2,0,1'));
    }

    /**
     * The same order as v6.2.15 for the two-file case of the evidence (mail_template.attachments "1,0").
     */
    public function testTwoFilesReversedLikeV6(): void
    {
        $field = $this->makeField(['first.pdf', 'second.pdf']);

        $this->assertSame(['second.pdf', 'first.pdf'], $this->sort($field, '1,0'));
    }

    /**
     * Keys given by fileIndex() (Exment file columns post the file uuid).
     */
    public function testFileIndexKeysReorderTheFiles(): void
    {
        $field = $this->makeField(['dir/a.txt', 'dir/b.txt', 'dir/c.txt']);
        $field->fileIndex(function ($index, $file) {
            return 'uuid-' . basename($file, '.txt');
        });

        $this->assertSame(['dir/b.txt', 'dir/c.txt', 'dir/a.txt'], $this->sort($field, 'uuid-b,uuid-c,uuid-a'));
    }

    /**
     * The file paths themselves are still accepted (existing MultipleFileSortFlagTest).
     */
    public function testPathOrderStillWorks(): void
    {
        $field = $this->makeField(['a.txt', 'b.txt', 'c.txt']);

        $this->assertSame(['b.txt', 'c.txt', 'a.txt'], $this->sort($field, 'b.txt,c.txt,a.txt'));
    }

    /**
     * Files the order does not name are kept, after the named ones: a sort never loses a file.
     */
    public function testFilesMissingFromTheOrderAreKept(): void
    {
        $field = $this->makeField(['a.txt', 'b.txt', 'c.txt']);

        $this->assertSame(['c.txt', 'a.txt', 'b.txt'], $this->sort($field, '2'));
    }

    /**
     * A key given twice does not duplicate the file.
     */
    public function testDuplicatedKeyDoesNotDuplicateTheFile(): void
    {
        $field = $this->makeField(['a.txt', 'b.txt']);

        $this->assertSame(['b.txt', 'a.txt'], $this->sort($field, '1,1,0'));
    }

    /**
     * An unknown entry keeps the saved files as they are: nothing is added or dropped.
     */
    public function testUnknownEntryKeepsTheSavedOrder(): void
    {
        $field = $this->makeField(['a.txt', 'b.txt']);

        $this->assertSame(['a.txt', 'b.txt'], $this->sort($field, '1,../../.env'));
        $this->assertSame(['a.txt', 'b.txt'], $this->sort($field, '5,0'));
    }

    /**
     * Trailing / leading commas from the hidden input are ignored.
     */
    public function testCommasAroundTheOrderAreIgnored(): void
    {
        $field = $this->makeField(['a.txt', 'b.txt']);

        $this->assertSame(['b.txt', 'a.txt'], $this->sort($field, ',1,0,'));
    }

    /**
     * No saved file does not fail; files saved as a JSON string (how the value is stored) are sorted too.
     */
    public function testNoSavedFileAndJsonSavedFiles(): void
    {
        $this->assertSame([], $this->sort($this->makeField(null), '0'));
        $this->assertSame(['b.txt', 'a.txt'], $this->sort($this->makeField('["a.txt","b.txt"]'), '1,0'));
    }

    /**
     * Without the flag prepare() is unchanged: the sort branch is not used.
     */
    public function testWithoutSortFlagSortIsNotUsed(): void
    {
        $field = new class ('foo', ['Foo']) extends MultipleFile {
            /** @var bool */
            public $sorted = false;

            protected function sortFiles($order, $updated_files)
            {
                $this->sorted = true;
                return [];
            }
        };
        $field->setOriginal(['foo' => ['a.txt']]);
        $field->move('dummy');

        try {
            $field->prepare('a.txt');
        } catch (\Throwable $ex) {
            // the upload branch needs storage; only the branch choice matters here
        }

        $this->assertFalse($field->sorted);
    }

    /**
     * @param mixed $files
     */
    protected function makeField($files): MultipleFile
    {
        $field = new MultipleFile('foo', ['Foo']);
        $field->setOriginal(['foo' => $files]);

        return $field;
    }

    /**
     * Run prepare() as the form does when the widget posted a new order.
     *
     * @return mixed
     */
    protected function sort(MultipleFile $field, string $order)
    {
        request()->merge([Field::FILE_SORT_FLAG => ['foo' => $order]]);

        return $field->prepare($order);
    }
}
