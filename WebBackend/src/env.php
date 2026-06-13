<?php
/**
 * Athena Studios Launcher - Minimal .env loader for backend secrets.
 *
 * Reads DATA_DIR/.env (KEY=VALUE, one per line) which is gitignored and blocked
 * by nginx (location ~ ^/(data|seed|src|cli)/ { deny all; }). Values also fall
 * back to real process environment variables, so a deployment can use either.
 *
 * Never echo these values. Secrets: DISCORD_CLIENT_SECRET, JWT_SECRET,
 * ATHENA_SERVER_KEY, DISCORD_BOT_TOKEN.
 */

declare(strict_types=1);

/** Parse and cache the .env file into an associative array. */
function env_all(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];

    $dir  = defined('DATA_DIR') ? DATA_DIR : (__DIR__ . '/../data');
    $file = $dir . '/.env';
    if (is_file($file) && is_readable($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $eq = strpos($line, '=');
                if ($eq === false) {
                    continue;
                }
                $k = trim(substr($line, 0, $eq));
                $v = trim(substr($line, $eq + 1));
                // Strip a single layer of matching surrounding quotes.
                $len = strlen($v);
                if ($len >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[$len - 1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                if ($k !== '') {
                    $cache[$k] = $v;
                }
            }
        }
    }
    return $cache;
}

/**
 * Get a config/secret value: .env first, then process env, then $default.
 * Returns $default (may be null) when unset or empty.
 */
function env_get(string $key, ?string $default = null): ?string
{
    $all = env_all();
    if (array_key_exists($key, $all) && $all[$key] !== '') {
        return $all[$key];
    }
    $envv = getenv($key);
    if (is_string($envv) && $envv !== '') {
        return $envv;
    }
    return $default;
}

/** True when a non-empty value is configured (used to gate optional features). */
function env_has(string $key): bool
{
    return env_get($key) !== null;
}
