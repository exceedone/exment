<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\ErrorController;

/**
 * The error page of Exment answers with the status of the error (a plugin page that is not
 * there: 404), the page itself stays the same.
 */
class ErrorPageStatusTest extends UnitTestBase
{
    public function testTheStatusOfTheError(): void
    {
        $this->assertSame(404, ErrorController::statusCode(new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException()));
        $this->assertSame(403, ErrorController::statusCode(new \Symfony\Component\HttpKernel\Exception\HttpException(403)));
        $this->assertSame(404, ErrorController::statusCode(new \Illuminate\Database\Eloquent\ModelNotFoundException()));
        $this->assertSame(200, ErrorController::statusCode(new \RuntimeException('something')));
    }

    public function testAPluginPageNotFoundIsA404(): void
    {
        try {
            abort(404);
        } catch (\Throwable $e) {
            $response = app(ErrorController::class)->error(request(), $e);
            $this->assertSame(404, $response->getStatusCode());
        }
    }
}
