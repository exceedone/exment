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
            $isMultiPage = is_array($result['results'])
                && array_is_list($result['results'])
                && is_array(reset($result['results']));
            if ($isMultiPage) {
                return response()->json([
                    'message' => exmtrans("custom_table.help.ai_ocr_import_multi_alert"),
                ], 500);
            }

            if (!$this->checkOcrResult($result['results'])) {
                return response()->json([
                    'message' => 'No results found. Please check the file and rerun the OCR process',
                ], 500);
            }

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
                $results = $result['results'];

                if (!$this->checkOcrResult($results)) {
                    throw new \Exception('No results found. Please check the file and rerun the OCR process');
                }

                $isMultiPage = is_array($results) && array_is_list($results) && is_array(reset($results));

                if ($isMultiPage) {
                    foreach ($results as $pageIndex => $pageResult) {
                        $new_record = new $modelClass();
                        $this->createCustomRecord($new_record, $pageResult, $filesPath, $file);
                    }
                } else {
                    $new_record = new $modelClass();
                    $this->createCustomRecord($new_record, $results, $filesPath, $file);
                }

                $succeedOcrFilesCount++;
            } catch (\Exception $ex) {
                \Log::error("OCR failed for file {$file->getFilename()}: " . $ex->getMessage());
                $failedOcrFilesCount++;
                $originalFilename = ExmentFile::where('local_filename', $file->getFilename())
                        ->first()?->filename ?? $file->getFilename();
                $failedOcrFileNameList[] = $originalFilename;
            }
        }

        $this->deleteTempDirectory($filesPath);

        return response()->json([
            'message' => 'Multi OCR completed',
            'succeedOcrFilesCount' => $succeedOcrFilesCount,
            'failedOcrFilesCount' => $failedOcrFilesCount,
            'failedOcrFileNameList' => implode("\n", $failedOcrFileNameList),
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

                $this->copyAiOcrFileAndLink($new_record, $tempPath, $file);

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
    private function copyAiOcrFileAndLink($custom_record, $tempPath, $file)
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
        if (!copy($file->getPathname(), $newPath)) {
            return;
        }

        $fileModel->local_dirname = $subDir;
        $fileModel->save();

        $fileModel->saveCustomValue($custom_record->id, null, $custom_record->custom_table);
        $fileModel->saveDocumentModel($custom_record, $tempFilename);
    }

    private function deleteTempDirectory(string $filesPath): void
    {
        $files = File::files($filesPath);
        $subdirs = File::directories($filesPath);

        if (empty($files) && empty($subdirs)) {
            File::deleteDirectory($filesPath);
        }
    }

    private function checkOcrResult($results)
    {
        if (!isset($results) || !is_array($results)) {
            return false;
        }

        foreach ($results as $key => $data) {
            if (isset($data['value'])) {
                if ($data['value'] !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
