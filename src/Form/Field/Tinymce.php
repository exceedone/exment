<?php

namespace Exceedone\Exment\Form\Field;

use ExmentAdminCore\Admin\Form\Field\Textarea;
use Exceedone\Exment\Model\Define;

class Tinymce extends Textarea
{
    use HasCustomOptions;

    protected $view = 'admin::form.textarea';

    protected static $js = [
        '/vendor/exment/tinymce/tinymce.min.js',
    ];

    // @phpstan-ignore-next-line
    protected $config = [];

    // @phpstan-ignore-next-line
    protected $disableImage = false;

    /**
     * POST url. If null, return adminurl, else, return this value
     *
     * @var string
     */
    protected $postImageUri;

    /**
     * Set config for tinymce.
     *
     * @param string $key
     * @param mixed  $val
     *
     * @return $this
     */
    public function config($key, $val)
    {
        $this->config[$key] = $val;

        return $this;
    }

    /**
     * Set disableImage
     *
     * @return  self
     */
    public function disableImage()
    {
        $this->disableImage = true;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function readonly()
    {
        $this->config('readonly', true);

        return parent::readonly();
    }

    /**
     * The names of the language files of TinyMCE 8 (tinymce-i18n) for the locales of Exment
     * whose name is not the same.
     */
    public const LANGUAGE_ALIASES = [
        'ko' => 'ko-KR',
        'zh' => 'zh-CN',
    ];

    /**
     * The language of TinyMCE for a locale: the one of public/vendor/exment/tinymce/langs when it has
     * a file for it (ja, de, es, ko-KR, vi, zh-CN from tinymce-i18n), else English (built in).
     */
    public static function tinymceLanguage(?string $locale): string
    {
        if (is_nullorempty($locale)) {
            return 'en';
        }
        $locale = (string)$locale;
        $dirs = [
            public_path('vendor/exment/tinymce/langs'),
            exment_package_path('public/vendor/exment/tinymce/langs'),
        ];
        $base = explode('_', str_replace('-', '_', $locale))[0];
        $names = array_unique(array_filter([
            $locale,
            str_replace('_', '-', $locale),
            str_replace('-', '_', $locale),
            $base,
            static::LANGUAGE_ALIASES[$base] ?? null,
        ]));
        foreach ($names as $name) {
            foreach ($dirs as $dir) {
                if (is_file($dir . '/' . $name . '.js')) {
                    return $name;
                }
            }
        }
        return 'en';
    }

    // @phpstan-ignore-next-line
    protected function getValidElements()
    {
        $tags = Define::HTML_ALLOWED_EDITOR_DEFAULT;
        if (!is_null($c = config('exment.html_allowed_editor'))) {
            $tags = $c;
        }

        return $tags;
    }

    /**
     * Get pOST url. If null, return adminurl, else, return this value
     *
     * @return  string
     */
    public function getPostImageUri()
    {
        if ($this->postImageUri) {
            return $this->postImageUri;
        }
        return admin_url();
    }

    /**
     * Set post url. If null, return adminurl, else, return this value
     *
     * @param string $postImageUri
     * @return $this
     */
    public function setPostImageUri(string $postImageUri)
    {
        $this->postImageUri = $postImageUri;

        return $this;
    }

    /**
     * Whether the editor uploads images (pasted or chosen).
     */
    protected function enableImage(): bool
    {
        return !$this->disableImage && !boolval(config('exment.diable_upload_images_editor', false));
    }

    /**
     * The settings of TinyMCE 8 (ISS00404: 5.10.9 was out of support).
     *
     * @return array<string, mixed>
     */
    public function tinymceConfigs(): array
    {
        $enableImage = $this->enableImage();

        // if readonly, disable tool bar
        if (array_boolval($this->config, 'readonly')) {
            $toolbar = false;
        } else {
            $toolbar = [
                'undo redo cut copy paste | blocks fontfamily fontsize',
                'bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify outdent indent blockquote bullist numlist | hr link ' . ($enableImage ? 'image ' : '') . 'code',
            ];
        }

        $configs = array_merge([
            'selector' => "{$this->getElementClassSelector()}",
            'toolbar'=> $toolbar,
            // (paste, the colors and hr are in the core since TinyMCE 6)
            'plugins'=> 'link lists code image',
            'menubar' => false,
            // the language file of TinyMCE when it has one (langs/<locale>.js), else English (ISS02835)
            'language' => static::tinymceLanguage(\App::getLocale()),
            'valid_elements' => $this->getValidElements(),
            'convert_urls' => false,
            'paste_data_images' => $enableImage,
            'branding' => false,
            'promotion' => false,
            // TinyMCE 8 on our own server, under the GPL (without it the editor is made read only)
            'license_key' => 'gpl',
        ], $this->config);
        // (a boolean since TinyMCE 6)
        $configs['readonly'] = array_boolval($configs, 'readonly');

        if ($enableImage) {
            $configs = array_merge([
                'automatic_uploads' => true,
                'file_picker_types' => 'image',
                'content_style' => 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }',
            ], $configs);
        }
        return $configs;
    }

