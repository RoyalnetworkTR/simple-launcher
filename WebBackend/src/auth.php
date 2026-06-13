<?php
/**
 * Athena Studios Launcher - Session auth (HS256 JWT) + signed OAuth state.
 *
 * The launcher authenticates via Discord (see discord.php), the backend issues a
 * signed JWT, and every account/skin/join endpoint requires it as a Bearer token.
 * JWT is stateless (verified by signature) but tracked in `sessions` for revoke.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string
{
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode(strtr($data, '-_', '+/'), true);
    return $out === false ? '' : $out;
}

function jwt_secret(): ?string
{
    return env_get('JWT_SECRET');
}

function jwt_sign(array $claims): ?string
{
    $secret = jwt_secret();
    if ($secret === null) {
        return null;
    }
    $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
    $h = b64url_encode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = b64url_encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', "$h.$p", $secret, true);
    return "$h.$p." . b64url_encode($sig);
}

function jwt_verify(string $token): ?array
{
    $secret = jwt_secret();
    if ($secret === null || $token === '') {
        return null;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$h, $p, $s] = $parts;
    $expected = b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));
    if (!hash_equals($expected, $s)) {
        return null;
    }
    $claims = json_decode(b64url_decode($p), true);
    if (!is_array($claims)) {
        return null;
    }
    if (isset($claims['exp']) && time() >= (int) $claims['exp']) {
        return null;
    }
    return $claims;
}

/** Issue a launcher session JWT (30 days) and record it for revocation. */
function issue_jwt(string $discordId, ?string $ua = null, ?string $ip = null): array
{
    $now = time();
    $exp = $now + 30 * 24 * 3600;
    $jti = bin2hex(random_bytes(16));
    $token = jwt_sign([
        'sub' => $discordId,
        'jti' => $jti,
        'iat' => $now,
        'exp' => $exp,
        'typ' => 'launcher',
    ]);
    record_session($jti, $discordId, $exp, $ua, $ip);
    return ['token' => (string) $token, 'exp' => $exp, 'jti' => $jti];
}

/**
 * Hash a per-account secret. Prefers argon2id (Linux/prod) and transparently
 * falls back to bcrypt where argon2 is unavailable (e.g. some Windows builds).
 * password_verify() handles either algorithm.
 */
function hash_secret(string $secret): string
{
    if (defined('PASSWORD_ARGON2ID')) {
        $h = @password_hash($secret, PASSWORD_ARGON2ID);
        if (is_string($h) && $h !== '') {
            return $h;
        }
    }
    return password_hash($secret, PASSWORD_BCRYPT);
}

/** Extract the Bearer token from the request, or null. */
function bearer_token(): ?string
{
    $auth = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0) {
                $auth = (string) $v;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Require a valid, non-revoked, non-banned Bearer session. On failure responds
 * with JSON and exits. Returns ['discord_id'=>, 'jti'=>, 'claims'=>].
 */
function require_bearer(): array
{
    $tok    = bearer_token();
    $claims = $tok !== null ? jwt_verify($tok) : null;
    if ($claims === null) {
        send_json(['error' => 'unauthorized'], 401);
    }
    $jti = (string) ($claims['jti'] ?? '');
    if ($jti !== '' && is_session_revoked($jti)) {
        send_json(['error' => 'session_revoked'], 401);
    }
    $discordId = (string) ($claims['sub'] ?? '');
    $u = get_discord_user($discordId);
    if ($u !== null && (int) $u['is_banned'] === 1) {
        send_json(['error' => 'banned', 'reason' => (string) ($u['ban_reason'] ?? '')], 403);
    }
    return ['discord_id' => $discordId, 'jti' => $jti, 'claims' => $claims];
}

// ---------------------------------------------------------------------------
// Signed OAuth state (stateless: carries platform + loopback port + nonce).
// Bound with HMAC(JWT_SECRET) so a client can't tamper with the redirect target.
// ---------------------------------------------------------------------------

function sign_state(array $data): string
{
    $payload = b64url_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES));
    $sig = b64url_encode(hash_hmac('sha256', $payload, jwt_secret() ?? '', true));
    return "$payload.$sig";
}

function verify_state(string $state): ?array
{
    $parts = explode('.', $state);
    if (count($parts) !== 2) {
        return null;
    }
    [$payload, $sig] = $parts;
    $expected = b64url_encode(hash_hmac('sha256', $payload, jwt_secret() ?? '', true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $data = json_decode(b64url_decode($payload), true);
    if (!is_array($data)) {
        return null;
    }
    if (isset($data['e']) && time() >= (int) $data['e']) {
        return null;
    }
    return $data;
}
