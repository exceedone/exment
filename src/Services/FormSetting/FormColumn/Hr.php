<?php

namespace Exceedone\Exment\Services\FormSetting\FormColumn;

use Exceedone\Exment\Services\FormSetting\FormBlock\BlockBase;
use Encore\Admin\Widgets\Form as WidgetForm;

/**
 */
class Hr extends OtherBase
{
    /**
     * Get setting modal form
     *
     * @param BlockBase $block_item
     * @param array $parameters
     * @return WidgetForm|null
     */
    public function getSettingModalForm(BlockBase $block_item, array $parameters): ?WidgetForm
    {
        return null;
    }

    /**
     * prepare saving option.
     *
     * @param array $options
     * @return array
     */
    public function prepareSavingOptions(array $options): array
    {
        return [];
    }

    /**
     * Whether using setting
     *
     * @return boolean
     */
    public function useSetting(): bool
    {
        return false;
    }

    public function getFontAwesomeClass(): ?string
    {
        return 'fa-window-minimize';
    }
}
