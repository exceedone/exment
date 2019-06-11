<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Exment;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Role;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SystemVersion;
use Exceedone\Exment\Form\Widgets\InfoBox;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Widgets\Box;

class SystemController extends AdminControllerBase
{
    use RoleForm, InitializeFormTrait;
    
    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("system.header"), exmtrans("system.header"), exmtrans("system.system_description"), 'fa-cogs');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $form = $this->getInitializeForm('system', false, true);
        $form->action(admin_url('system'));

        // Role Setting
        $this->addRoleForm($form, RoleType::SYSTEM);

        $content->row(new Box(trans('admin.edit'), $form));

        if (!config('exment.disabled_outside_api', false)) {
            // Version infomation
            $infoBox = $this->getVersionBox();
            $content->row(new Box(exmtrans("system.version_header"), $infoBox->render()));
        }

        return $content;
    }

    /**
     * get exment version infoBox.
     *
     * @return Content
     */
    protected function getVersionBox()
    {
        list($latest, $current) = getExmentVersion();
        $version = checkLatestVersion();
        $showLink = false;

        if ($version == SystemVersion::ERROR) {
            $message = exmtrans("system.version_error");
            $icon = 'warning';
            $bgColor = 'red';
            $current = '---';
        } elseif ($version == SystemVersion::DEV) {
            $message = exmtrans("system.version_develope");
            $icon = 'legal';
            $bgColor = 'olive';
        } elseif ($version == SystemVersion::LATEST) {
            $message = exmtrans("system.version_latest");
            $icon = 'check-square';
            $bgColor = 'blue';
        } else {
            $message = exmtrans("system.version_old") . '(' . $latest . ')';
            $showLink = true;
            $icon = 'arrow-circle-right';
            $bgColor = 'aqua';
        }
        
        // Version infomation
        $infoBox = new InfoBox(
            exmtrans("system.current_version") . $current,
            $icon,
            $bgColor,
            getManualUrl('update'),
            $message
        );
        $class = $infoBox->getAttributes()['class'];
        $infoBox
            ->class(isset($class)? $class . ' box-version': 'box-version')
            ->showLink($showLink)
            ->target('_blank');
        if ($showLink) {
            $infoBox->linkText(exmtrans("system.update_guide"));
        }

        return $infoBox;
    }

    /**
     * Send data
     * @param Request $request
     */
    public function post(Request $request)
    {
        DB::beginTransaction();
        try {
            $result = $this->postInitializeForm($request);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                return $result;
            }

            // Set Role
            Role::roleLoop(RoleType::SYSTEM, function ($role, $related_type) use ($request) {
                $values = $request->input($role->getRoleName($related_type));
                // array_filter
                $values = array_filter($values, function ($k) {
                    return isset($k);
                });
                if (!isset($values)) {
                    $values = [];
                }

                // get DB system_authoritable values
                $dbValues = DB::table(SystemTableName::SYSTEM_AUTHORITABLE)
                    ->where('related_type', $related_type)
                    ->where('morph_type', RoleType::SYSTEM()->lowerKey())
                    ->where('role_id', $role->id)
                    ->get(['related_id']);
                foreach ($values as $value) {
                    if (!isset($value)) {
                        continue;
                    }
                    /// not exists db value, insert
                    if (!$dbValues->first(function ($dbValue, $k) use ($value) {
                        return $dbValue->related_id == $value;
                    })) {
                        DB::table(SystemTableName::SYSTEM_AUTHORITABLE)->insert(
                            [
                            'related_id' => $value,
                            'related_type' => $related_type,
                            'morph_id' => null,
                            'morph_type' => RoleType::SYSTEM()->lowerKey(),
                            'role_id' => $role->id,
                        ]
                    );
                    }
                }

                ///// Delete if not exists value
                foreach ($dbValues as $dbValue) {
                    if (!collect($values)->first(function ($value, $k) use ($dbValue) {
                        return $dbValue->related_id == $value;
                    })) {
                        DB::table(SystemTableName::SYSTEM_AUTHORITABLE)
                        ->where('related_id', $dbValue->related_id)
                        ->where('related_type', $related_type)
                        ->where('morph_type', RoleType::SYSTEM()->lowerKey())
                        ->where('role_id', $role->id)
                        ->delete();
                    }
                }
            });

            DB::commit();

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_url('system'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }
}
