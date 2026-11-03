<?php
/**
 * Application Bootstrap
 * 
 * Initializes environment, autoloader, error handlers, and configuration.
 */

declare(strict_types=1);

// Prevent multiple bootstrap calls
if (defined('HELPDESK_BOOTSTRAPPED')) {
    return;
}
define('HELPDESK_BOOTSTRAPPED', true);

$baseDir = dirname(__DIR__);

// 1. Load .env file if it exists
$envFile = $baseDir . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments and empty lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Remove inline comments if unquoted
            if (!str_starts_with($value, '"') && !str_starts_with($value, "'") && str_contains($value, '#')) {
                $value = trim(explode('#', $value)[0]);
            }
            // Strip enclosing quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

// 2. Class Autoloader
spl_autoload_register(function (string $class) use ($baseDir): void {
    // Map of directories containing application classes
    $directories = [
        $baseDir . '/database/',
        $baseDir . '/services/',
        $baseDir . '/mail/',
    ];

    // Strip namespaces if present (e.g. App\Services\TicketService -> TicketService)
    $parts = explode('\\', $class);
    $className = end($parts);

    foreach ($directories as $dir) {
        $file = $dir . $className . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// 3. Global Configuration Repository
class Config
{
    private static array $items = [];

    public static function load(string $baseDir): void
    {
        if (!empty(self::$items)) {
            return;
        }
        $configDir = $baseDir . DIRECTORY_SEPARATOR . 'config';
        foreach (['app', 'database', 'mail'] as $file) {
            $path = $configDir . DIRECTORY_SEPARATOR . $file . '.php';
            if (file_exists($path)) {
                self::$items[$file] = require $path;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $data = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            $data = $data[$segment];
        }

        return $data;
    }
}

Config::load($baseDir);

// 4. Timezone Configuration
$timezone = Config::get('app.timezone', 'UTC');
date_default_timezone_set($timezone);

// 5. Error & Exception Handling
$debug = (bool)Config::get('app.debug', false);
if ($debug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// Global helper function for configuration
if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}
