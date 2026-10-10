<?php

namespace Noneym\Loggerxo\Logging;

use Monolog\Logger;
use Noneym\Loggerxo\Client;

/**
 * Custom Laravel log channel, registered as driver "loggerxo".
 *
 * Laravel 11+: add "loggerxo" to LOG_STACK.
 * Laravel 9/10: add a channel to config/logging.php:
 *   'loggerxo' => ['driver' => 'loggerxo', 'level' => env('LOGGERXO_LOG_LEVEL', 'debug')],
 * and list it in the "stack" channel's channels array.
 */
class LoggerxoLogChannel
{
    public function __construct(protected Client $client) {}

    public function __invoke(array $config): Logger
    {
        $level = Logger::toMonologLevel($config['level'] ?? $this->client->config('log_level', 'debug'));

        $handler = Logger::API >= 3
            ? new LoggerxoHandler($this->client, $level)
            : new LoggerxoHandlerV2($this->client, $level);

        return new Logger($config['name'] ?? 'loggerxo', [$handler]);
    }
}
