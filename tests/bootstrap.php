<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

/**
 * PHPUnit does not load .env files. Integration tests read DKBSIGN_* via getenv().
 */
$loadEnvFile = static function (string $path): void {
    if (! is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (! str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if ($name === '') {
            continue;
        }

        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($name) !== false) {
            continue;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
};

$root = dirname(__DIR__);
$loadEnvFile("{$root}/.env");
$loadEnvFile("{$root}/.env.local");
