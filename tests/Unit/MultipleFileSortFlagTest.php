<?php

namespace Exceedone\Exment\Tests\Unit;

use ExmentAdminCore\Admin\Form\Field;
use ExmentAdminCore\Admin\Form\Field\MultipleFile;

/**
 * MultipleFile::prepare() with the _file_sort_ flag in the request.
 *
 * The flag made prepare() call sortFiles() without its second argument, and a request
 * carrying it ended in an ArgumentCountError (500). These tests do not touch the database.
 */
class MultipleFileSortFlagTest extends UnitTestBase
{
    protected function tearDown(): void
    {
        request()->offsetUnset(Field::FILE_SORT_FLAG);

        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testSortFlagReordersTheOriginalFiles()
    {
        $field = $this->makeField(['foo' => ['a.txt', 'b.txt', 'c.txt']]);
        request()->merge([Field::FILE_SORT_FLAG => ['foo' => 'c.txt,a.txt,b.txt']]);

        $this->assertSame(['c.txt', 'a.txt', 'b.txt'], $field->prepare('c.txt,a.txt,b.txt'));
    }

    /**
     * An order naming files the field does not have keeps the saved files as they are:
     * the sort never adds a value of its own.
     *
     * @return void
     */
    public function testSortFlagKeepsTheOriginalFilesForAnUnknownOrder()
    {
        $field = $this->makeField(['foo' => ['a.txt', 'b.txt']]);
        request()->merge([Field::FILE_SORT_FLAG => ['foo' => '../../.env,1,0']]);

        $this->assertSame(['a.txt', 'b.txt'], $field->prepare('../../.env,1,0'));
    }

    /**
     * @param array<string, mixed> $original
     * @return MultipleFile
     */
    protected function makeField(array $original): MultipleFile
    {
        $field = new MultipleFile('foo', ['Foo']);
        $field->setOriginal($original);

        return $field;
    }
}
