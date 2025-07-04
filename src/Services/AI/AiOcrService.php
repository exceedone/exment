<?php

namespace Exceedone\Exment\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;
use Exception;

// Local Test
use Illuminate\Support\Facades\App;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;

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

//        try {
//            $response = Http::attach(
//                'document',
//                file_get_contents($file->getRealPath()),
//                $file->getClientOriginalName()
//            )->post($this->ocrServerUrl, [
//                'table'   => $tableKey,
//                'columns' => $columnsInfo,
//            ]);
//
//            if ($response->failed()) {
//                throw new Exception("OCR server error: " . $response->body());
//            }
//
//            return [
//                'file' => $file->getClientOriginalName(),
//                'results' => $response->json(),
//                'message' => 'OK',
//            ];
//        } catch (\Throwable $e) {
//            \Log::error('OCR API Error: ' . $e->getMessage());
//
//            return [
//                'file' => $file->getClientOriginalName(),
//                'results' => [],
//                'message' => 'OCR API Error: ' . $e->getMessage(),
//            ];
//        }

        // Local Test
        $uploadedFile = new UploadedFile(
            $file->getRealPath(),
            $file->getFilename(),
            mime_content_type($file->getRealPath()),
            null,
            true
        );

        $request = new Request(
            [],                         // GET
            ['fields' => json_encode($columnsInfo)], // POST
            [], [],                     // attributes, cookies
            ['file' => $uploadedFile]   // FILES
        );

        $request->files->set('file', $uploadedFile);

        try {
            $response = App::call('App\Http\Controllers\OcrController@extractFieldsWithBoundingBox', [
                'request' => $request,
            ]);

            return [
                'file' => $file->getFilename(),
                'results' => $response->getData(true),
                'message' => 'OK',
            ];
        } catch (\Throwable $e) {
            \Log::error('OCR controller Error: ' . $e->getMessage());

            return [
                'file' => $file->getFilename(),
                'results' => [],
                'message' => 'OCR controller Error: ' . $e->getMessage(),
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

            return [
                'column_name' => $column->column_name,
                'value_type'  => $column->column_type ?? 'string',
                'keywords'    => $keywords,
                'position'    => $column->options['ocr_extraction_role'] ?? null,
                'default_value' => $column->options['ocr_default_value'] ?? null,
            ];
        })->toArray();
    }
}
