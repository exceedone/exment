<?php

namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Model\File as ExmentFile;

/**
 */
class Image extends OtherBase
{
    /**
     * Get setting modal form
     *
     * @return WidgetForm
     */
    public function getSettingModalForm(BlockBase $block_item, array $parameters): WidgetForm
    {
        $form = new WidgetForm($parameters);

        $imageurl = $this->getImageUrl();
        if (!isset($imageurl)) {
            $form->image('image', exmtrans('custom_form.image'))
                ->required()
                ->help(exmtrans("common.message.cannot_preview", ['name' => exmtrans("custom_form.image")]))
                ->attribute(['accept' => "image/*"]);
        } else {
            $form->description(exmtrans('custom_form.message.image_need_delete'));

            $imagetag = '<img src="'.$imageurl.'" class="mw-100 image_html" style="max-height:200px;" />';
            $form->description($imagetag)->escape(false);
        }
        $form->switchbool('image_aslink', exmtrans('custom_form.image_aslink'))->default(false)
            ->help(exmtrans('custom_form.help.image_aslink'));

        return $form;
    }



    /**
     * Get items for display
     *
     * @return array
     */
    public function getItemsForDisplay(): array
    {
        $result = parent::getItemsForDisplay();

        // set image url for option
        $options = json_decode_ex($result['options'], true);
        $options['image_url'] = $this->getImageUrl();
        $result['options'] = collect($options)->toJson();
        return $result;
    }

    /**
     * getImageUrl
     *
     * @return string|null
     */
    protected function getImageUrl(): ?string
    {
        $file = ExmentFile::getFileFromFormColumn(array_get($this->custom_form_column, 'id'));
        if (!$file) {
            return null;
        }
        return ExmentFile::getUrl($file);
    }

    /**
     * prepare saving option.
     *
     * @param array $options
     * @return array
     */
    public function prepareSavingOptions(array $options): array
    {
        return array_filter($options, function ($option, $key) {
            return in_array($key, [
                'image_aslink',
            ]);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Get validation rules for jquery
     *
     * @return array
     */
    public function getValidationRules(): array
    {
        return ['image' => 'required_image'];
    }

    public function getFontAwesomeClass(): ?string
    {
        return 'fa-picture-o';
    }

    /**
     * Get option labels difinitions. for getting label, and js
     *
     * @return array
     */
    public function getOptionLabels(): array
    {
        $result = parent::getOptionLabels();

        if (!is_nullorempty($this->getImageUrl())) {
            $result['image'] = exmtrans('custom_form.setting_available');
        }

        return $result;
    }
}
