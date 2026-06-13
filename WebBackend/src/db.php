<?php
/**
 * Athena Studios Launcher - Database layer (PDO SQLite)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Return a shared PDO connection to the SQLite database.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Sensible concurrency defaults for a small read-mostly metrics store.
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
    }
    return $pdo;
}

/**
 * Create the schema if it does not already exist.
 */
function init_db(): void
{
    $pdo = db();

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT,
            action TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            ip TEXT,
            username TEXT,
            os TEXT,
            os_arch TEXT,
            dotnet TEXT,
            ram_total INTEGER,
            ram_max INTEGER,
            ram_min INTEGER,
            resolution TEXT,
            mc_version TEXT,
            loader_type TEXT,
            loader_version TEXT,
            launcher_version TEXT,
            cpu TEXT,
            gpu TEXT,
            java_path TEXT,
            machine_name TEXT,
            cpu_cores INTEGER,
            gpu_ram TEXT,
            disk_total TEXT,
            disk_free TEXT,
            locale TEXT,
            motherboard TEXT,
            is_64bit_process TEXT,
            hwid TEXT
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS bans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hwid TEXT UNIQUE,
            reason TEXT,
            date_banned DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');

    // ----- Accounts / Discord auth / skins (added; idempotent migration) -----

    // Discord identities (one per Discord account).
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS discord_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            discord_id TEXT UNIQUE NOT NULL,
            username TEXT,
            avatar TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME,
            is_banned INTEGER NOT NULL DEFAULT 0,
            ban_reason TEXT
        )
    ');

    // Minecraft accounts owned by a Discord user (cap enforced in app layer).
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mc_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            discord_id TEXT NOT NULL,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            uuid TEXT NOT NULL UNIQUE,
            secret_hash TEXT NOT NULL,
            is_banned INTEGER NOT NULL DEFAULT 0,
            ban_reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mc_accounts_discord ON mc_accounts(discord_id)');

    // JWT sessions - JWT is stateless; this table only enables revocation.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            jti TEXT UNIQUE NOT NULL,
            discord_id TEXT NOT NULL,
            issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at INTEGER NOT NULL,
            revoked INTEGER NOT NULL DEFAULT 0,
            user_agent TEXT,
            ip TEXT
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_discord ON sessions(discord_id)');

    // Short-lived single-use join tokens (the proof AthenaCore mod verifies).
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS join_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            mc_account_id INTEGER NOT NULL,
            username TEXT NOT NULL,
            uuid TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            used_at INTEGER,
            used_ip TEXT
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_join_tokens_token ON join_tokens(token)');

    // Skins keyed by content hash; accounts point at one hash.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS skins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hash TEXT UNIQUE NOT NULL,
            model TEXT NOT NULL DEFAULT \'default\',
            width INTEGER,
            height INTEGER,
            uploaded_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            approved INTEGER NOT NULL DEFAULT 1
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS account_skins (
            mc_account_id INTEGER PRIMARY KEY,
            skin_hash TEXT NOT NULL
        )
    ');

    // Fixed-window rate limiter.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS rate_limits (
            rl_key TEXT PRIMARY KEY,
            window_start INTEGER NOT NULL,
            count INTEGER NOT NULL DEFAULT 0
        )
    ');
}

/**
 * All metric columns the client may populate (excluding auto fields id/timestamp).
 */
function metric_columns(): array
{
    return [
        'uuid', 'action', 'ip', 'username', 'os', 'os_arch', 'dotnet',
        'ram_total', 'ram_max', 'ram_min', 'resolution', 'mc_version',
        'loader_type', 'loader_version', 'launcher_version', 'cpu', 'gpu',
        'java_path', 'machine_name', 'cpu_cores', 'gpu_ram', 'disk_total',
        'disk_free', 'locale', 'motherboard', 'is_64bit_process', 'hwid',
    ];
}

/**
 * Insert a metric row. Only known columns from $data are used; everything
 * else is ignored. Integer columns are cast.
 */
function insert_metric(array $data): void
{
    $cols     = metric_columns();
    $intCols  = ['ram_total', 'ram_max', 'ram_min', 'cpu_cores'];
    $insertCols = [];
    $placeholders = [];
    $values = [];

    foreach ($cols as $col) {
        if (!array_key_exists($col, $data)) {
            continue;
        }
        $insertCols[]   = $col;
        $placeholders[] = ':' . $col;
        $value = $data[$col];
        if (in_array($col, $intCols, true)) {
            $value = (int) $value;
        }
        $values[':' . $col] = $value;
    }

    if (empty($insertCols)) {
        return;
    }

    $sql = 'INSERT INTO metrics (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = db()->prepare($sql);
    $stmt->execute($values);
}

