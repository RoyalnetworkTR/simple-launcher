<?php
/**
 * Athena Studios Launcher - Backend Configuration
 *
 * Single place for all runtime constants and the bootstrap routine that
 * ensures the required directories and seed files exist.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------
define('BASE_DIR', dirname(__DIR__));            // .../WebBackend
define('FILES_DIR', BASE_DIR . '/files');        // admin content drop-zone (gitignored)
define('DATA_DIR', BASE_DIR . '/data');          // sqlite db + private state (gitignored)
define('DB_PATH', DATA_DIR . '/metrics.db');     // sqlite database file
define('SEED_DIR', BASE_DIR . '/seed');          // default config templates
define('SKINS_DIR', FILES_DIR . '/skins');       // uploaded player skins (web-accessible)

// Dynamic-pack state / changelog / version (private, served via /api routes).
define('STATE_PATH', DATA_DIR . '/pack_state.json');     // incremental scan cache
define('CHANGELOG_PATH', DATA_DIR . '/changelog.json');  // auto-generated release notes
define('VERSION_PATH', DATA_DIR . '/version.json');      // current pack version + latest note
define('LOCK_PATH', DATA_DIR . '/pack.lock');            // refresh lock (avoids double bumps)

// ---------------------------------------------------------------------------
// Branding / defaults
// ---------------------------------------------------------------------------
define('BRAND', 'Athena Studios');
define('DEFAULT_SERVER_IP', 'oyna.athenastudios.com.tr');

// Files that are managed by the backend itself and must never be advertised
// in index.json / mods.json as downloadable client content, nor served raw
// via /files/.
define('META_FILES', ['index.json', 'server_config.json', 'mods.json', 'mods_classification.json']);

// ---------------------------------------------------------------------------
// Bootstrap (runs on include) - create dirs BEFORE anything reads them.
// ---------------------------------------------------------------------------
if (!is_dir(FILES_DIR)) {
    @mkdir(FILES_DIR, 0775, true);
}
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}
if (!is_dir(SKINS_DIR)) {
    @mkdir(SKINS_DIR, 0775, true);
}
// Private PHP session storage for the admin panel (never web-accessible: lives
// under data/ which Apache 404s). Tight perms so only the PHP user can read it.
define('SESSIONS_DIR', DATA_DIR . '/sessions');
if (!is_dir(SESSIONS_DIR)) {
    @mkdir(SESSIONS_DIR, 0700, true);
}
@chmod(SESSIONS_DIR, 0700);

// Secrets / feature config loaded from data/.env (gitignored, nginx-blocked).
require_once __DIR__ . '/env.php';
// Minecraft accounts allowed per Discord user (clamped to a sane 1-10 range).
define('ACCOUNT_CAP', max(1, min(10, (int) env_get('ACCOUNT_CAP', '5'))));

// Founder Discord id - always seeded as the 'kurucu' (owner) panel role and can
// never be locked out. Overridable via .env, but the hardcoded default stands
// on its own so the founder role survives even without the env key.
define('ATHENA_FOUNDER_DISCORD_ID', env_get('ATHENA_FOUNDER_DISCORD_ID', '637985724007841812'));

// ---------------------------------------------------------------------------
// Admin password (never ship a committed/hardcoded password).
//
// Resolution order:
//   1) ATHENA_ADMIN_PW environment variable (recommended for production).
//   2) data/admin_password.txt  - persisted; edit this file to set your own.
//   3) Otherwise a strong random password is generated ONCE and written to
//      data/admin_password.txt. Read it from there after first deploy.
//
// ALWAYS serve the admin panel over HTTPS - the password travels in the URL.
// ---------------------------------------------------------------------------
$__envPw = getenv('ATHENA_ADMIN_PW');
if (is_string($__envPw) && $__envPw !== '') {
    define('ADMIN_PASSWORD', $__envPw);
} else {
    $__pwFile = DATA_DIR . '/admin_password.txt';
    $__pw = is_file($__pwFile) ? trim((string) file_get_contents($__pwFile)) : '';
    if ($__pw === '') {
        try {
            $__pw = bin2hex(random_bytes(16)); // 32 hex (128-bit)
        } catch (Throwable $e) {
            $__pw = substr(hash('sha256', uniqid('athena', true)), 0, 32);
        }
        @file_put_contents($__pwFile, $__pw . "\n");
        @chmod($__pwFile, 0600);
    }
    define('ADMIN_PASSWORD', $__pw);
}

// Reverse proxies whose X-Forwarded-For we trust. On a typical VPS the PHP
// process sits behind a local reverse proxy (nginx/apache) on loopback, so we
// only honor XFF when the immediate peer is loopback/private. Extend if needed.
define('TRUSTED_PROXY_PREFIXES', [
    '127.', '::1', '10.', '192.168.',
    '172.16.', '172.17.', '172.18.', '172.19.', '172.20.', '172.21.', '172.22.', '172.23.',
    '172.24.', '172.25.', '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.',
]);

// ---------------------------------------------------------------------------
// Seed default content if the admin has not provided it yet.
// ---------------------------------------------------------------------------
$cfgTarget = FILES_DIR . '/server_config.json';
$cfgSeed   = SEED_DIR . '/server_config.json';
if (!is_file($cfgTarget) && is_file($cfgSeed)) {
    @copy($cfgSeed, $cfgTarget);
}

$classTarget = FILES_DIR . '/mods_classification.json';
$classSeed   = SEED_DIR . '/mods_classification.json';
if (!is_file($classTarget) && is_file($classSeed)) {
    @copy($classSeed, $classTarget);
}
