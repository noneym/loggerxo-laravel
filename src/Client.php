<?php

namespace Noneym\Loggerxo;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Noneym\Loggerxo\Jobs\SendEnvelope;
use Throwable;

class Client
{
    public const VERSION = '0.1.0';

    protected ?string $key = null;

    protected ?string $endpoint = null;

    protected array $buffer = [];

    protected array $breadcrumbs = [];

    protected ?array $user = null;

    protected array $tags = [];

    protected array $context = [];

    protected ?Tracer $tracer = null;

    protected bool $sending = false;

    public function __construct(protected array $config, protected ?Application $app = null)
    {
        $this->parseDsn((string) ($config['dsn'] ?? ''));
    }

    protected function parseDsn(string $dsn): void
    {
        if ($dsn === '') {
            return;
        }
        $parts = parse_url($dsn);
        if (! $parts || empty($parts['host'])) {
            return;
        }
        $key = $parts['user'] ?? null;
        if (! $key && ! empty($parts['query'])) {
            parse_str($parts['query'], $q);
            $key = $q['key'] ?? null;
        }
        if (! $key) {
            return;
        }
        $this->key = $key;
        $this->endpoint = sprintf('%s://%s%s/api/v1/envelope', $parts['scheme'] ?? 'https', $parts['host'], isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    public function enabled(): bool
    {
        return $this->key !== null && $this->endpoint !== null;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function tracer(): Tracer
    {
        return $this->tracer ??= ($this->app ? $this->app->make(Tracer::class) : new Tracer($this));
    }

    public function isOwnEndpoint(string $url): bool
    {
        return $this->endpoint !== null && str_starts_with($url, substr($this->endpoint, 0, strpos($this->endpoint, '/api/v1')));
    }

    /* ------------------------------------------------------------- scope */

    public function setUser(?array $user): static
    {
        $this->user = $user ?: null;

        return $this;
    }

    public function setTag(string $key, string|int|float|bool|null $value): static
    {
        if ($value === null) {
            unset($this->tags[$key]);
        } else {
            $this->tags[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $this;
    }

    public function setTags(array $tags): static
    {
        foreach ($tags as $k => $v) {
            $this->setTag((string) $k, $v);
        }

        return $this;
    }

    public function setContext(string $key, mixed $value): static
    {
        $this->context[$key] = $value;

        return $this;
    }

    public function addBreadcrumb(string $type, string $category, string $message, array $data = [], string $level = 'info'): static
    {
        $this->breadcrumbs[] = [
            'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'type' => $type,
            'category' => $category,
            'message' => Str::limit($message, 500, '…'),
            'level' => $level,
            'data' => $data ?: null,
        ];
        $max = (int) $this->config('max_breadcrumbs', 40);
        if (count($this->breadcrumbs) > $max) {
            array_splice($this->breadcrumbs, 0, count($this->breadcrumbs) - $max);
        }

        return $this;
    }

    public function resetScope(): void
    {
        $this->breadcrumbs = [];
        $this->user = null;
        $this->tags = [];
        $this->context = [];
    }

    /* ----------------------------------------------------------- capture */

    public function captureException(Throwable $e, array $extra = []): ?string
    {
        if (! $this->enabled() || $this->shouldIgnore($e)) {
            return null;
        }
        try {
            $event = $this->baseEvent('error', $extra['level'] ?? 'error');
            $event['exception'] = StackTrace::fromThrowable($e, $this->config);
            $event['message'] = get_class($e).': '.$e->getMessage();
            $event['logger'] = $extra['logger'] ?? 'exception';
            $this->applyExtra($event, $extra);
            $this->enqueue($event);

            return $event['event_id'];
        } catch (Throwable) {
            return null;
        }
    }

    public function captureMessage(string $message, string $level = 'info', array $extra = []): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            $event = $this->baseEvent('log', $level);
            $event['message'] = $message;
            $event['logger'] = $extra['logger'] ?? 'app';
            $this->applyExtra($event, $extra);
            $this->enqueue($event);

            return $event['event_id'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Log channel entry point: a Monolog record.
     */
    public function captureLogRecord(string $level, string $message, array $context, string $channel): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            $exception = $context['exception'] ?? null;
            unset($context['exception']);
            $event = $this->baseEvent($exception instanceof Throwable ? 'error' : 'log', $level);
            $event['message'] = $message;
            $event['logger'] = $channel;
            if ($exception instanceof Throwable) {
                if ($this->shouldIgnore($exception)) {
                    return;
                }
                $event['exception'] = StackTrace::fromThrowable($exception, $this->config);
            }
            if ($context !== []) {
                $event['context'] = ['log' => $this->sanitize($context)];
            }
            $this->enqueue($event);
        } catch (Throwable) {
            // never break logging
        }
    }

    public function captureEvent(array $event): void
    {
        if (! $this->enabled()) {
            return;
        }
        $event += $this->baseEvent($event['type'] ?? 'log', $event['level'] ?? 'info');
        $this->enqueue($event);
    }

    public function captureTransaction(array $transaction): void
    {
        if (! $this->enabled()) {
            return;
        }
        $base = $this->baseEvent('transaction', 'info');
        unset($base['breadcrumbs']);
        $this->enqueue($transaction + $base);
    }

    protected function applyExtra(array &$event, array $extra): void
    {
        if (! empty($extra['tags'])) {
            $event['tags'] = array_merge($event['tags'], array_map('strval', $extra['tags']));
        }
        if (! empty($extra['context'])) {
            $event['context'] = array_merge($event['context'], $this->sanitize($extra['context']));
        }
        if (! empty($extra['fingerprint'])) {
            $event['fingerprint'] = (array) $extra['fingerprint'];
        }
        if (! empty($extra['user'])) {
            $event['user'] = $extra['user'];
        }
    }

    protected function baseEvent(string $type, string $level): array
    {
        $request = $this->currentRequest();
        $event = [
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'level' => $level,
            'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'platform' => 'php',
            'server_name' => $this->config('server_name'),
            'transaction' => $this->tracer()->name(),
            'tags' => $this->tags + ['php' => PHP_VERSION, 'laravel' => $this->app?->version() ?? ''],
            'context' => $this->context + ['runtime' => ['sapi' => PHP_SAPI, 'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1)]],
            'breadcrumbs' => $this->breadcrumbs,
            'user' => $this->user,
        ];
        if ($request) {
            $event['request'] = $this->requestData($request);
            $event['url'] = $request->fullUrl();
            $event['user_agent'] = (string) $request->userAgent();
            if ($this->config('send_default_pii')) {
                $event['user'] = ($event['user'] ?? []) + ['ip' => $request->ip()];
            }
        }

        return $event;
    }

    protected function currentRequest(): ?Request
    {
        if (! $this->app || $this->app->runningInConsole() || ! $this->app->bound('request')) {
            return null;
        }
        try {
            $request = $this->app->make('request');

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function requestData(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $k => $v) {
            $headers[$k] = implode(', ', $v);
        }

        return [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'headers' => $this->sanitize($headers),
            'query' => $this->sanitize($request->query()),
            'data' => $request->isMethod('GET') ? [] : $this->sanitize($this->limit($request->except($this->config('mask', [])))),
            'ip' => $this->config('send_default_pii') ? $request->ip() : null,
            'route' => $request->route()?->getName() ?: null,
        ];
    }

    public function sanitize(array $data, int $depth = 0): array
    {
        $mask = array_map('strtolower', (array) $this->config('mask', []));
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), $mask, true)) {
                $out[$k] = '[Filtered]';
            } elseif (is_array($v)) {
                $out[$k] = $depth < 5 ? $this->sanitize($v, $depth + 1) : '[array]';
            } elseif (is_object($v)) {
                $out[$k] = method_exists($v, '__toString') ? Str::limit((string) $v, 500) : '['.get_class($v).']';
            } elseif (is_string($v)) {
                $out[$k] = Str::limit($v, 2000);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    protected function limit(array $data): array
    {
        return count($data) > 100 ? array_slice($data, 0, 100, true) + ['…' => '[truncated]'] : $data;
    }

    protected function shouldIgnore(Throwable $e): bool
    {
        foreach ((array) $this->config('ignore_exceptions', []) as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /* --------------------------------------------------------- transport */

    protected function enqueue(array $event): void
    {
        $this->buffer[] = $event;
        if (count($this->buffer) >= (int) $this->config('max_events_per_envelope', 100)) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === [] || ! $this->enabled() || $this->sending) {
            return;
        }
        $events = $this->buffer;
        $this->buffer = [];
        $envelope = [
            'sdk' => ['name' => 'loggerxo-laravel', 'version' => self::VERSION],
            'environment' => (string) $this->config('environment', 'production'),
            'release' => (string) ($this->config('release') ?? ''),
            'platform' => 'php',
            'events' => $events,
        ];

        if ($this->config('queue') && $this->app && $this->app->bound('queue')) {
            try {
                SendEnvelope::dispatch($this->endpoint, $this->key, $envelope, (float) $this->config('timeout', 3), (bool) $this->config('gzip', true))
                    ->onQueue((string) $this->config('queue_name', 'default'));

                return;
            } catch (Throwable) {
                // fall through to direct send
            }
        }

        $this->sending = true;
        try {
            static::send($this->endpoint, $this->key, $envelope, (float) $this->config('timeout', 3), (bool) $this->config('gzip', true));
        } catch (Throwable) {
            // swallow: monitoring must never break the app
        } finally {
            $this->sending = false;
        }
    }

    public static function send(string $endpoint, string $key, array $envelope, float $timeout = 3.0, bool $gzip = true): void
    {
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($body === false) {
            return;
        }
        $headers = ['X-Loggerxo-Key' => $key, 'Content-Type' => 'application/json', 'User-Agent' => 'loggerxo-laravel/'.self::VERSION];
        if ($gzip && function_exists('gzencode') && strlen($body) > 1024) {
            $body = gzencode($body, 6);
            $headers['Content-Encoding'] = 'gzip';
        }
        Http::withHeaders($headers)->timeout($timeout)->connectTimeout(min(2.0, $timeout))->retry(1, 200, throw: false)->withBody($body, 'application/json')->post($endpoint);
    }
}
