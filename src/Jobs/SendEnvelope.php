<?php

namespace Noneym\Loggerxo\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Noneym\Loggerxo\Client;

class SendEnvelope implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public string $endpoint,
        public string $key,
        public array $envelope,
        public float $timeout = 3.0,
        public bool $gzip = true,
    ) {}

    public function handle(): void
    {
        Client::send($this->endpoint, $this->key, $this->envelope, $this->timeout, $this->gzip);
    }
}
