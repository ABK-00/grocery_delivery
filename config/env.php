<?php

declare(strict_types=1);

/**
 * Simple .env loader.
 *
 * Loads variables from the project's root .env file
 * into $_ENV, $_SERVER and getenv().
 */

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file(
        $path,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Ignore empty lines and comments.
        if (
            $line === '' ||
            str_starts_with($line, '#')
        ) {
            continue;
        }

        // Ignore malformed lines.
        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);

        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        // Remove matching quotes.
        if (
            strlen($value) >= 2 &&
            (
                ($value[0] === '"' && $value[-1] === '"') ||
                ($value[0] === "'" && $value[-1] === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;

        putenv($name . '=' . $value);
    }
}

loadEnv(dirname(__DIR__) . '/.env');