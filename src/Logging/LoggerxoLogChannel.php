<?php

namespace Noneym\Loggerxo\Logging;

use Monolog\Level;
use Monolog\Logger;
use Noneym\Loggerxo\Client;

/**
 * Custom Laravel log channel. In config/logging.php this is registered automatically as driver "loggerxo";
 * add "loggerxo" to LOG_STACK to use it.
 */
class LoggerxoLogChannel
{
    public function __construct(protected Client $client) {}

    public function __invoke(array $config): Logger
    {
        $level = $config['level'] ?? $this->client->config('log_level', 'debug');
        $monologLevel = Level::fromName(ucfirst(strtolower((string) $level)));

        return new Logger($config['name'] ?? 'loggerxo', [new LoggerxoHandler($this->client, $monologLevel)]);
    }
}
