<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Textarea;
use Exceedone\Exment\Model\Define;

class Tinymce extends Textarea
{
    protected $view = 'admin::form.textarea';

    protected static $js = [
        '/vendor/exment/tinymce/tinymce.min.js',
    ];

    protected $config = [];

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
        $this->config('readonly', '1');

        return parent::readonly();
    }

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

    public function render()
    {
        // Remove required attributes(for timymce bug).
        array_forget($this->attributes, 'required');
        $this->rules = collect($this->rules)->filter(function ($rule) {
            return !is_string($rule) || $rule !== 'required';
        })->toArray();
        $this->rules = array_values($this->rules);

        $locale = \App::getLocale();

        $enableImage = !$this->disableImage && !boolval(config('exment.diable_upload_images_editor', false));

        // if readonly, disable tool bar
        if (array_boolval($this->config, 'readonly')) {
            $toolbar = false;
        } elseif ($enableImage) {
            $toolbar = ['undo redo cut copy paste | formatselect fontselect fontsizeselect ', ' bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify outdent indent blockquote bullist numlist | hr link image code'];
        } else {
            $toolbar = ['undo redo cut copy paste | formatselect fontselect fontsizeselect ', ' bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify outdent indent blockquote bullist numlist | hr link code'];
        }

        $configs = array_merge([
            'selector' => "{$this->getElementClassSelector()}",
            'toolbar'=> $toolbar,
            'plugins'=> 'textcolor hr link lists code image paste',
            'menubar' => false,
            'language' => $locale,
            'valid_elements' => $this->getValidElements(),
            'convert_urls' => false,
            'paste_enable_default_filters' => false,
            'paste_data_images' => $enableImage,
            'branding' => false,
        ], $this->config);

        if ($enableImage) {
            $configs = array_merge([
                'automatic_uploads' => true,
                'file_picker_types' => 'image',
                'content_style' => 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }',
            ], $configs);
        }

        $configs = json_encode($configs);

        $max_file_size = \Exment::getUploadMaxFileSize();
        $message = exmtrans('custom_value.message.editor_image_oversize');
        $url =  url_join($this->getPostImageUri(), 'tmpimages') . '?_token='. csrf_token();

        $this->script = <<<EOT
        var config = $configs;
        if(pBool('$enableImage')){
            config['images_upload_handler'] = function(blobInfo, success, failure){
                const image_size = blobInfo.blob().size;
                const max_size   = $max_file_size;
                if( image_size  > max_size){
                    failure('$message');
                    return;
                };
                var xhr, formData;

                xhr = new XMLHttpRequest();
                xhr.withCredentials = false;
                xhr.open('POST', '$url');
                xhr.onload = function() {
                    var json = JSON.parse(xhr.responseText);

                    if (xhr.status >= 400 && xhr.status < 500) {
                        failure('Error: ' + json[0]);
                        return;
                    }
                    else if (xhr.status < 200 || xhr.status >= 300) {
                        failure('HTTP Error: ' + xhr.status);
                        return;
                    }

                    if (!json || typeof json.location != 'string') {
                        failure('Invalid JSON: ' + xhr.responseText);
                        return;
                    }

                    success(json.location);
                };

                xhr.onerror = function () {
                    failure('Image upload failed due to a XHR Transport error. Code: ' + xhr.status);
                };

                formData = new FormData();
                formData.append('file', blobInfo.blob(), blobInfo.filename());

                xhr.send(formData);
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
