<?php

namespace Exceedone\Exment\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use ExmentAdminCore\Admin\Layout\Content;
use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
use ExmentAdminCore\Admin\Facades\Admin;
use ExmentAdminCore\Admin\Widgets\Box;

class ErrorController extends Controller
{
    // public function __construct(Request $request)
    // {
    //     parent::__construct($request);
    // }

    /**
     * Index interface.
     */
    // @phpstan-ignore-next-line
    public function error(Request $request, $exception)
    {
        /** @phpstan-ignore-next-line response expects array|Illuminate\Contracts\View\View|string|null, ExmentAdminCore\Admin\Layout\Content given */
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
            $content->row(new Box(exmtrans("error.header"), $form));
        }), static::statusCode($exception));
    }

    /**
     * A page that is not there (the route of a plugin page that is not there): 404, shown as
     * Exment's error page by the handler of the errors (Exment::error()).
     *
     * @return never
     */
    public function notFound()
    {
        abort(404);
    }

    /**
     * The status of the error page: the one of an HTTP exception (abort(404) of a plugin page,
     * a 403), 404 for a record not found, else 200 as before (the page itself is the same).
     *
     * @param mixed $exception
     */
    public static function statusCode($exception): int
    {
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return $exception->getStatusCode();
        }
        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 404;
        }
        return 200;
    }


    // @phpstan-ignore-next-line
    public function maintenance()
    {
        return response(view('exment::exception.maintenance', [
            'manual_url' => \Exment::getManualUrl('troubleshooting') . '?id=' .exmtrans('error.maintenance_id'),
        ])->render(), 503);
    }
}
