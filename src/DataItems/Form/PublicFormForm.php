<?php

namespace Exceedone\Exment\DataItems\Form;

use Symfony\Component\HttpFoundation\Response;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ValidateCalledType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Services\PartialCrudService;
use Exceedone\Exment\Services\Calc\CalcService;

/**
 * Public form's form
 */
class PublicFormForm extends DefaultForm
{
    /**
     * If true, all disabled tools button
     *
     * @var boolean
     */
    protected $disableToolsButton = true;

    /**
     * If true, disableSavedRedirectCheck
     *
     * @var boolean
     */
    protected $disableSavedRedirectCheck = true;
    
    /**
     * If true, disableDefaultSavedRedirect.
     *
     * @var boolean
     */
    protected $disableDefaultSavedRedirect = true;
}