/**
 * Return the ban reason for a HWID, or null if not banned.
 * (Treated as banned only when a row exists.)
 */
function is_banned(string $hwid): ?string
{
    if ($hwid === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT reason FROM bans WHERE hwid = :hwid LIMIT 1');
    $stmt->execute([':hwid' => $hwid]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    // A banned record exists; reason may be null/empty -> normalize to string.
    return (string) ($row['reason'] ?? '');
}

/**
 * Ban or unban a HWID. $action is "ban" or "unban".
 * Returns true on success.
 */
function set_ban(string $hwid, ?string $reason, string $action): bool
{
    if ($hwid === '') {
        return false;
    }
    $pdo = db();
    if ($action === 'unban') {
        $stmt = $pdo->prepare('DELETE FROM bans WHERE hwid = :hwid');
        $stmt->execute([':hwid' => $hwid]);
        return true;
    }

    // Default: ban (upsert).
    $stmt = $pdo->prepare('
        INSERT INTO bans (hwid, reason) VALUES (:hwid, :reason)
        ON CONFLICT(hwid) DO UPDATE SET reason = excluded.reason, date_banned = CURRENT_TIMESTAMP
    ');
    $stmt->execute([':hwid' => $hwid, ':reason' => $reason]);
    return true;
}

// ===========================================================================
// Discord users
// ===========================================================================

function get_discord_user(string $discordId): ?array
{
    if ($discordId === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM discord_users WHERE discord_id = :d LIMIT 1');
    $stmt->execute([':d' => $discordId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Insert or update a Discord identity from a profile {id, username, avatar}; returns the row. */
function upsert_discord_user(array $profile): array
{
    $pdo = db();
    $discordId = (string) ($profile['id'] ?? '');
    $username  = isset($profile['username']) ? (string) $profile['username'] : null;
    $avatar    = isset($profile['avatar']) ? (string) $profile['avatar'] : null;

    $stmt = $pdo->prepare('
        INSERT INTO discord_users (discord_id, username, avatar, last_login)
        VALUES (:d, :u, :a, CURRENT_TIMESTAMP)
        ON CONFLICT(discord_id) DO UPDATE SET
            username = excluded.username,
            avatar = excluded.avatar,
            last_login = CURRENT_TIMESTAMP
    ');
    $stmt->execute([':d' => $discordId, ':u' => $username, ':a' => $avatar]);

    $row = get_discord_user($discordId);
    return $row ?? ['discord_id' => $discordId, 'is_banned' => 0];
}

function set_discord_ban(string $discordId, ?string $reason, bool $banned): void
{
    $stmt = db()->prepare('UPDATE discord_users SET is_banned = :b, ban_reason = :r WHERE discord_id = :d');
    $stmt->execute([':b' => $banned ? 1 : 0, ':r' => $banned ? $reason : null, ':d' => $discordId]);
    if ($banned) {
        revoke_sessions_for_discord($discordId);
        revoke_join_tokens_for_discord($discordId);
    }
}

// ===========================================================================
// Minecraft accounts
// ===========================================================================

function list_accounts(string $discordId): array
{
    $stmt = db()->prepare('SELECT * FROM mc_accounts WHERE discord_id = :d ORDER BY id ASC');
    $stmt->execute([':d' => $discordId]);
    return $stmt->fetchAll();
}

function account_count(string $discordId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) AS c FROM mc_accounts WHERE discord_id = :d');
    $stmt->execute([':d' => $discordId]);
    return (int) ($stmt->fetch()['c'] ?? 0);
}

function get_account(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM mc_accounts WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function get_account_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT * FROM mc_accounts WHERE username = :u COLLATE NOCASE LIMIT 1');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function get_account_by_uuid(string $uuid): ?array
{
    $stmt = db()->prepare('SELECT * FROM mc_accounts WHERE uuid = :u LIMIT 1');
    $stmt->execute([':u' => $uuid]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Create an account. Returns new id, or throws PDOException on UNIQUE conflict. */
function create_account(string $discordId, string $username, string $uuid, string $secretHash): int
{
    $stmt = db()->prepare('
        INSERT INTO mc_accounts (discord_id, username, uuid, secret_hash)
        VALUES (:d, :u, :uuid, :s)
    ');
    $stmt->execute([':d' => $discordId, ':u' => $username, ':uuid' => $uuid, ':s' => $secretHash]);
    return (int) db()->lastInsertId();
}

function delete_account(int $id): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM account_skins WHERE mc_account_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM join_tokens WHERE mc_account_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM mc_accounts WHERE id = :id')->execute([':id' => $id]);
}

function update_account_secret(int $id, string $secretHash): void
{
    $stmt = db()->prepare('UPDATE mc_accounts SET secret_hash = :s WHERE id = :id');
    $stmt->execute([':s' => $secretHash, ':id' => $id]);
}

function set_account_ban(int $id, ?string $reason, bool $banned): void
{
    $stmt = db()->prepare('UPDATE mc_accounts SET is_banned = :b, ban_reason = :r WHERE id = :id');
    $stmt->execute([':b' => $banned ? 1 : 0, ':r' => $banned ? $reason : null, ':id' => $id]);
    if ($banned) {
        db()->prepare('UPDATE join_tokens SET used_at = :now WHERE mc_account_id = :id AND used_at IS NULL')
            ->execute([':now' => time(), ':id' => $id]);
    }
}

// ===========================================================================
// JWT sessions (revocation)
// ===========================================================================

function record_session(string $jti, string $discordId, int $expiresAt, ?string $ua, ?string $ip): void
{
    $stmt = db()->prepare('
        INSERT OR IGNORE INTO sessions (jti, discord_id, expires_at, user_agent, ip)
        VALUES (:j, :d, :e, :ua, :ip)
    ');
    $stmt->execute([':j' => $jti, ':d' => $discordId, ':e' => $expiresAt, ':ua' => $ua, ':ip' => $ip]);
}

function is_session_revoked(string $jti): bool
{
    if ($jti === '') {
        return false;
    }
    $stmt = db()->prepare('SELECT revoked FROM sessions WHERE jti = :j LIMIT 1');
    $stmt->execute([':j' => $jti]);
    $row = $stmt->fetch();
    // Unknown jti is treated as NOT revoked (stateless JWT still valid by signature).
    return $row !== false && (int) $row['revoked'] === 1;
}

function revoke_session(string $jti): void
{
    db()->prepare('UPDATE sessions SET revoked = 1 WHERE jti = :j')->execute([':j' => $jti]);
}

function revoke_sessions_for_discord(string $discordId): void
{
    db()->prepare('UPDATE sessions SET revoked = 1 WHERE discord_id = :d')->execute([':d' => $discordId]);
}

// ===========================================================================
// Join tokens (single-use, short TTL)
// ===========================================================================

/** Create a join token bound to an account. Returns the inserted row. */
function create_join_token(int $accountId, string $username, string $uuid, string $token, int $ttl): array
{
    $now = time();
    $exp = $now + max(30, $ttl);
    db()->prepare('
        INSERT INTO join_tokens (token, mc_account_id, username, uuid, created_at, expires_at)
        VALUES (:t, :a, :u, :uuid, :c, :e)
    ')->execute([':t' => $token, ':a' => $accountId, ':u' => $username, ':uuid' => $uuid, ':c' => $now, ':e' => $exp]);
    return ['token' => $token, 'username' => $username, 'uuid' => $uuid, 'expires_at' => $exp];
}

/**
 * Atomically consume a join token (single-use, unexpired). Returns the token row
 * on success, or null if missing/expired/already-used/username-mismatch.
 */
function consume_join_token(string $token, string $username, string $ip): ?array
{
    if ($token === '') {
        return null;
    }
    $pdo = db();
    $now = time();
    $upd = $pdo->prepare('
        UPDATE join_tokens SET used_at = :now, used_ip = :ip
        WHERE token = :t AND used_at IS NULL AND expires_at > :now2
    ');
    $upd->execute([':now' => $now, ':ip' => $ip, ':t' => $token, ':now2' => $now]);
    if ($upd->rowCount() !== 1) {
        return null;
    }
    $sel = $pdo->prepare('SELECT * FROM join_tokens WHERE token = :t LIMIT 1');
    $sel->execute([':t' => $token]);
    $row = $sel->fetch();
    if ($row === false) {
        return null;
    }
    if (strcasecmp((string) $row['username'], $username) !== 0) {
        return null;
    }
    return $row;
}

function revoke_join_tokens_for_discord(string $discordId): void
{
    db()->prepare('
        UPDATE join_tokens SET used_at = :now
        WHERE used_at IS NULL AND mc_account_id IN (SELECT id FROM mc_accounts WHERE discord_id = :d)
    ')->execute([':now' => time(), ':d' => $discordId]);
}

// ===========================================================================
// Skins
// ===========================================================================

function upsert_skin(string $hash, string $model, ?int $w, ?int $h, string $uploadedBy): void
{
    db()->prepare('
        INSERT INTO skins (hash, model, width, height, uploaded_by)
        VALUES (:h, :m, :w, :ht, :by)
        ON CONFLICT(hash) DO UPDATE SET model = excluded.model
    ')->execute([':h' => $hash, ':m' => $model, ':w' => $w, ':ht' => $h, ':by' => $uploadedBy]);
}

function set_account_skin(int $accountId, string $hash): void
{
    db()->prepare('
        INSERT INTO account_skins (mc_account_id, skin_hash) VALUES (:a, :h)
        ON CONFLICT(mc_account_id) DO UPDATE SET skin_hash = excluded.skin_hash
    ')->execute([':a' => $accountId, ':h' => $hash]);
}

function clear_account_skin(int $accountId): void
{
    db()->prepare('DELETE FROM account_skins WHERE mc_account_id = :a')->execute([':a' => $accountId]);
}

/** Skin row {hash, model, approved} for a player UUID, or null. */
function get_skin_for_uuid(string $uuid): ?array
{
    $stmt = db()->prepare('
        SELECT s.hash, s.model, s.approved
        FROM mc_accounts a
        JOIN account_skins x ON x.mc_account_id = a.id
        JOIN skins s ON s.hash = x.skin_hash
        WHERE a.uuid = :u LIMIT 1
    ');
    $stmt->execute([':u' => $uuid]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

// ===========================================================================
// Rate limiting (fixed window)
// ===========================================================================

/** Returns true if the action is allowed (under the limit) for this window. */
function rate_check(string $key, int $limit, int $windowSec): bool
{
    $pdo = db();
    $now = time();
    $stmt = $pdo->prepare('SELECT window_start, count FROM rate_limits WHERE rl_key = :k');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();

    if ($row === false) {
        try {
            $pdo->prepare('INSERT INTO rate_limits (rl_key, window_start, count) VALUES (:k, :w, 1)')
                ->execute([':k' => $key, ':w' => $now]);
        } catch (Throwable $e) {
            // Concurrent insert: treat as allowed.
        }
        return true;
    }

    if (($now - (int) $row['window_start']) >= $windowSec) {
        $pdo->prepare('UPDATE rate_limits SET window_start = :w, count = 1 WHERE rl_key = :k')
            ->execute([':w' => $now, ':k' => $key]);
        return true;
    }

    if ((int) $row['count'] >= $limit) {
        return false;
    }
    $pdo->prepare('UPDATE rate_limits SET count = count + 1 WHERE rl_key = :k')->execute([':k' => $key]);
    return true;
}

// ===========================================================================
// Admin dashboard queries
// ===========================================================================

function admin_all_discord_users(int $limit = 500): array
{
    $sql = 'SELECT d.*,
                   (SELECT COUNT(*) FROM mc_accounts a WHERE a.discord_id = d.discord_id) AS account_count
            FROM discord_users d ORDER BY d.id DESC LIMIT ' . (int) $limit;
    return db()->query($sql)->fetchAll();
}

function admin_all_accounts(int $limit = 1000): array
{
    $sql = 'SELECT a.*, d.username AS discord_username,
                   (SELECT skin_hash FROM account_skins x WHERE x.mc_account_id = a.id) AS skin_hash
            FROM mc_accounts a
            LEFT JOIN discord_users d ON d.discord_id = a.discord_id
            ORDER BY a.id DESC LIMIT ' . (int) $limit;
    return db()->query($sql)->fetchAll();
}

function set_skin_approved(string $hash, bool $approved): void
{
    db()->prepare('UPDATE skins SET approved = :a WHERE hash = :h')
        ->execute([':a' => $approved ? 1 : 0, ':h' => $hash]);
}
