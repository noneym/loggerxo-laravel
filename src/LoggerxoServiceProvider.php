<?php

namespace Noneym\Loggerxo;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Noneym\Loggerxo\Console\TestCommand;
use Noneym\Loggerxo\Http\Middleware\TraceRequest;
use Noneym\Loggerxo\Logging\LoggerxoLogChannel;
use Throwable;

class LoggerxoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/loggerxo.php', 'loggerxo');

        $this->app->singleton(Client::class, fn ($app) => new Client($app['config']->get('loggerxo', []), $app));
        $this->app->alias(Client::class, 'loggerxo');
        $this->app->singleton(Tracer::class, fn ($app) => new Tracer($app->make(Client::class)));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/loggerxo.php' => config_path('loggerxo.php')], 'loggerxo-config');

        if ($this->app->runningInConsole()) {
            $this->commands([TestCommand::class]);
        }

        $client = $this->app->make(Client::class);
        if (! $client->enabled()) {
            return;
        }

        // Log channel: LOG_STACK=single,loggerxo
        Log::extend('loggerxo', fn ($app, array $config) => (new LoggerxoLogChannel($app->make(Client::class)))($config));

        if ($client->config('capture_exceptions', true)) {
            $this->app->afterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) use ($client) {
                if (method_exists($handler, 'reportable')) {
                    $handler->reportable(function (Throwable $e) use ($client) {
                        $client->captureException($e);
                    });
                }
            });
            // The handler may already be resolved by the time we boot.
            if ($this->app->resolved(ExceptionHandler::class)) {
                $handler = $this->app->make(ExceptionHandler::class);
                if (method_exists($handler, 'reportable')) {
                    $handler->reportable(fn (Throwable $e) => $client->captureException($e));
                }
            }
        }

        $this->registerBreadcrumbs($client);

        if ($client->config('tracing', true)) {
            $this->registerTracing($client);
        }

        $this->app->terminating(fn () => $client->flush());
        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            Event::listen(\Laravel\Octane\Events\RequestTerminated::class, function () use ($client) {
                $client->flush();
                $client->resetScope();
            });
        }
    }

    protected function registerBreadcrumbs(Client $client): void
    {
        Event::listen(Authenticated::class, function (Authenticated $event) use ($client) {
            $user = $event->user;
            $data = ['id' => (string) $user->getAuthIdentifier()];
            if ($client->config('send_default_pii')) {
                $data['email'] = $user->email ?? null;
                $data['username'] = $user->name ?? null;
            }
            $client->setUser(array_filter($data));
        });

        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($client) {
            if (($event->context['loggerxo_internal'] ?? false) === true) {
                return;
            }
            $client->addBreadcrumb('log', $event->level, $event->message, array_slice($event->context, 0, 10), $event->level);
        });

        if ($client->config('trace_queries', true)) {
            Event::listen(QueryExecuted::class, function (QueryExecuted $event) use ($client) {
                $client->tracer()->recordQuery($event);
            });
        }

        Event::listen(ResponseReceived::class, function (ResponseReceived $event) use ($client) {
            $url = (string) $event->request->url();
            if ($client->isOwnEndpoint($url)) {
                return;
            }
            $client->addBreadcrumb('http', 'http.client', $event->request->method().' '.$url.' ['.$event->response->status().']', ['status_code' => $event->response->status()], $event->response->status() >= 500 ? 'error' : 'info');
            $client->tracer()->recordSpan('http.client', $event->request->method().' '.$url, $event->response->transferStats?->getTransferTime() ? $event->response->transferStats->getTransferTime() * 1000 : 0);
        });
        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event) use ($client) {
            $url = (string) $event->request->url();
            if (! $client->isOwnEndpoint($url)) {
                $client->addBreadcrumb('http', 'http.client', $event->request->method().' '.$url.' [connection failed]', [], 'error');
            }
        });
    }

    protected function registerTracing(Client $client): void
    {
        $this->app->booted(function () {
            if ($this->app->bound(HttpKernel::class)) {
                $kernel = $this->app->make(HttpKernel::class);
                if (method_exists($kernel, 'prependMiddleware')) {
                    $kernel->prependMiddleware(TraceRequest::class);
                }
            }
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($client) {
            $client->resetScope();
            $client->tracer()->start($event->job->resolveName(), 'queue.job', ['queue' => $event->job->getQueue(), 'connection' => $event->connectionName]);
            $client->setTag('queue', (string) $event->job->getQueue());
        });
        Event::listen(JobProcessed::class, function () use ($client) {
            $client->tracer()->finish(200);
            $client->flush();
        });
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use ($client) {
            $client->tracer()->finish(500);
            $client->flush();
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($client) {
            if (! $event->command || in_array($event->command, ['loggerxo:ingest', 'queue:work', 'queue:listen', 'horizon', 'schedule:work', 'serve', 'tinker'], true)) {
                return;
            }
            $client->tracer()->start('artisan '.$event->command, 'console.command');
        });
        Event::listen(CommandFinished::class, function (CommandFinished $event) use ($client) {
            if ($client->tracer()->active()) {
                $client->tracer()->finish($event->exitCode === 0 ? 200 : 500);
                $client->flush();
            }
        });
    }
}
