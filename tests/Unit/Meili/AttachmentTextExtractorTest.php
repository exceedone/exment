<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextConfig;
use Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextExtractor;
use Exceedone\Exment\Services\Meili\AttachmentText\ExtractionResult;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;

class AttachmentTextExtractorTest extends TestCase
{
    /** @var string[] */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testExtractsTextFromPdf(): void
    {
        $file = $this->temporaryFile('pdf', $this->simplePdf('MEILI PDF KEYWORD'));

        $result = $this->extractor()->extractPath($file, 'report.pdf');

        $this->assertSame(ExtractionResult::READY, $result->status);
        $this->assertStringContainsString('MEILI PDF KEYWORD', (string) $result->text);
        $this->assertSame('smalot/pdfparser', $result->parser);
        $this->assertSame(1, $result->metadata['page_count']);
    }

    public function testReturnsNoExtractableTextWithoutClaimingThePdfIsAScan(): void
    {
        $file = $this->temporaryFile('pdf', $this->simplePdf('   '));

        $result = $this->extractor()->extractPath($file, 'image-only.pdf');

        $this->assertSame(ExtractionResult::NO_EXTRACTABLE_TEXT, $result->status);
        $this->assertNull($result->text);
        $this->assertNull($result->errorCode);
    }

    public function testUnreadablePdfIsNotClassifiedAsPasswordProtected(): void
    {
        $file = $this->temporaryFile('pdf', "%PDF-1.7\nnot a valid PDF");

        $result = $this->extractor()->extractPath($file, 'unreadable.pdf');

        $this->assertSame(ExtractionResult::FAILED, $result->status);
        $this->assertSame('pdf_unreadable', $result->errorCode);
        $this->assertNotSame(ExtractionResult::PASSWORD_PROTECTED, $result->status);
    }

    public function testExtractsDocxBodyAndHeaderText(): void
    {
        $file = $this->temporaryZip('docx', [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'word/document.xml' => $this->wordXml('MEILI DOCX 本文'),
            'word/header1.xml' => $this->wordXml('MEILI DOCX HEADER'),
        ]);

        $result = $this->extractor()->extractPath($file, 'report.docx');

        $this->assertSame(ExtractionResult::READY, $result->status);
        $this->assertStringContainsString('MEILI DOCX 本文', (string) $result->text);
        $this->assertStringContainsString('MEILI DOCX HEADER', (string) $result->text);
        $this->assertSame(2, $result->metadata['part_count']);
    }

    public function testExtractsPptxInPresentationOrderWithSlideAndNotesMarkers(): void
    {
        $file = $this->temporaryZip('pptx', [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst><p:sldId id="256" r:id="rId2"/><p:sldId id="257" r:id="rId1"/></p:sldIdLst></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="slides/slide1.xml"/><Relationship Id="rId2" Target="slides/slide2.xml"/></Relationships>',
            'ppt/slides/slide1.xml' => $this->slideXml('Slide one title'),
            'ppt/slides/slide2.xml' => $this->slideXml('Slide two title'),
            'ppt/slides/_rels/slide2.xml.rels' => $this->notesRelationshipXml('../notesSlides/notesSlide7.xml'),
            'ppt/notesSlides/notesSlide2.xml' => $this->slideXml('WRONG NOTES FROM ANOTHER SLIDE'),
            'ppt/notesSlides/notesSlide7.xml' => $this->slideXml('MEILI PPTX RELATIONSHIP NOTES'),
        ]);

        $result = $this->extractor()->extractPath($file, 'deck.pptx');

        $this->assertSame(ExtractionResult::READY, $result->status);
        $this->assertStringContainsString('[Slide 1: Slide two title]', (string) $result->text);
        $this->assertStringContainsString('[Notes for Slide 1]', (string) $result->text);
        $this->assertStringContainsString('MEILI PPTX RELATIONSHIP NOTES', (string) $result->text);
        $this->assertStringNotContainsString('WRONG NOTES FROM ANOTHER SLIDE', (string) $result->text);
        $this->assertLessThan(
            strpos((string) $result->text, 'Slide one title'),
            strpos((string) $result->text, 'Slide two title'),
            'slides must follow presentation.xml ordering, not the ZIP filename order',
        );
    }

    public function testExtractsNotesForASlideWithoutVisibleTextViaRelationship(): void
    {
        $file = $this->temporaryZip('pptx', [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="slides/slide2.xml"/></Relationships>',
            'ppt/slides/slide2.xml' => $this->slideXml(''),
            'ppt/slides/_rels/slide2.xml.rels' => $this->notesRelationshipXml('../notesSlides/notesSlide7.xml'),
            'ppt/notesSlides/notesSlide7.xml' => $this->slideXml('MEILI NOTES ONLY KEYWORD'),
        ]);

        $result = $this->extractor()->extractPath($file, 'notes-only.pptx');

        $this->assertSame(ExtractionResult::READY, $result->status);
        $this->assertStringContainsString('[Notes for Slide 1]', (string) $result->text);
        $this->assertStringContainsString('MEILI NOTES ONLY KEYWORD', (string) $result->text);
        $this->assertStringNotContainsString('[Slide 1:', (string) $result->text);
    }

