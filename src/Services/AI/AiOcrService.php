<?php

namespace Exceedone\Exment\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;
use Exception;

class AiOcrService
{
    protected string $ocrServerUrl = 'http://Exment-AI-Server.example.com/api/ocr-process';

    public function processFile($file, string $tableKey, $columns): array
    {
        $columnsInfo = $this->getCustomColumns($columns);

        if (empty($columnsInfo)) {
            return [
                'file' => $file->getClientOriginalName(),
                'results' => [],
                'message' => 'No columns to process.',
            ];
        }

        $response = Http::attach(
            'document',
            file_get_contents($file->getRealPath()),
            $file->getClientOriginalName()
        )->post($this->ocrServerUrl, [
            'table'   => $tableKey,
            'columns' => $columnsInfo,
        ]);

        if ($response->failed()) {
            throw new Exception("OCR server error: " . $response->body());
        }

        return $response->json();
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
            return [
                'column_name'         => $column->column_name,
                'column_type'         => $column->column_type,
                'ocr_search_keyword'  => $column->options['ocr_search_keyword'] ?? null,
                'ocr_extraction_role' => $column->options['ocr_extraction_role'] ?? null,
            ];
        })->toArray();
    }
}
