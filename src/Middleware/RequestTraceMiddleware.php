<?php
namespace Exceedone\Exment\Middleware;

use Closure;
use Illuminate\Http\Request;
use Exceedone\Exment\Services\TraceCollector;
use Symfony\Component\HttpFoundation\Response;

class RequestTraceMiddleware
{
    protected TraceCollector $collector;

    // những prefix muốn bỏ qua (tùy chỉnh)
    protected array $skipPrefixes = [
        '/vendor/',
        '/assets/',
        '/favicon.ico',
        '/robots.txt',
    ];

    public function __construct(TraceCollector $collector)
    {
        $this->collector = $collector;
    }

    /**
     * Kiểm tra có nên bỏ qua request này (static assets...) hay không.
     */
    protected function shouldSkip(Request $request): bool
    {
        $uri = $request->getPathInfo();

        foreach ($this->skipPrefixes as $p) {
            // dùng strpos để tương thích mọi phiên bản PHP
            if ($p === $uri || strpos($uri, $p) === 0) {
                return true;
            }
        }

        return false;
    }

    public function handle(Request $request, Closure $next)
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        // chỉ log các thông tin tóm tắt, không log toàn bộ headers/body
        $this->collector->add('request:start', 'request', [
            'method' => $request->method(),
            'uri' => $request->getPathInfo(),
            'full_url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'content_length' => $request->header('content-length'),
        ]);

        /** @var Response $response */
        $response = $next($request);

        // thêm X-Request-Id để dễ correlate (nếu response hỗ trợ headers)
        if (method_exists($response, 'headers')) {
            $response->headers->set('X-Request-Id', $this->collector->requestId());
        }

        return $response;
    }

    public function terminate($request, $response)
    {
        if ($this->shouldSkip($request))
            return;

        $route = $request->route();
        $meta = [
            'method' => $request->method(),
            'uri' => $request->getPathInfo(),
            'route' => $route ? $route->getActionName() : null,
            'status' => $response->getStatusCode(),
        ];

        $summary = $this->collector->summary($meta);
        \Log::info('request-trace', $summary);
    }


}
