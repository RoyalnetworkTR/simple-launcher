<?php
/**
 * Athena Studios Launcher - Admin panel authentication & RBAC.
 *
 * Replaces the legacy `?password=` query-string auth with a hardened PHP
 * session. Two ways in:
 *   - Discord OAuth (role resolved from the admin_roles table; founder is
 *     always kurucu), via a DEDICATED redirect_uri separate from the launcher.
 *   - A break-glass local username/password account (bound to kurucu),
 *     generated once on first boot.
 *
 * Authorization is permission-based (see admin_permissions). A logged-in user
 * with NO role gets nothing - not even read access. Roles are re-resolved from
 * the database on EVERY request, so a revoked admin loses access immediately.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';      // hash_secret()
require_once __DIR__ . '/discord.php';

// Session lifetime bounds.
const ADMIN_SESSION_IDLE     = 8 * 3600;    // sign out after 8h of inactivity
const ADMIN_SESSION_ABSOLUTE = 24 * 3600;   // hard cap 24h since login

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

/** Start the hardened admin session (idempotent). Call before reading $_SESSION. */
function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (defined('SESSIONS_DIR') && is_dir(SESSIONS_DIR)) {
        session_save_path(SESSIONS_DIR);
    }
    session_name('ATHENA_ADMIN');

    $secure = ((($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'));

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Enforce idle + absolute timeouts.
    if (isset($_SESSION['admin'])) {
        $now      = time();
        $loginAt  = (int) ($_SESSION['login_at'] ?? 0);
        $lastSeen = (int) ($_SESSION['last_seen'] ?? 0);
        if (($loginAt > 0 && $now - $loginAt > ADMIN_SESSION_ABSOLUTE)
            || ($lastSeen > 0 && $now - $lastSeen > ADMIN_SESSION_IDLE)) {
            admin_clear_login();   // keep the session alive (so a CSRF token can be issued for re-login)
        } else {
            $_SESSION['last_seen'] = $now;
        }
    }
}

/** Promote a successful login into the session (called by the route handlers). */
function admin_establish_session(array $admin): void
{
    session_regenerate_id(true);                 // anti session-fixation
    $_SESSION['admin']     = $admin;             // ['kind'=>'discord'|'local', ...]
    $_SESSION['login_at']  = time();
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf']      = bin2hex(random_bytes(32));
}

/** Drop the login but keep the session (used on timeout). */
function admin_clear_login(): void
{
    unset($_SESSION['admin'], $_SESSION['login_at'], $_SESSION['last_seen']);
    @session_regenerate_id(true);
}

/** Full logout: destroy the session and expire the cookie. */
function admin_logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?? '/',
            'domain'   => $p['domain'] ?? '',
            'secure'   => (bool) ($p['secure'] ?? false),
            'httponly' => (bool) ($p['httponly'] ?? true),
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    @session_destroy();
}

// ---------------------------------------------------------------------------
// Current admin + roles
// ---------------------------------------------------------------------------

/**
 * The logged-in admin with a FRESH role lookup, or null. Never trusts a
 * session-cached role: a revoked admin (or one whose Discord got banned) is
 * treated as logged out on their very next request.
 *
 * @return array{kind:string,id:string|int,role:string,name:string,discord_id?:string}|null
 */
function current_admin(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
        return null;
    }
    $a    = $_SESSION['admin'];
    $kind = (string) ($a['kind'] ?? '');

    if ($kind === 'discord') {
        $did = (string) ($a['discord_id'] ?? '');
        if ($did === '') {
            return null;
        }
        $u = get_discord_user($did);
        if ($u !== null && (int) $u['is_banned'] === 1) {
            return null;                       // banned -> no access
        }
        $role = admin_role_for_discord($did);
        if ($role === null) {
            return null;                       // role revoked -> no access
        }
        return ['kind' => 'discord', 'id' => $did, 'discord_id' => $did, 'role' => $role, 'name' => (string) ($a['name'] ?? $did)];
    }

    if ($kind === 'local') {
        $lid = (int) ($a['local_id'] ?? 0);
        $row = $lid > 0 ? get_admin_local_by_id($lid) : null;
        if ($row === null) {
            return null;
        }
        return ['kind' => 'local', 'id' => $lid, 'role' => (string) $row['role'], 'name' => (string) $row['username']];
    }

    return null;
}