    public function render()
    {
        // Remove required attributes(for timymce bug).
        array_forget($this->attributes, 'required');
        $this->rules = collect($this->rules)->filter(function ($rule) {
            return !is_string($rule) || $rule !== 'required';
        })->toArray();
        $this->rules = array_values($this->rules);

        $enableImage = $this->enableImage();
        $configs = json_encode($this->tinymceConfigs());

        $max_file_size = \Exment::getUploadMaxFileSize();
        $message = json_encode(strval(exmtrans('custom_value.message.editor_image_oversize')));
        $url = json_encode(url_join($this->getPostImageUri(), 'tmpimages') . '?_token='. csrf_token());

        $this->script = <<<EOT
        var config = $configs;
        if(pBool('$enableImage')){
            // TinyMCE 6+: the handler returns a promise of the URL of the image
            config['images_upload_handler'] = function(blobInfo, progress){
                return new Promise(function(resolve, reject){
                    const image_size = blobInfo.blob().size;
                    const max_size   = $max_file_size;
                    if( image_size  > max_size){
                        reject({ message: $message, remove: true });
                        return;
                    };
                    var xhr = new XMLHttpRequest();
                    xhr.withCredentials = false;
                    xhr.open('POST', $url);
                    xhr.upload.onprogress = function (e) {
                        if (e.lengthComputable) {
                            progress(e.loaded / e.total * 100);
                        }
                    };
                    xhr.onload = function() {
                        var json = null;
                        try {
                            json = JSON.parse(xhr.responseText);
                        } catch (e) {
                        }

                        if (xhr.status >= 400 && xhr.status < 500) {
                            reject({ message: 'Error: ' + (json ? json[0] : xhr.status), remove: true });
                            return;
                        }
                        else if (xhr.status < 200 || xhr.status >= 300) {
                            reject({ message: 'HTTP Error: ' + xhr.status, remove: true });
                            return;
                        }

                        if (!json || typeof json.location != 'string') {
                            reject({ message: 'Invalid JSON: ' + xhr.responseText, remove: true });
                            return;
                        }

                        resolve(json.location);
                    };

                    xhr.onerror = function () {
                        reject({ message: 'Image upload failed due to a XHR Transport error. Code: ' + xhr.status, remove: true });
                    };

                    var formData = new FormData();
                    formData.append('file', blobInfo.blob(), blobInfo.filename());

                    xhr.send(formData);
                });
            };

            config['file_picker_callback'] = function (cb, value, meta) {
                var input = document.createElement('input');
                input.setAttribute('type', 'file');
                input.setAttribute('accept', 'image/*');

                input.onchange = function () {
                    var file = this.files[0];

                    var reader = new FileReader();
                    reader.onload = function () {
                        var id = 'blobid' + (new Date()).getTime();
                        var blobCache =  tinymce.activeEditor.editorUpload.blobCache;
                        var base64 = reader.result.split(',')[1];
                        var blobInfo = blobCache.create(id, file, base64);
                        blobCache.add(blobInfo);

                        /* call the callback and populate the Title field with the file name */
                        cb(blobInfo.blobUri(), { title: file.name });
                    };
                    reader.readAsDataURL(file);
                };

                input.click();
            };
        }

        tinymce.init(config);
EOT;
        return parent::render();
    }
}
