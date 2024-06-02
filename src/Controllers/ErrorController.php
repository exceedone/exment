<?php

namespace Exceedone\Exment\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Widgets\Box;

class ErrorController extends Controller
{
    // public function __construct(Request $request)
    // {
    //     parent::__construct($request);
    // }

    /**
     * Index interface.
     */
    public function error(Request $request, $exception)
    {
        /** @phpstan-ignore-next-line response expects array|Illuminate\Contracts\View\View|string|null, Encore\Admin\Layout\Content given */
        return response(Admin::content(function (Content $content) use ($exception) {
            $content->header(exmtrans('error.header'));
            $content->description(exmtrans('error.description'));

            $form = new WidgetForm();
            $form->disableReset();
            $form->disableSubmit();

            if (!boolval(config('app.debug', false))) {
                $form->display('error_datetime', exmtrans("error.error_datetime"))
                    ->default(\Carbon\Carbon::now()->format('Y/m/d H:i:s'))
                ;
            }

            $form->textarea('message', exmtrans("error.error_message"))
                ->default($exception->getMessage())
                ->attribute(['disabled' => true])
                ->rows(3)
            ;

            if (boolval(config('app.debug', false))) {
                $form->textarea('trace', exmtrans("error.error_trace"))
                    ->default($exception->getTraceAsString())
                    ->attribute(['disabled' => true])
                    ->rows(5)
                ;
            } else {
                $form->display('trace', exmtrans("error.error_trace"))
                    ->default(exmtrans("error.check_error_log"))
                ;
            }
            /** @phpstan-ignore-next-line constructor expects string, Encore\Admin\Widgets\Form given */
            $content->row(new Box(exmtrans("error.header"), $form));
        }));
    }


    public function maintenance()
    {
        return response(view('exment::exception.maintenance', [
            'manual_url' => \Exment::getManualUrl('troubleshooting') . '?id=' .exmtrans('error.maintenance_id'),
        ])->render(), 503);
    }
}
