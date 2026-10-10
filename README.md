# Loggerxo for Laravel

Sends exceptions, log lines, breadcrumbs and performance transactions from a Laravel 9, 10, 11 or 12 app to a [Loggerxo](https://loggerxo.com) server.

## Install

```bash
composer config repositories.loggerxo vcs https://github.com/noneym/loggerxo-laravel
composer require noneym/loggerxo-laravel:dev-main
```

```env
LOGGERXO_DSN=https://lx_your_project_key@loggerxo.com
LOGGERXO_ENVIRONMENT=${APP_ENV}
LOGGERXO_RELEASE=1.0.0
LOG_STACK=single,loggerxo      # optional (Laravel 11+): ship every log line too
```

On Laravel 9/10 add the channel to `config/logging.php` instead:

```php
'loggerxo' => ['driver' => 'loggerxo', 'level' => env('LOGGERXO_LOG_LEVEL', 'debug')],
// and add 'loggerxo' to the 'stack' channel's 'channels' list
```

```bash
php artisan loggerxo:test
```

## What it does

| Source | How |
| --- | --- |
| Exceptions | Hooks the exception handler (`reportable`). Includes code context, request, user, breadcrumbs. |
| Logs | `loggerxo` Monolog channel. `LOGGERXO_LOG_LEVEL` sets the minimum level. |
| Breadcrumbs | SQL queries, log lines, outgoing HTTP calls, auth. |
| Transactions | HTTP requests (route pattern), queue jobs, artisan commands, with DB spans, memory, query counts. |

## Manual API

```php
use Noneym\Loggerxo\Facades\Loggerxo;

Loggerxo::captureException($e, ['tags' => ['module' => 'billing']]);
Loggerxo::captureMessage('Payout batch finished', 'info', ['context' => ['count' => 42]]);
Loggerxo::setUser(['id' => $user->id, 'email' => $user->email]);
Loggerxo::setTag('tenant', $tenant->slug);
Loggerxo::addBreadcrumb('cache', 'warm', 'warming products', ['took_ms' => 12]);
Loggerxo::flush();
```

## Configuration

`php artisan vendor:publish --tag=loggerxo-config` and see `config/loggerxo.php`. Notable keys: `ignore_exceptions`, `ignore_transactions`, `send_default_pii`, `traces_sample_rate`, `slow_query_ms`, `queue` (send via your queue instead of at request end), `mask`.

Events are buffered in memory and sent once per request/job/command (gzip, 3 s timeout). The SDK never throws.
