<?php

namespace Noneym\Loggerxo\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Noneym\Loggerxo\Client;

class LoggerxoHandler extends AbstractProcessingHandler
{
    public function __construct(protected Client $client, int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (($record->context['loggerxo_internal'] ?? false) === true) {
            return;
        }
        $this->client->captureLogRecord(
            strtolower($record->level->name),
            $record->message,
            $record->context + ($record->extra ? ['extra' => $record->extra] : []),
            $record->channel
        );
    }
}
