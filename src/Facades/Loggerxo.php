<?php

namespace Noneym\Loggerxo\Facades;

use Illuminate\Support\Facades\Facade;
use Noneym\Loggerxo\Client;

/**
 * @method static bool enabled()
 * @method static ?string captureException(\Throwable $e, array $extra = [])
 * @method static ?string captureMessage(string $message, string $level = 'info', array $extra = [])
 * @method static void captureEvent(array $event)
 * @method static \Noneym\Loggerxo\Client setUser(?array $user)
 * @method static \Noneym\Loggerxo\Client setTag(string $key, string|int|float|bool|null $value)
 * @method static \Noneym\Loggerxo\Client setTags(array $tags)
 * @method static \Noneym\Loggerxo\Client setContext(string $key, mixed $value)
 * @method static \Noneym\Loggerxo\Client addBreadcrumb(string $type, string $category, string $message, array $data = [], string $level = 'info')
 * @method static \Noneym\Loggerxo\Tracer tracer()
 * @method static void flush()
 * @method static void resetScope()
 *
 * @see Client
 */
class Loggerxo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
