<?php

namespace Skywatch\Laravel\Http\Middleware;

use Closure;
use Skywatch\Laravel\SkywatchClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TraceSpanMiddleware
{
    protected static ?float $startedAt = null;

    protected static ?string $traceId = null;

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('skywatch.tracing.enabled', true)) {
            return $next($request);
        }

        self::$startedAt = microtime(true);
        self::$traceId = $request->header('X-Request-Id')
            ?? $request->header('X-Correlation-Id')
            ?? $request->header('X-Trace-Id')
            ?? uniqid('trace_', true);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (self::$startedAt === null || self::$traceId === null) {
            return;
        }

        try {
            app(SkywatchClient::class)->flushSpans(
                self::$traceId,
                (microtime(true) - self::$startedAt) * 1000,
                $request,
                $response->getStatusCode()
            );
        } catch (\Throwable) {
            // Never break the app if tracing fails
        }
    }
}
