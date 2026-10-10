<?php

namespace Noneym\Loggerxo\Console;

use Illuminate\Console\Command;
use Noneym\Loggerxo\Client;
use RuntimeException;

class TestCommand extends Command
{
    protected $signature = 'loggerxo:test';

    protected $description = 'Send a test exception, log line and transaction to Loggerxo';

    public function handle(Client $client): int
    {
        if (! $client->enabled()) {
            $this->error('LOGGERXO_DSN is not configured.');

            return self::FAILURE;
        }

        $client->setTag('source', 'loggerxo:test');
        $client->addBreadcrumb('default', 'test', 'Breadcrumb recorded before the test exception');
        $client->captureMessage('Loggerxo test log line from '.config('app.name'), 'warning', ['context' => ['php' => PHP_VERSION]]);

        try {
            $this->boom();
        } catch (RuntimeException $e) {
            $id = $client->captureException($e, ['tags' => ['test' => 'true']]);
            $this->info('Captured test exception '.$id);
        }

        $client->tracer()->start('artisan loggerxo:test (sample)', 'console.command');
        usleep(50_000);
        $client->tracer()->recordSpan('db.query', 'select 1', 1.5);
        $client->tracer()->finish(200);

        $client->flush();
        $this->info('Events sent. They should appear in your project within a few seconds.');

        return self::SUCCESS;
    }

    protected function boom(): void
    {
        throw new RuntimeException('This is a test exception from loggerxo:test', 42, new \LogicException('Previous exception for the chain'));
    }
}
