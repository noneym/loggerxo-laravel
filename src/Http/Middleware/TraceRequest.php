<?php

namespace Noneym\Loggerxo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Noneym\Loggerxo\Client;
use Symfony\Component\HttpFoundation\Response;

class TraceRequest
{
    public function __construct(protected Client $client) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->client->enabled() || $this->ignored($request)) {
            return $next($request);
        }

        $this->client->tracer()->start($request->method().' '.$request->path(), 'http.server', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
        ]);

        $response = $next($request);

        // Route is resolved by now: use the pattern so /orders/1 and /orders/2 group together.
        $route = $request->route();
        if ($route) {
            $uri = method_exists($route, 'uri') ? $route->uri() : $request->path();
            $this->client->tracer()->rename($request->method().' /'.ltrim($uri, '/'));
            if ($route->getName()) {
                $this->client->setTag('route', $route->getName());
            }
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->client->tracer()->active()) {
            return;
        }
        $this->client->tracer()->finish($response->getStatusCode(), [
            'http_method' => $request->method(),
            'url' => $request->fullUrl(),
            'user_agent' => (string) $request->userAgent(),
            'request' => ['url' => $request->fullUrl(), 'method' => $request->method(), 'route' => $request->route()?->getName()],
        ]);
    }

    protected function ignored(Request $request): bool
    {
        $path = $request->path();
        foreach ((array) $this->client->config('ignore_transactions', []) as $pattern) {
            if (Str::is($pattern, $path) || ($request->route()?->getName() && Str::is($pattern, $request->route()->getName()))) {
                return true;
            }
        }

        return false;
    }
}
