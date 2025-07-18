<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Exceedone\Exment\Services\AI\AiOcrService;
use Exceedone\Exment\Model\File as ExmentFile;
use Illuminate\Support\Facades\File;
use Exceedone\Exment\Model\CustomTable;

class AiOcrController extends AdminControllerTableBase
{
    protected $ocrService;

    public function __construct(?CustomTable $custom_table, Request $request, AiOcrService $ocrService)
    {
        parent::__construct($custom_table, $request);

        if (!$this->custom_table) {
            return;
        }

        $this->ocrService = $ocrService;

        $this->setPageInfo($this->custom_table->table_view_name, $this->custom_table->table_view_name, $this->custom_table->description, $this->custom_table->getOption('icon'));
    }

    public function runAiOcr(Request $request, $tableKey)
    {
        $filePath = $request->input('file_path');
        if (!($filePath && is_dir($filePath))) {
            return response()->json(['error' => 'Invalid file path'], 400);
        }

        $files = File::files($filePath);
        $file = $files[0] ?? null;

        if (!$this->ocrService->isValidOcrFile($file)) {
            return response()->json(['error' => 'Invalid file format'], 400);
        }

        try {
            $result = $this->ocrService->processFile($file, $tableKey, $this->custom_columns);

            $local_filename = pathinfo($file, PATHINFO_BASENAME);
            $this->saveFileOptions($local_filename, $result['results']);

            return response()->json([
                'message' => 'OCR completed',
                'result'  => $result['results'],
            ]);
        } catch (\Exception $ex) {
            \Log::error("OCR failed: " . $ex->getMessage());

            return response()->json([
                'message' => 'OCR processing failed',
                'detail' => $ex->getMessage(),
            ], 500);
        }
    }

    public function runMultiAiOcr(Request $request, $tableKey)
    {
        $filesPath = $request->input('files_path');
        if (!($filesPath && is_dir($filesPath))) {
            return response()->json(['error' => 'Invalid path'], 400);
        }

        $files = File::files($filesPath);
        $customColumns = $this->custom_columns;

        $allResults = [];
        $succeedOcrFilesCount = 0;
        $failedOcrFilesCount = 0;
        $failedOcrFileNameList = [];
        foreach ($files as $file) {
            if (!$this->ocrService->isValidOcrFile($file)) {
                continue;
            }

            try {
                $result = $this->ocrService->processFile($file, $tableKey, $customColumns);

                $local_filename = pathinfo($file, PATHINFO_BASENAME);
                $this->saveFileOptions($local_filename, $result['results']);

                $modelClass = get_class($this->custom_table->getValueModel());
                $new_record = new $modelClass();

                $this->createCustomRecord($new_record, $result['results'], $filesPath, $file);

                $allResults[] = [
                    'file' => $file->getFilename(),
                    'status' => 'success',
                    'results' => $result['results'],
                ];

                $succeedOcrFilesCount++;
            } catch (\Exception $ex) {
                \Log::error("OCR failed for file {$file->getFilename()}: " . $ex->getMessage());

                $allResults[] = [
                    'file' => $file->getFilename(),
                    'status' => 'error',
                    'error' => $ex->getMessage(),
                ];

                $failedOcrFilesCount++;
                $failedOcrFileNameList[] = $file->getFilename();
            }
        }

        $this->deleteIfEmptyTempDirectory($filesPath);

        return response()->json([
            'message' => 'Multi OCR completed',
            'succeedOcrFilesCount' => $succeedOcrFilesCount,
            'failedOcrFilesCount' => $failedOcrFilesCount,
            'failedOcrFileNameList' => $failedOcrFileNameList,
        ]);
    }


    private function saveFileOptions($local_filename, $aiOcrResult) {
        $fileModel = ExmentFile::where('local_filename', $local_filename)->first();
        if ($fileModel) {
            $fileModel->options = array_merge($fileModel->options ?? [], $aiOcrResult);
            $fileModel->save();
        }
    }

    private function createCustomRecord($new_record, $aiOcrResult, $tempPath, $file)
    {
        try {
            \ExmentDB::transaction(function () use ($new_record, $aiOcrResult, $tempPath, $file) {
                $valueHash = [];
                foreach ($aiOcrResult as $column => $field) {
                    $value = $field['value'];
                    $valueHash[$column] = $value;
                }
                $new_record->value = $valueHash;
                $new_record->save();

                $this->moveAiOcrFileAndLink($new_record, $tempPath, $file);

                // storeJancode
                // updateRelation
                // callSavedInTransaction
            });
        } catch (\Exception $ex) {
            \Log::error($ex);
            throw $ex;
        }
    }

    // Move AI-OCR temporary files into their final folder, update their database records, and link them to the main record
    private function moveAiOcrFileAndLink($custom_record, $tempPath, $file)
    {
        if (!$custom_record) {
            return;
        }

        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $tempPath);
        $parts = explode(DIRECTORY_SEPARATOR, $normalizedPath);
        $aiOcrIndex = array_search('ai_ocr_temp', $parts);
        if ($aiOcrIndex === false || !isset($parts[$aiOcrIndex + 2])) {
            return;
        }

        $tempFilename = $file->getFilename();
        $local_filename = pathinfo($tempFilename, PATHINFO_BASENAME);
        $fileModel = ExmentFile::where('local_filename', $local_filename)->first();
        if (!$fileModel) {
            return;
        }

        $baseParts = array_slice($parts, 0, $aiOcrIndex);
        $subDir = $parts[$aiOcrIndex + 1];
        $destinationBase = implode(DIRECTORY_SEPARATOR, array_merge($baseParts, [$subDir]));
        if (!File::exists($destinationBase)) {
            File::makeDirectory($destinationBase, 0777, true);
        }
        $newPath = $destinationBase . DIRECTORY_SEPARATOR . $tempFilename;
        rename($file->getPathname(), $newPath);

        $fileModel->local_dirname = $subDir;
        $fileModel->save();

        $fileModel->saveCustomValue($custom_record->id, null, $custom_record->custom_table);
        $fileModel->saveDocumentModel($custom_record, $tempFilename);
    }

    private function deleteIfEmptyTempDirectory(string $filesPath): void
    {
        if (!File::exists($filesPath)) {
            return;
        }

        $files = File::files($filesPath);
        $subdirs = File::directories($filesPath);

        if (empty($files) && empty($subdirs)) {
            File::deleteDirectory($filesPath);
        }
    }
}
