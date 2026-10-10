<?php

namespace Noneym\Loggerxo;

use Throwable;

class StackTrace
{
    public static function fromThrowable(Throwable $e, array $config, int $depth = 0): array
    {
        $out = [
            'type' => get_class($e),
            'value' => mb_substr($e->getMessage(), 0, 4000),
            'module' => $e->getFile(),
            'code' => is_scalar($e->getCode()) ? (string) $e->getCode() : null,
            'frames' => self::frames($e, $config),
        ];
        if ($depth < 5 && $e->getPrevious()) {
            $out['chain'] = [self::fromThrowable($e->getPrevious(), $config, $depth + 1)];
            // flatten nested chains
            if (isset($out['chain'][0]['chain'])) {
                $out['chain'] = array_merge($out['chain'], $out['chain'][0]['chain']);
                unset($out['chain'][0]['chain']);
            }
        }

        return $out;
    }

    public static function frames(Throwable $e, array $config): array
    {
        $base = function_exists('base_path') ? rtrim(base_path(), DIRECTORY_SEPARATOR) : '';
        $include = (array) ($config['in_app_include'] ?? ['app']);
        $contextLines = (int) ($config['context_lines'] ?? 5);

        $frames = [[
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'function' => null,
            'class' => null,
        ]];
        foreach ($e->getTrace() as $t) {
            $frames[] = [
                'file' => $t['file'] ?? null,
                'line' => $t['line'] ?? null,
                'function' => $t['function'] ?? null,
                'class' => $t['class'] ?? null,
            ];
        }

        $out = [];
        $contextBudget = 12;
        foreach (array_slice($frames, 0, 60) as $frame) {
            $file = $frame['file'] ?? '';
            $inApp = self::isInApp($file, $base, $include);
            $row = [
                'file' => $base && str_starts_with($file, $base) ? ltrim(substr($file, strlen($base)), '/\\') : $file,
                'line' => $frame['line'],
                'function' => $frame['function'],
                'class' => $frame['class'],
                'in_app' => $inApp,
            ];
            if ($inApp && $contextLines > 0 && $contextBudget > 0 && $file && $frame['line'] && is_readable($file)) {
                $ctx = self::context($file, (int) $frame['line'], $contextLines);
                if ($ctx) {
                    $row['context'] = $ctx;
                    $contextBudget--;
                }
            }
            $out[] = array_filter($row, fn ($v) => $v !== null);
        }

        return $out;
    }

    protected static function isInApp(string $file, string $base, array $include): bool
    {
        if ($file === '' || str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR) || str_contains($file, '/vendor/')) {
            return false;
        }
        if ($base === '') {
            return true;
        }
        if (! str_starts_with($file, $base)) {
            return false;
        }
        $rel = ltrim(substr($file, strlen($base)), '/\\');
        foreach ($include as $dir) {
            if (str_starts_with($rel, $dir.'/') || str_starts_with($rel, $dir.'\\')) {
                return true;
            }
        }

        return false;
    }

    protected static function context(string $file, int $line, int $lines): ?array
    {
        try {
            if (filesize($file) > 2_000_000) {
                return null;
            }
            $all = file($file, FILE_IGNORE_NEW_LINES);
            if ($all === false || ! isset($all[$line - 1])) {
                return null;
            }
            $trim = fn ($l) => mb_substr(rtrim((string) $l), 0, 300);
            $pre = array_map($trim, array_slice($all, max(0, $line - 1 - $lines), min($lines, $line - 1)));
            $post = array_map($trim, array_slice($all, $line, $lines));

            return ['pre' => $pre, 'line' => $trim($all[$line - 1]), 'post' => $post];
        } catch (Throwable) {
            return null;
        }
    }
}