/**
 * Permission set for a role.
 *   view           - load the dashboard and read all tables
 *   ban            - HWID + account bans/unbans
 *   skin           - skin moderation (reset/approve/reject)
 *   cfg            - edit server_config
 *   mods           - classify + upload + delete mods
 *   index          - rebuild the pack index
 *   account_delete - delete a Minecraft account (a user's "name slot")
 *   announce       - post a changelog/announcement note
 *   roles          - grant/revoke panel roles (kurucu only)
 *
 * @return array<int,string>
 */
function admin_permissions(string $role): array
{
    switch ($role) {
        case 'kurucu':
            return ['view', 'ban', 'skin', 'cfg', 'mods', 'index', 'account_delete', 'announce', 'roles'];
        case 'admin':
            return ['view', 'ban', 'skin', 'cfg', 'mods', 'index', 'account_delete', 'announce'];
        case 'moderator':
            return ['view', 'ban', 'skin'];
        default:
            return [];
    }
}

/** True when the current admin holds $perm. */
function admin_has_perm(string $perm): bool
{
    $a = current_admin();
    if ($a === null) {
        return false;
    }
    return in_array($perm, admin_permissions((string) $a['role']), true);
}

/**
 * Gate a mutating/admin route. On failure emits a generic 403 JSON and exits
 * (never leaks whether the user is logged in or which permission was missing).
 * Call AFTER admin_session_start() and csrf_check().
 */
function require_perm(string $perm): void
{
    if (admin_has_perm($perm)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Validate the CSRF token (form field or X-CSRF-Token header). 403+exit on failure. */
function csrf_check(): void
{
    $given = '';
    if (!empty($_POST['csrf'])) {
        $given = (string) $_POST['csrf'];
    } elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $given = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    $sess = (string) ($_SESSION['csrf'] ?? '');
    if ($sess === '' || $given === '' || !hash_equals($sess, $given)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'csrf']);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Local (break-glass) login helpers
// ---------------------------------------------------------------------------

/** A stable dummy hash so failed logins for unknown users still spend verify time. */
function admin_dummy_hash(): string
{
    static $h = null;
    if ($h === null) {
        $h = hash_secret('athena-dummy-not-a-real-password');
    }
    return $h;
}

/**
 * Verify break-glass credentials in constant time. Returns the admin_local row
 * on success, or null. Always runs password_verify (against a dummy hash when
 * the user is missing) to avoid username enumeration via timing.
 */
function admin_local_verify(string $username, string $password): ?array
{
    $row  = $username !== '' ? get_admin_local($username) : null;
    $hash = $row['pass_hash'] ?? admin_dummy_hash();
    $ok   = password_verify($password, $hash);
    if (!$ok || $row === null) {
        return null;
    }
    return $row;
}

// ---------------------------------------------------------------------------
// First-boot seeding
// ---------------------------------------------------------------------------

/**
 * Create the one-time break-glass admin if none exists. The plaintext password
 * is surfaced ONCE: written to data/breakglass_credentials.txt (0600, blocked
 * by Apache) and logged to the PHP error log. The DB stores only the hash.
 */
function ensure_breakglass_account(): void
{
    if (admin_local_count() > 0) {
        return;
    }
    $username = 'kurucu_' . bin2hex(random_bytes(3));
    $password = bin2hex(random_bytes(18));        // 36 hex chars
    create_admin_local($username, hash_secret($password), 'kurucu');

    $file = DATA_DIR . '/breakglass_credentials.txt';
    $body = "ATHENA Studios - panel break-glass admin (sifreli giris)\n"
          . "username: {$username}\n"
          . "password: {$password}\n"
          . "role:     kurucu\n"
          . "NOTE: Bu dosyayi ilk giristen sonra SILIN. Sifrenin tek acik kopyasi burada.\n";
    @file_put_contents($file, $body);
    @chmod($file, 0600);
    @error_log("[ATHENA] Break-glass admin olusturuldu: user={$username} (bkz: data/breakglass_credentials.txt)");
}