    public function testXlsxPreservesFormattedAndRawNumericValues(): void
    {
        $file = $this->temporaryFile('xlsx');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('売上');
        $sheet->setCellValue('A1', 'MEILI XLSX 日本語');
        $sheet->setCellValue('A2', 1234.5);
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->setCellValue('A3', Date::PHPToExcel(new \DateTimeImmutable('2026-09-23')));
        $sheet->getStyle('A3')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->setCellValueExplicit('A4', '00123', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        (new XlsxWriter($spreadsheet))->save($file);
        $spreadsheet->disconnectWorksheets();

        $result = $this->extractor()->extractPath($file, 'sales.xlsx');

        $this->assertSame(ExtractionResult::READY, $result->status);
        $this->assertStringContainsString('[Sheet: 売上]', (string) $result->text);
        $this->assertStringContainsString('MEILI XLSX 日本語', (string) $result->text);
        $this->assertStringContainsString('1,234.50 1234.5', (string) $result->text);
        $this->assertStringContainsString('2026-09-23', (string) $result->text);
        $this->assertStringContainsString('00123', (string) $result->text);
    }

    public function testRejectsXlsxZipEntryLimitBeforePhpSpreadsheetLoadsTheWorkbook(): void
    {
        $file = $this->temporaryZip('xlsx', [
            'xl/workbook.xml' => '<workbook/>',
            'xl/worksheets/sheet1.xml' => '<worksheet/>',
        ]);

        $result = (new AttachmentTextExtractor(new AttachmentTextConfig(maxZipEntries: 1)))
            ->extractPath($file, 'entry-limit.xlsx');

        $this->assertSame(ExtractionResult::FAILED, $result->status);
        $this->assertSame('office_package_entry_limit', $result->errorCode);
    }

    public function testRejectsXlsxUncompressedAndCompressionRatioLimitsBeforeLoad(): void
    {
        $uncompressed = $this->temporaryZip('xlsx', [
            'xl/workbook.xml' => str_repeat('A', 1024),
        ]);
        $uncompressedResult = (new AttachmentTextExtractor(new AttachmentTextConfig(
            maxZipEntries: 10,
            maxZipUncompressedBytes: 32,
        )))->extractPath($uncompressed, 'uncompressed-limit.xlsx');

        $compressed = $this->temporaryZip('xlsx', [
            'xl/workbook.xml' => str_repeat('A', 2048),
        ]);
        $compressedResult = (new AttachmentTextExtractor(new AttachmentTextConfig(
            maxZipEntries: 10,
            maxZipUncompressedBytes: 4096,
            maxZipCompressionRatio: 1,
        )))->extractPath($compressed, 'compression-ratio.xlsx');

        $this->assertSame(ExtractionResult::FAILED, $uncompressedResult->status);
        $this->assertSame('office_package_uncompressed_limit', $uncompressedResult->errorCode);
        $this->assertSame(ExtractionResult::FAILED, $compressedResult->status);
        $this->assertSame('office_package_compression_ratio', $compressedResult->errorCode);
    }

    public function testRejectsZipPathTraversalAndUnsupportedExtensions(): void
    {
        $file = $this->temporaryZip('docx', [
            'word/document.xml' => $this->wordXml('MEILI'),
            '../unexpected.xml' => '<x/>',
        ]);
        $unsafe = $this->extractor()->extractPath($file, 'unsafe.docx');
        $unsupportedFile = $this->temporaryFile('json', '{"secret":"not indexed"}');
        $unsupported = $this->extractor()->extractPath($unsupportedFile, 'data.json');

        $this->assertSame(ExtractionResult::FAILED, $unsafe->status);
        $this->assertSame('office_package_invalid', $unsafe->errorCode);
        $this->assertSame(ExtractionResult::UNSUPPORTED, $unsupported->status);
        $this->assertSame('unsupported_extension', $unsupported->errorCode);
    }

    public function testTruncatesAtUtf8CharacterBoundary(): void
    {
        $file = $this->temporaryZip('docx', [
            'word/document.xml' => $this->wordXml('あいうえお'),
        ]);
        $extractor = new AttachmentTextExtractor(new AttachmentTextConfig(maxCharacters: 3));

        $result = $extractor->extractPath($file, 'short.docx');

        $this->assertSame(ExtractionResult::READY, $result->status);
        $this->assertSame('あいう', $result->text);
        $this->assertSame(3, $result->charCount);
        $this->assertTrue($result->truncated);
    }

    public function testRejectsFilesAboveTheExtractionSizeLimit(): void
    {
        $file = $this->temporaryFile('pdf', $this->simplePdf('MEILI SIZE LIMIT'));
        $extractor = new AttachmentTextExtractor(new AttachmentTextConfig(maxBytes: 16));

        $result = $extractor->extractPath($file, 'large.pdf');

        $this->assertSame(ExtractionResult::TOO_LARGE, $result->status);
        $this->assertSame('file_size_limit', $result->errorCode);
    }

    private function extractor(): AttachmentTextExtractor
    {
        return new AttachmentTextExtractor();
    }

    private function temporaryFile(string $extension, string $contents = ''): string
    {
        $file = tempnam(sys_get_temp_dir(), 'exment-meili-');
        self::assertNotFalse($file);
        $target = $file . '.' . $extension;
        rename($file, $target);
        file_put_contents($target, $contents);
        $this->temporaryFiles[] = $target;

        return $target;
    }

    /** @param array<string,string> $entries */
    private function temporaryZip(string $extension, array $entries): string
    {
        $file = $this->temporaryFile($extension);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        $zip->close();

        return $file;
    }

    private function wordXml(string $text): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p></w:body></w:document>';
    }

    private function slideXml(string $text): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
    }

    private function notesRelationshipXml(string $target): string
    {
        return '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdNotes" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="' . htmlspecialchars($target, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/></Relationships>';
    }

    private function simplePdf(string $text): string
    {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 12 Tf 72 720 Td ({$escaped}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $number = $index + 1;
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
