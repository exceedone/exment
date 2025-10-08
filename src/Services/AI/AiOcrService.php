<?php

namespace Exceedone\Exment\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;

class AiOcrService
{
    protected string $ocrServerUrl = 'https://exment.org/api/ocr/parse-bbox';
    protected string $bearerToken  = '4|mMD8liCRxqrwmEh6QnivJvhpjrW712OlMxNRiO8B0cbc0302';

    public function processFile($file, string $tableKey, $columns): array
    {
        $columnsInfo = $this->getCustomColumns($columns);

        if (empty($columnsInfo)) {
            return [
                'file' => $file->getFilename(),
                'results' => [],
                'message' => 'No columns to process.',
            ];
        }

        try {
            // HTTP multipart form-data request
            $response = Http::withToken($this->bearerToken)
                ->attach(
                    'file',
                    fopen($file->getRealPath(), 'r'),
                    $file->getFilename()
                )
                ->post($this->ocrServerUrl, [
                    'fields' => json_encode($columnsInfo)
                ]);

            if ($response->successful()) {
                return [
                    'file' => $file->getFilename(),
                    'results' => $response->json(),
                    'message' => 'OK',
                ];
            } else {
                return [
                    'file' => $file->getFilename(),
                    'results' => [],
                    'message' => 'OCR API error: ' . $response->status(),
                ];
            }
        } catch (\Throwable $e) {
            return [
                'file' => $file->getFilename(),
                'results' => [],
                'message' => 'OCR API Exception: ' . $e->getMessage(),
            ];
        }
    }

    public function isValidOcrFile($file): bool
    {
        if (!$file) {
            return false;
        }

        $extension = null;

        if (method_exists($file, 'getClientOriginalExtension')) {
            $extension = strtolower($file->getClientOriginalExtension());
        } elseif (method_exists($file, 'getExtension')) {
            $extension = strtolower($file->getExtension());
        }

        if (!$extension) {
            return false;
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'pdf'];
        return in_array($extension, $allowedExtensions);
    }

    private function getCustomColumns($customColumns): array
    {
        $collection = $customColumns instanceof Collection
            ? $customColumns
            : collect($customColumns);

        if ($collection->isEmpty()) {
            return [];
        }

        return $collection->map(function ($column) {
            $keywordString = $column->options['ocr_search_keyword'] ?? '';
            $keywords = collect(explode(',', $keywordString))
                ->map(fn($kw) => trim($kw))
                ->filter()
                ->values()
                ->toArray();

            if (empty($keywords)) {
                return null;
            }

            return [
                'column_name' => $column->column_name,
                'value_type'  => $column->column_type ?? 'string',
                'keywords'    => $keywords,
                'position'    => $column->options['ocr_extraction_role'] ?? null,
                'default_value' => $column->options['ocr_default_value'] ?? null,
            ];
        })
        ->filter()
        ->values()
        ->toArray();
    }
}
