<?php

namespace Noneym\Loggerxo\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Noneym\Loggerxo\Client;

/**
 * Monolog 2 variant (Laravel 9). Monolog 3 uses LoggerxoHandler; LoggerxoLogChannel picks one at runtime.
 */
class LoggerxoHandlerV2 extends AbstractProcessingHandler
{
    public function __construct(protected Client $client, $level = 100, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        if (($record['context']['loggerxo_internal'] ?? false) === true) {
            return;
        }
        $this->client->captureLogRecord(
            strtolower((string) ($record['level_name'] ?? 'info')),
            (string) ($record['message'] ?? ''),
            (array) ($record['context'] ?? []) + (! empty($record['extra']) ? ['extra' => $record['extra']] : []),
            (string) ($record['channel'] ?? 'loggerxo')
        );
    }
}
