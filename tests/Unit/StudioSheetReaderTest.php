<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Services\Studio\SheetImporter;
use Exceedone\Exment\Services\Studio\SheetReader;
use PHPUnit\Framework\TestCase;

/**
 * Reading an Excel or CSV file for the Studio builder: the guessed parts and
 * the values taken in (ISS00231 phase 2).
 */
class StudioSheetReaderTest extends TestCase
{
    /**
     * @return array<string, array{0: array<int, string>, 1: string}>
     */
    public static function guesses(): array
    {
        return [
            'mail' => [['a@example.com', 'b@example.co.jp'], ColumnType::EMAIL],
            'url' => [['https://exment.net', 'http://example.com/a?b=1'], ColumnType::URL],
            'date' => [['2026-11-21', '2026/1/5', '2026年12月1日'], ColumnType::DATE],
            'date and time' => [['2026-11-21 09:30', '2026/1/5 18:00:00'], ColumnType::DATETIME],
            'time' => [['09:30', '18:00:00'], ColumnType::TIME],
            'integer' => [['1', '-20', '1,234,567'], ColumnType::INTEGER],
            'decimal' => [['1.5', '20', '-0.25'], ColumnType::DECIMAL],
            'a code keeps its zero' => [['007', '012'], ColumnType::TEXT],
            'a long number is text' => [['1234567890123456'], ColumnType::TEXT],
            'yes or no' => [['はい', 'いいえ', 'はい'], ColumnType::YESNO],
            'long text' => [[str_repeat('あ', 120)], ColumnType::TEXTAREA],
            'text with a line break' => [["1行目\n2行目"], ColumnType::TEXTAREA],
            'a few values repeated' => [['見込み', '取引中', '見込み', '休止', '取引中', '見込み'], ColumnType::SELECT],
            'values not repeated' => [['山田', '佐藤', '鈴木'], ColumnType::TEXT],
            'empty' => [[], ColumnType::TEXT],
        ];
    }

    /**
     * @param array<int, string> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('guesses')]
    public function testThePartIsGuessedFromTheValues(array $values, string $type): void
    {
        $this->assertSame($type, SheetReader::guess($values)[0]);
    }

    public function testAChoiceListsItsValuesInTheOrderTheyCome(): void
    {
        $this->assertSame("見込み\n取引中\n休止", SheetReader::guess(['見込み', '取引中', '見込み', '休止', '取引中', '休止'])[1]);
    }

    public function testDates(): void
    {
        $this->assertSame('2026-01-05', SheetReader::toDateTime('2026/1/5', 'Y-m-d'));
        $this->assertSame('2026-12-01 09:05:00', SheetReader::toDateTime('2026年12月1日 9:05', 'Y-m-d H:i:s'));
        $this->assertNull(SheetReader::toDateTime('2026-02-30', 'Y-m-d'));
        $this->assertNull(SheetReader::toDateTime('2026-11-21', 'Y-m-d H:i:s'));
        $this->assertNull(SheetReader::toDateTime('21/11/2026', 'Y-m-d'));
    }

    public function testValuesThatDoNotFitThePartAreLeftEmpty(): void
    {
        $this->assertSame(1234567, SheetImporter::convert(ColumnType::INTEGER, '1,234,567'));
        $this->assertNull(SheetImporter::convert(ColumnType::INTEGER, '1.5'));
        $this->assertSame('1.5', SheetImporter::convert(ColumnType::DECIMAL, '1.5'));
        $this->assertSame('2026-11-21', SheetImporter::convert(ColumnType::DATE, '2026/11/21'));
        $this->assertSame('2026-11-21 00:00:00', SheetImporter::convert(ColumnType::DATETIME, '2026/11/21'));
        $this->assertSame('09:05:00', SheetImporter::convert(ColumnType::TIME, '9:05'));
        $this->assertSame(1, SheetImporter::convert(ColumnType::YESNO, '○'));
        $this->assertSame(0, SheetImporter::convert(ColumnType::YESNO, 'いいえ'));
        $this->assertNull(SheetImporter::convert(ColumnType::YESNO, 'たぶん'));
        $this->assertNull(SheetImporter::convert(ColumnType::EMAIL, 'not a mail'));
        $this->assertNull(SheetImporter::convert(ColumnType::USER, 'yamada'));
        $this->assertSame('見込み', SheetImporter::convert(ColumnType::SELECT, '見込み'));
    }

    public function testACsvSavedByExcelInShiftJisIsRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'studio') . '.csv';
        $csv = "会社名,区分,売上\r\n山田商事,見込み,\"1,200\"\r\n,,\r\n佐藤工業,\"取引中\r\n（重要）\",300\r\n";
        file_put_contents($path, mb_convert_encoding($csv, 'SJIS-win', 'UTF-8'));
        try {
            $sheet = SheetReader::read($path, 'csv');
        } finally {
            unlink($path);
        }

        $this->assertSame(['会社名', '区分', '売上'], $sheet['headers']);
        // the empty row is skipped, the line break in a quoted value is kept
        $this->assertSame([['山田商事', '見込み', '1,200'], ['佐藤工業', "取引中\n（重要）", '300']], $sheet['rows']);
        $this->assertFalse($sheet['truncated']);

        $fields = SheetReader::fields($sheet);
        $this->assertSame([0, 1, 2], array_column($fields, 'source'));
        $this->assertSame(['A', 'B', 'C'], array_column($fields, 'column'));
        $this->assertSame(ColumnType::INTEGER, $fields[2]['type']);
    }

    public function testAUtf8CsvWithABomIsRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'studio') . '.csv';
        file_put_contents($path, "\xEF\xBB\xBF名前,メール\n山田,yamada@example.com\n");
        try {
            $sheet = SheetReader::read($path, 'csv');
        } finally {
            unlink($path);
        }
        $this->assertSame(['名前', 'メール'], $sheet['headers']);
        $this->assertSame(ColumnType::EMAIL, SheetReader::fields($sheet)[1]['type']);
    }
}
