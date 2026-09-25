<?php

namespace FaultScope\Laravel\Http\Middleware;

use Closure;
use FaultScope\Laravel\FaultScopeClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InjectFaultScopeJsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('faultscope.enabled', true) ||
            ! config('faultscope.javascript.enabled', true) ||
            ! config('faultscope.javascript.auto_inject', false)) {
            return $response;
        }

        // Only inject into regular GET HTML responses (not AJAX, PJAX, or JSON API requests)
        if ($request->isMethod('GET') && ! $request->ajax() && ! $request->pjax() && ! $request->wantsJson()) {
            $contentType = $response->headers->get('Content-Type');
            if ($contentType && str_contains(strtolower($contentType), 'text/html')) {
                $content = $response->getContent();
                if ($content && ! str_contains($content, 'FaultScope.init')) {
                    $snippet = FaultScopeClient::renderJsScripts();
                    if (str_contains($content, '</head>')) {
                        $content = preg_replace('/(<\/head>)/i', $snippet."\n$1", $content, 1);
                        $response->setContent($content);
                    } elseif (str_contains($content, '</body>')) {
                        $content = preg_replace('/(<\/body>)/i', $snippet."\n$1", $content, 1);
                        $response->setContent($content);
                    }
                }
            }
        }

        return $response;
    }
}
