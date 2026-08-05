<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\ColumnItems\CustomColumns\Select;
use Exceedone\Exment\Model\CustomColumn;
use Tests\TestCase;

/**
 * Tests for Select column display-value decoding.
 *
 * Background: select values are stored as-is in DB. On display, any value
 * matching the regex /\[.+\]/ was unconditionally replaced by json_decode()'s
 * result. For option values that merely CONTAIN brackets (ex. "1,[テスト]")
 * the decode fails and returns null, so detail/list/edit showed a blank value.
 * Fix: only use the decoded result when it actually is an array.
 *
 * Files changed:
 *   - src/ColumnItems/CustomColumns/Select.php (commit fc7e523)
 */
class SelectRenderTest extends TestCase
{
    /**
     * Call protected Select::getResultForSelect() on an in-memory column
     * (no DB access).
     *
     * @param mixed  $value       stored value (string or array)
     * @param bool   $label       true: display text, false: raw value
     * @param string $select_item select options, one per line
     * @return mixed
     */
    protected function getResultForSelect($value, bool $label, string $select_item = "1,[テスト]\n2,[サンプル]")
    {
        $custom_column = new CustomColumn();
        $custom_column->column_type = 'select';
        $custom_column->options = ['select_item' => $select_item];

        $reflection = new \ReflectionClass(Select::class);
        /** @var Select $item */
        $item = $reflection->newInstanceWithoutConstructor();

        $property = new \ReflectionProperty($item, 'custom_column');
        $property->setValue($item, $custom_column);

        $method = new \ReflectionMethod($item, 'getResultForSelect');
        return $method->invoke($item, $value, $label);
    }

    // -----------------------------------------------------------------------
    // Bug-fix cases: single value containing brackets must not become blank
    // -----------------------------------------------------------------------

    /**
     * "1,[テスト]" matches /\[.+\]/ but is invalid JSON.
     * Before the fix: json_decode → null → displayed blank.
     * After the fix: the original string is kept.
     */
    public function testTextKeepsSingleValueContainingBrackets(): void
    {
        $this->assertSame('1,[テスト]', $this->getResultForSelect('1,[テスト]', true));
    }

    /**
     * Same as above for the value (label=false) path.
     */
    public function testValueKeepsSingleValueContainingBrackets(): void
    {
        $this->assertSame('1,[テスト]', $this->getResultForSelect('1,[テスト]', false));
    }

    /**
     * A value that is ONLY brackets but not valid JSON ("[テスト]" has an
     * unquoted string inside) must also be kept as-is.
     */
    public function testTextKeepsBracketOnlyNonJsonValue(): void
    {
        $this->assertSame('[テスト]', $this->getResultForSelect('[テスト]', true, "[テスト]\n[サンプル]"));
    }

    // -----------------------------------------------------------------------
    // Regression guard: real multiple-select JSON values still decode
    // -----------------------------------------------------------------------

    /**
     * Multiple select stores values as a JSON array string. label=false must
     * return the decoded array unchanged.
     */
    public function testValueDecodesJsonArrayString(): void
    {
        $this->assertSame(['a', 'b'], $this->getResultForSelect('["a","b"]', false, "a\nb"));
    }

    /**
     * label=true joins decoded multiple values with the separate word.
     */
    public function testTextImplodesJsonArrayString(): void
    {
        $expected = implode(exmtrans('common.separate_word'), ['a', 'b']);
        $this->assertSame($expected, $this->getResultForSelect('["a","b"]', true, "a\nb"));
    }

    /**
     * Multiple values whose options contain brackets (TC-25) decode and join.
     */
    public function testTextImplodesJsonArrayWithBracketValues(): void
    {
        $expected = implode(exmtrans('common.separate_word'), ['1,[テスト]', '2,[サンプル]']);
        $this->assertSame($expected, $this->getResultForSelect('["1,[テスト]","2,[サンプル]"]', true));
    }

    /**
     * An already-decoded array input passes through unchanged.
     */
    public function testValueKeepsArrayInput(): void
    {
        $this->assertSame(['a', 'b'], $this->getResultForSelect(['a', 'b'], false, "a\nb"));
    }

    // -----------------------------------------------------------------------
    // Regression guard: plain values unchanged
    // -----------------------------------------------------------------------

    /**
     * A value without brackets is returned as-is.
     */
    public function testTextKeepsPlainValue(): void
    {
        $this->assertSame('foo', $this->getResultForSelect('foo', true, "foo\nbar"));
    }

    /**
     * Null value stays null (no decode attempt).
     */
    public function testValueKeepsNull(): void
    {
        $this->assertNull($this->getResultForSelect(null, false));
    }

    // -----------------------------------------------------------------------
    // Documented limitation (pre-existing, unchanged by the fix)
    // -----------------------------------------------------------------------

    /**
     * A single value that IS valid JSON array syntax (ex. "[1,2]") is still
     * decoded and treated as multiple values. This behavior existed before
     * the fix and is kept intentionally; this test documents it.
     */
    public function testValidJsonArrayLiteralIsStillDecoded(): void
    {
        $this->assertSame([1, 2], $this->getResultForSelect('[1,2]', false, "[1,2]\n[3,4]"));
    }
}
