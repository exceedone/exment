<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Exceedone\Exment\Exment;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Authority;
use Exceedone\Exment\Enums\AuthorityType;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\InfoBox;

class SystemController extends AdminControllerBase
{
    use InitializeForm, AuthorityForm;
    
    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("system.header"), exmtrans("system.header"), exmtrans("system.system_description"));
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $form = $this->getInitializeForm();
        $form->action('system');

        // Authority Setting
        $this->addAuthorityForm($form, AuthorityType::SYSTEM);

        // Version infomation
        $infoBox = new InfoBox(
            exmtrans("system.current_version") . '---',
            'refresh',
            'gray',
            config('exment.manual_url'),
            exmtrans("system.version_progress")
        );
        $class = $infoBox->getAttributes()['class'];
        $infoBox->class(isset($class)? $class . ' box-version': 'box-version');

        $content->row(new Box(trans('admin.edit'), $form));
        $content->row(new Box(exmtrans("system.version_header"), $infoBox->render()));

        Admin::script($this->getVersionScript());
        return $content;
    }

    /**
     * get system version script
     *
     * @return script
     */
    protected function getVersionScript()
    {
        $install = exmtrans("system.install_guide");
        $script = <<<EOT
        $(function () {
            $('div.box-version .small-box-footer').hide();
            $('div.box-version div.icon > i').addClass('fa-spin');
            $.ajax({
                url: admin_base_path('system/version'),
                type: "GET",
                success: function (data) {
                    $('div.box-version div.icon > i').removeClass('fa-spin');
                    $('div.box-version .small-box-footer').hide();
                    $('div.box-version div.inner > p').html(data.current);
                    $('div.box-version div.inner > h3').html(data.message);
                    if (data.status == 1) {
                        $('div.box-version div.icon > i').removeClass('fa-refresh').addClass('fa-check-square');
                        $('div.box-version').removeClass('bg-gray').addClass('bg-blue');
                    } else if (data.status == 2) {
                        $('div.box-version div.icon > i').removeClass('fa-refresh').addClass('fa-info-circle');
                        $('div.box-version').removeClass('bg-gray').addClass('bg-teal');
                        $('div.box-version a.small-box-footer').html('$install&nbsp;<i class="fa fa-arrow-circle-right"></i>');
                        $('div.box-version a.small-box-footer').show();
                    } else if (data.status == 3) {
                        $('div.box-version').removeClass('bg-gray').addClass('bg-olive');
                        $('div.box-version div.icon > i').removeClass('fa-refresh').addClass('fa-legal');
                    } else if (data.status == -1) {
                        $('div.box-version div.icon > i').removeClass('fa-refresh').addClass('fa-warning');
                    }
                },
            });
        });
EOT;
        return $script;
    }
    /**
     * get exment version command.
     *
     * @return Content
     */
    public function version(Request $request)
    {
        list($latest, $current) = getExmentVersion();
        if (empty($latest) || empty($current)) {
            return response()->json([
                'status'  => -1,
                'message'  => exmtrans("system.version_error"),
                'current'  => exmtrans("system.current_version") . '---',
            ]);
        }   
        if (strpos($current, 'dev-') === 0) {
            return response()->json([
                'status'  => 3,
                'message'  => exmtrans("system.version_develope"),
                'current'  => exmtrans("system.current_version") . $current,
            ]);
        } elseif ($latest === $current) {
            return response()->json([
                'status'  => 1,
                'message'  => exmtrans("system.version_latest"),
                'current'  => exmtrans("system.current_version") . $current,
            ]);
        } else {
            return response()->json([
                'status'  => 2,
                'message'  => exmtrans("system.version_old") . '(' . $latest . ')',
                'current'  => exmtrans("system.current_version") . $current,
            ]);
        }
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

            // Set Authority
            Authority::authorityLoop(AuthorityType::SYSTEM(), function ($authority, $related_type) use ($request) {
                $values = $request->input($authority->getAuthorityName($related_type));
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
                    ->where('morph_type', AuthorityType::SYSTEM())
                    ->where('authority_id', $authority->id)
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
                            'morph_type' => AuthorityType::SYSTEM(),
                            'authority_id' => $authority->id,
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
                        ->where('morph_type', AuthorityType::SYSTEM())
                        ->where('authority_id', $authority->id)
                        ->delete();
                    }
                }
            });

            DB::commit();

            admin_toastr(trans('admin.save_succeeded'));

            return redirect(admin_base_path('system'));
        } catch (Exception $exception) {
            //TODO:error handling
            DB::rollback();
            throw $exception;
        }
    }
}
