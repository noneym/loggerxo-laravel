<?php

namespace Noneym\Loggerxo;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Str;

/**
 * Tracks one active transaction (request, job or command) and its spans.
 */
class Tracer
{
    protected ?array $transaction = null;

    protected array $spans = [];

    protected float $started = 0;

    protected float $dbTime = 0;

    protected int $queries = 0;

    protected bool $sampled = true;

    public function __construct(protected Client $client) {}

    public function start(string $name, string $op = 'http.server', array $data = []): void
    {
        $this->spans = [];
        $this->dbTime = 0;
        $this->queries = 0;
        $this->started = microtime(true);
        $this->sampled = (mt_rand() / mt_getrandmax()) <= (float) $this->client->config('traces_sample_rate', 1.0);
        $this->transaction = ['name' => $name, 'op' => $op, 'data' => $data];
    }

    public function active(): bool
    {
        return $this->transaction !== null;
    }

    public function name(): ?string
    {
        return $this->transaction['name'] ?? null;
    }

    public function rename(string $name): void
    {
        if ($this->transaction) {
            $this->transaction['name'] = $name;
        }
    }

    public function recordQuery(QueryExecuted $event): void
    {
        $this->queries++;
        $this->dbTime += $event->time;
        $sql = Str::limit($event->sql, 1000, '…');
        $this->client->addBreadcrumb('query', 'db.query', $sql, ['duration_ms' => round($event->time, 2), 'connection' => $event->connectionName]);
        $slow = (int) $this->client->config('slow_query_ms', 0);
        if ($event->time >= $slow) {
            $this->recordSpan('db.query', $sql, $event->time, ['connection' => $event->connectionName], $event->time);
        }
    }

    public function recordSpan(string $op, string $description, float $durationMs, array $data = [], ?float $endedMsAgo = null): void
    {
        if (! $this->transaction || count($this->spans) >= (int) $this->client->config('max_spans', 150)) {
            return;
        }
        $nowMs = (microtime(true) - $this->started) * 1000;
        $this->spans[] = [
            'op' => $op,
            'description' => $description,
            'start_ms' => round(max(0, $nowMs - $durationMs), 2),
            'duration_ms' => round($durationMs, 2),
            'data' => $data ?: null,
        ];
    }

    public function finish(int $statusCode = 200, array $extra = []): void
    {
        if (! $this->transaction) {
            return;
        }
        $duration = (microtime(true) - $this->started) * 1000;
        $tx = $this->transaction;
        $spans = $this->spans;
        $this->transaction = null;
        $this->spans = [];

        if (! $this->sampled) {
            return;
        }

        $this->client->captureTransaction([
            'name' => $tx['name'],
            'op' => $tx['op'],
            'status_code' => $statusCode,
            'duration_ms' => round($duration, 3),
            'measurements' => array_filter([
                'db_time_ms' => round($this->dbTime, 2),
                'db_query_count' => $this->queries,
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ] + ($extra['measurements'] ?? []), fn ($v) => $v !== null),
            'spans' => $spans,
            'context' => ['transaction' => $tx['data']] + ($extra['context'] ?? []),
        ] + array_diff_key($extra, ['measurements' => 1, 'context' => 1]));
    }
}
