<?php

namespace Exceedone\Exment\Services\Meili\AttachmentText;

/**
 * Lightweight OOXML reader. We only need searchable text, so parsing XML parts
 * directly is safer and substantially smaller than loading a Word/PowerPoint
 * object graph.
 */
final class OoxmlTextExtractor
{
    /**
     * @return array{text:string,parser:string,metadata:array<string,int>}
     */
    public function extract(string $path, string $format, AttachmentTextConfig $config): array
    {
        $entries = OoxmlPackageValidator::entries($path, $config);

        if ($format === 'docx') {
            $parts = array_values(array_filter($entries, fn ($entry) => preg_match('#^word/(document|header[^/]*|footer[^/]*|footnotes|endnotes|comments)\.xml$#', $entry) === 1));
            sort($parts, SORT_NATURAL);
            $text = $this->readParts($path, $parts, ['p', 'tr', 'tc']);

            return ['text' => $text, 'parser' => 'ooxml/xmlreader', 'metadata' => ['part_count' => count($parts)]];
        }

        $slides = $this->presentationSlides($path, $entries);
        $chunks = [];
        foreach ($slides as $index => $slide) {
            $slideText = trim($this->readXmlText($path, $slide, ['p']));
            $notes = $this->notesForSlide($path, $slide, $entries);
            $noteText = $notes === null ? '' : trim($this->readXmlText($path, $notes, ['p']));
            if ($slideText === '' && $noteText === '') {
                continue;
            }

            if ($slideText !== '') {
                $title = trim((string) strtok($slideText, "\n"));
                $chunks[] = sprintf('[Slide %d%s]\n%s', $index + 1, $title === '' ? '' : ': ' . $title, $slideText);
            }
            if ($noteText !== '') {
                $chunks[] = sprintf('[Notes for Slide %d]\n%s', $index + 1, $noteText);
            }
        }

        return ['text' => implode("\n\n", $chunks), 'parser' => 'ooxml/xmlreader', 'metadata' => ['slide_count' => count($slides)]];
    }

    /** @param string[] $parts @param string[] $blockElements */
    private function readParts(string $path, array $parts, array $blockElements): string
    {
        return implode("\n\n", array_map(fn ($part) => $this->readXmlText($path, $part, $blockElements), $parts));
    }

    /** @param string[] $blockElements */
    private function readXmlText(string $path, string $entry, array $blockElements): string
    {
        $reader = $this->xmlReader($path, $entry);

        try {
            $text = '';
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if (in_array($reader->localName, $blockElements, true) && $text !== '' && !str_ends_with($text, "\n")) {
                    $text .= "\n";
                }
                if ($reader->localName === 't') {
                    $text .= $reader->readString();
                }
            }

            return $text;
        } finally {
            $reader->close();
        }
    }

    /** @param string[] $entries @return string[] */
    private function presentationSlides(string $path, array $entries): array
    {
        $fallback = array_values(array_filter($entries, fn ($entry) => preg_match('#^ppt/slides/slide\d+\.xml$#', $entry) === 1));
        usort($fallback, 'strnatcmp');

        if (!in_array('ppt/presentation.xml', $entries, true) || !in_array('ppt/_rels/presentation.xml.rels', $entries, true)) {
            return $fallback;
        }

        $ids = $this->relationshipIds($path, 'ppt/presentation.xml', 'sldId');
        $relationships = $this->relationships($path, 'ppt/_rels/presentation.xml.rels');
        $slides = [];
        foreach ($ids as $id) {
            $target = $relationships[$id] ?? null;
            if ($target === null) {
                continue;
            }
            $entry = 'ppt/' . ltrim($target, '/');
            if (in_array($entry, $entries, true)) {
                $slides[] = $entry;
            }
        }

        return $slides ?: $fallback;
    }

    /** @return string[] */
    private function relationshipIds(string $path, string $entry, string $element): array
    {
        try {
            $reader = $this->xmlReader($path, $entry);
        } catch (\RuntimeException $exception) {
            return [];
        }

        try {
            $ids = [];
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === $element) {
                    $id = $reader->getAttribute('r:id') ?: $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    if (is_string($id) && $id !== '') {
                        $ids[] = $id;
                    }
                }
            }
            return $ids;
        } finally {
            $reader->close();
        }
    }

    /** @return array<string,string> */
    private function relationships(string $path, string $entry): array
    {
        try {
            $reader = $this->xmlReader($path, $entry);
        } catch (\RuntimeException $exception) {
            return [];
        }

        try {
            $relationships = [];
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Relationship') {
                    continue;
                }
                $id = $reader->getAttribute('Id');
                $target = $reader->getAttribute('Target');
                if (is_string($id) && is_string($target) && $id !== '' && $target !== '' && !str_contains($target, '..')) {
                    $relationships[$id] = $target;
                }
            }
            return $relationships;
        } finally {
            $reader->close();
        }
    }

    /** @param string[] $entries */
    private function notesForSlide(string $path, string $slide, array $entries): ?string
    {
        $relationshipPart = dirname($slide) . '/_rels/' . basename($slide) . '.rels';
        if (!in_array($relationshipPart, $entries, true)) {
            return null;
        }

        $target = $this->relationshipTargetByType($path, $relationshipPart, 'notesSlide');
        if ($target === null) {
            return null;
        }
        $notes = $this->resolveRelativeTarget($slide, $target);

        return $notes !== null && in_array($notes, $entries, true) ? $notes : null;
    }

    private function relationshipTargetByType(string $path, string $entry, string $typeSuffix): ?string
    {
        try {
            $reader = $this->xmlReader($path, $entry);
        } catch (\RuntimeException $exception) {
            return null;
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Relationship') {
                    continue;
                }
                $type = $reader->getAttribute('Type');
                $target = $reader->getAttribute('Target');
                $mode = $reader->getAttribute('TargetMode');
                if (is_string($type)
                    && str_ends_with($type, '/' . $typeSuffix)
                    && is_string($target)
                    && $target !== ''
                    && $mode !== 'External') {
                    return $target;
                }
            }
            return null;
        } finally {
            $reader->close();
        }
    }

    /** Resolve an internal OPC relationship target without escaping the package root. */
    private function resolveRelativeTarget(string $sourcePart, string $target): ?string
    {
        if ($target === '' || str_contains($target, "\0") || str_starts_with($target, '/') || preg_match('#^[A-Za-z]:[\\/]#', $target)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', dirname($sourcePart)), fn ($part) => $part !== '' && $part !== '.'));
        foreach (explode('/', str_replace('\\', '/', $target)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (empty($segments)) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            if (str_contains($part, "\0")) {
                return null;
            }
            $segments[] = $part;
        }

        $resolved = implode('/', $segments);
        return str_starts_with($resolved, 'ppt/') ? $resolved : null;
    }

    private function xmlReader(string $path, string $entry): \XMLReader
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('office_package_unreadable');
        }
        try {
            $xml = $zip->getFromName($entry);
        } finally {
            $zip->close();
        }
        if (!is_string($xml)) {
            throw new \RuntimeException('office_xml_unreadable');
        }

        $reader = new \XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \RuntimeException('office_xml_unreadable');
        }

        return $reader;
    }
}
