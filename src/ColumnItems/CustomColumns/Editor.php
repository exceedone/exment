<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use ExmentAdminCore\Admin\Form;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Validator;
use Exceedone\Exment\Enums\FileType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class Editor extends CustomItem
{
    // @phpstan-ignore-next-line
    protected $tmpfiles;

    /** @var array<string, array{file: ExmentFile, target: mixed, temp_name: string}> */
    protected array $pastedImages = [];

    // @phpstan-ignore-next-line
    public function saving()
    {
        if (is_nullorempty($this->value)) {
            return;
        }

        $value = $this->savedFileInEditor($this->value);

        return strval($value);
    }

    // @phpstan-ignore-next-line
    public function saved()
    {
        if (is_nullorempty($this->tmpfiles)) {
            return;
        }

        $pending = $this->tmpfiles;
        $this->tmpfiles = [];
        foreach ($pending as $tmpfile) {
            $target = $tmpfile['target'] ?? $this->custom_value;
            if (! $this->sameImageTarget($target, $this->custom_value)) {
                $this->tmpfiles[] = $tmpfile;
                continue;
            }
            $tmpfile['file']->saveDocumentModel($target, $tmpfile['filename']);
            if (isset($tmpfile['temp_name'])) {
                if (!Storage::disk(Define::DISKNAME_TEMP_UPLOAD)->delete($tmpfile['temp_name'])) {
                    Log::warning('Could not remove a saved temporary editor image.', ['file' => $tmpfile['temp_name']]);
                }
            }
        }
    }

    private function sameImageTarget($target, $row): bool
    {
        return $target === $row || (isset($target->id, $row->id)
            && strval($target->id) === strval($row->id)
            && $target->custom_table->table_name === $row->custom_table->table_name);
    }

    public function getPastedImageUrls($row): array
    {
        $urls = [];
        foreach ($this->pastedImages as $source => $image) {
            if ($this->sameImageTarget($image['target'], $row)) {
                $urls[$source] = [
                    'url' => admin_url('files', [$image['file']->uuid]),
                    'uuid' => strval($image['file']->uuid),
                ];
            }
        }
        return $urls;
    }

    public function getPastedImageFiles($row): array
    {
        $files = [];
        foreach ($this->pastedImages as $image) {
            if ($this->sameImageTarget($image['target'], $row)) {
                $files[$image['file']->uuid] = $image;
            }
        }
        return $files;
    }

    // @phpstan-ignore-next-line
    protected function _text($v)
    {
        // replace img html
        $v = $this::replaceImgUrl($v);

        return $v;
    }

    // @phpstan-ignore-next-line
    protected function _html($v)
    {
        $text = $this->_text($v);
        if (is_null($text)) {
            return null;
        }

        if (boolval(array_get($this->options, 'grid_column'))) {
            // if grid, remove tag and omit string
            $text = get_omitted_string(strip_tags($text));
        }

        return  '<div class="show-tinymce">'.replaceBreak(html_clean($text), false).'</div>';
    }

    // @phpstan-ignore-next-line
    protected function getAdminFieldClass()
    {
        return Field\Tinymce::class;
    }

    // @phpstan-ignore-next-line
    protected function setAdminOptions(&$field)
    {
        $options = $this->custom_column->options;
        $field->rows(array_get($options, 'rows', 6));

        $item = $this;
        $field->callbackValue(function ($value) use ($item) {
            return $item::replaceImgUrl($value);
        });

        if ($this->isPublicForm()) {
            $field->setPostImageUri($this->options['public_form']->getUrl());
        }
    }

    // @phpstan-ignore-next-line
    protected function setValidates(&$validates)
    {
        // value string
        $validates[] = new Validator\StringNumericRule();

        // value size
        $validates[] = new Validator\MaxLengthExRule(config('exment.char_length_limit', 63999));
    }


    /**
     * Replace "src" value
     *
     * @param ?string $v
     * @return string
     */
    // @phpstan-ignore-next-line
    public static function replaceImgUrl($v, $options = [])
    {
        // replace img html
        preg_match_all('/\<img(.*?)data-exment-file-uuid="(?<file_uuid>.*?)"(.*?)\>/u', $v, $matches);
        if (is_nullorempty($matches)) {
            return $v;
        }

        for ($index = 0; $index < count($matches[0]); $index++) {
            $replaceValue = $matches[0][$index];
            $file_uuid = array_get($matches, 'file_uuid')[$index];
            if (is_nullorempty($file_uuid)) {
                continue;
            }
            $url = ExmentFile::getUrl($file_uuid, $options);
 
            //replace src
            $replaceValue = preg_replace('/src="(.*?)"/u', 'src="' . $url . '"', $replaceValue);
            //$replaceValue = preg_replace('/data-exment-file-uuid="(.*?)"/u', "", $replaceValue);

            $v = str_replace($matches[0][$index], $replaceValue, $v);
        }

        return $v;
    }


    // @phpstan-ignore-next-line
    protected function savedFileInEditor($value)
    {
        if (is_nullorempty($value)) {
            return $value;
        }
        // find <img alt="" src=""> tag
        preg_match_all('/\<img([^\>]*?)src="(?<src>.*?)"(.*?)\>/u', $value, $matches);
        preg_match_all('/!\[[^\]\r\n]*\]\((?<src>[^\s)]+)\)/u', $value, $markdown);
        $htmlCount = count($matches[0]);
        $matches[0] = array_merge($matches[0], $markdown[0]);
        $matches['src'] = array_merge($matches['src'], $markdown['src']);
        if (empty($matches[0])) {
            return $value;
        }

        if (!is_array($this->tmpfiles)) { $this->tmpfiles = []; }

        // replace src="" and save file
        for ($index = 0; $index < count($matches[0]); $index++) {
            $match = $matches[0][$index];
            // group
            $src = array_get($matches, 'src')[$index];
            $isMarkdown = $index >= $htmlCount;
            $filename = pathinfo($src, PATHINFO_FILENAME);

            $exists = Storage::disk(Define::DISKNAME_TEMP_UPLOAD)->exists($filename);
            // check url
            $tmpUrl = strpos($src, admin_urls('tmpfiles')); // check url
            $publicTemp = false;
            // consider public form
            if ($tmpUrl === false) {
                $patturn_uuid = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}";
                $patturn = public_form_url() . "/(?<public_form_uuid>{$patturn_uuid})/tmpfiles/(?<file_uuid>{$patturn_uuid})";
                preg_match("/" . str_replace("/", "\/", $patturn) . "/", $src, $preg_match);
                if ($preg_match) {
                    $tmpUrl = true;
                    $publicTemp = true;
                }
            }

            $fileUrl = strpos($src, admin_urls('files')); // check url
            if ($isMarkdown && $tmpUrl === false) {
                continue;
            }
            if (isset($this->pastedImages[$src])
                && $this->sameImageTarget($this->pastedImages[$src]['target'], $this->custom_value)) {
                $uuid = $this->pastedImages[$src]['file']->uuid;
                if ($isMarkdown) {
                    $value = str_replace($match, str_replace($src, admin_url('files', [$uuid]), $match), $value);
                    continue;
                }
            } else {
                $uuid = null;
            }

            if ($tmpUrl !== false && $uuid === null) {
                $base = rtrim(admin_urls('tmpfiles'), '/') . '/';
                $owned = preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $filename)
                    && ($src === $base . $filename || (!$isMarkdown && $publicTemp))
                    && session()->has($filename);
                if (!$exists || !$owned) {
                    throw ValidationException::withMessages([
                        $this->custom_column->column_name => exmtrans('common.message.editor_image_expired'),
                    ]);
                }
            }

            // if not exment path url, continue
            if ($tmpUrl === false && $fileUrl === false) {
                continue;
            }

            // save tmp files
            if ($uuid === null && $exists && $tmpUrl !== false) {
                // get temporary file data
                $file = Storage::disk(Define::DISKNAME_TEMP_UPLOAD)->get($filename);
                if (@getimagesizefromstring($file) === false) {
                    throw ValidationException::withMessages([
                        $this->custom_column->column_name => trans('validation.image'),
                    ]);
                }
                // get original filename from session
                $original_name = session()->get($filename);
                // save file info
                $exmentfile = ExmentFile::saveFileInfo(FileType::CUSTOM_VALUE_DOCUMENT, path_join($this->custom_table->table_name, make_uuid()));

                // save document model
                $this->tmpfiles[] = [
                    'file' => $exmentfile,
                    'filename' => $original_name??$filename,
                    'target' => $this->custom_value,
                    'temp_name' => $filename,
                ];
                $this->pastedImages[$src] = [
                    'file' => $exmentfile,
                    'target' => $this->custom_value,
                    'temp_name' => $filename,
                ];
                $disk = Storage::disk(config('admin.upload.disk'));
                if (!$disk->put($exmentfile->path, $file) || !$disk->exists($exmentfile->path)
                    || $disk->size($exmentfile->path) !== strlen($file)) {
                    throw new \RuntimeException('Failed to store an editor image.');
                }

                // set request session to save this custom_value's id and type into files table.
                $file_uuids = System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID) ?? [];
                $file_uuid = [
                    'uuid' => $exmentfile->uuid,
                    'column_name' => $this->custom_column->column_name,
                    'custom_table' => $this->custom_table,
                    'path' => $exmentfile->path,
                    'replace' => false
                ];
                $file_uuids[] = $file_uuid;
                System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID, $file_uuids);

                $uuid = $exmentfile->uuid;
                $this->custom_value->file_uuids($file_uuid);
            }
            if ($isMarkdown) {
                $value = str_replace($match, str_replace($src, admin_url('files', [$uuid]), $match), $value);
                continue;
            }

            // replace src to uuid
            $replaceValue = $match;
            preg_match('/src="(.*?)"/u', $replaceValue, $replaceMatch);
            if ($replaceMatch) {
                $replaceValue = preg_replace('/src="(.*?)"/u', 'src=""', $replaceValue);
            }

            preg_match('/data-exment-file-uuid="(?<uuid>.*?)"/u', $replaceValue, $replaceMatch);
            if ($replaceMatch) {
                $uuid = isset($uuid) ? $uuid : array_get($replaceMatch, 'uuid');
                $replaceValue = preg_replace('/data-exment-file-uuid="(.*?)"/u', 'data-exment-file-uuid="' . $uuid . '"', $replaceValue);
            }
            // not exists "data-exment-file-uuid", add
            else {
                $replaceValue = str_replace('<img', '<img data-exment-file-uuid="' . $uuid . '"', $replaceValue);
            }
            $value = str_replace($match, $replaceValue, $value);
        }

        return $value;
    }

    // @phpstan-ignore-next-line
    protected function getExtention($type)
    {
        if (is_nullorempty($type)) {
            return null;
        }

        $types = [
            'image/gif' => 'gif',
            'image/jpeg'=>'jpg',
            'image/png'=>'png',
            'image/bmp'=>'bmp',
        ];
        foreach ($types as $t => $ext) {
            if (isMatchString($t, $type)) {
                return ".{$ext}";
            }
        }

        return null;
    }


    /**
     * Set Custom Column Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnOptionForm(&$form)
    {
        $form->number('rows', exmtrans("custom_column.options.rows"))
            ->default(6)
            ->min(1)
            ->max(30)
            ->help(exmtrans("custom_column.help.rows"));
    }

    /**
     * Set Custom Column Option default value Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnDefaultValueForm(&$form, bool $asCustomForm = false)
    {
        $form->tinymce('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"))
            ->attribute(['data-default_timymce' => 1])
            ->disableImage()
            ->rows(3);
    }
}
