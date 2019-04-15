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
     *
     * @return Content
     */
    public function error(Request $request, $exception)
    {
        return response(Admin::content(function (Content $content) use ($request, $exception) {
            $content->header(exmtrans('error.header'));
            $content->description(exmtrans('error.description'));

            $form = new WidgetForm();
            $form->disableReset();
            $form->disableSubmit();

            $form->textarea('message', exmtrans("error.error_message"))
                ->default($exception->getMessage())
                ->attribute(['disabled' => true])
                ->rows(3)
                ;
            $form->textarea('trace', exmtrans("error.error_trace"))
                ->default($exception->getTraceAsString())
                ->attribute(['disabled' => true])
                ->rows(5)
                ;

            $content->row(new Box(exmtrans("error.header"), $form));
        }));
    }
}
