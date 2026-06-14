<?php
/**
 * Athena Studios Launcher - Discord OAuth2 (confidential client).
 *
 * The backend holds the client secret and performs the code exchange server-side,
 * so the launcher never sees it. Standard authorization-code flow over HTTPS:
 * the redirect_uri points at our own /api/auth/discord/callback, so the code only
 * ever travels Discord -> our backend (never through the client).
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';

/** True only when client id, secret and redirect URI are all configured. */
function discord_oauth_configured(): bool
{
    return env_get('DISCORD_CLIENT_ID') !== null
        && env_get('DISCORD_CLIENT_SECRET') !== null
        && env_get('DISCORD_REDIRECT_URI') !== null;
}

function discord_authorize_url(string $state): string
{
    $params = http_build_query([
        'response_type' => 'code',
        'client_id'     => (string) env_get('DISCORD_CLIENT_ID'),
        'scope'         => 'identify',
        'redirect_uri'  => (string) env_get('DISCORD_REDIRECT_URI'),
        'state'         => $state,
        'prompt'        => 'consent',
    ]);
    return 'https://discord.com/oauth2/authorize?' . $params;
}

/** Exchange an authorization code for tokens; returns the decoded JSON or null. */
function discord_exchange_code(string $code): ?array
{
    $body = http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => (string) env_get('DISCORD_REDIRECT_URI'),
        'client_id'     => (string) env_get('DISCORD_CLIENT_ID'),
        'client_secret' => (string) env_get('DISCORD_CLIENT_SECRET'),
    ]);
    $res = athena_http_post_form('https://discord.com/api/oauth2/token', $body);
    if ($res === null) {
        return null;
    }
    $data = json_decode($res, true);
    return is_array($data) ? $data : null;
}

// ---------------------------------------------------------------------------
// Admin panel OAuth - a SECOND, dedicated redirect_uri so the panel's web
// session flow is fully separate from the launcher's loopback flow. Discord
// requires redirect_uri to match exactly between authorize and token exchange,
// so the admin flow carries its own URI end-to-end. The launcher functions
// above are untouched.
// ---------------------------------------------------------------------------

function discord_admin_redirect_uri(): ?string
{
    return env_get('DISCORD_ADMIN_REDIRECT_URI');
}

/** True only when client id, secret AND the admin redirect URI are configured. */
function discord_admin_oauth_configured(): bool
{
    return env_get('DISCORD_CLIENT_ID') !== null
        && env_get('DISCORD_CLIENT_SECRET') !== null
        && discord_admin_redirect_uri() !== null;
}

function discord_admin_authorize_url(string $state): string
{
    $params = http_build_query([
        'response_type' => 'code',
        'client_id'     => (string) env_get('DISCORD_CLIENT_ID'),
        'scope'         => 'identify',
        'redirect_uri'  => (string) discord_admin_redirect_uri(),
        'state'         => $state,
        'prompt'        => 'consent',
    ]);
    return 'https://discord.com/oauth2/authorize?' . $params;
}

/** Exchange an authorization code (admin redirect_uri) for tokens; decoded JSON or null. */
function discord_admin_exchange_code(string $code): ?array
{
    $body = http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => (string) discord_admin_redirect_uri(),
        'client_id'     => (string) env_get('DISCORD_CLIENT_ID'),
        'client_secret' => (string) env_get('DISCORD_CLIENT_SECRET'),
    ]);
    $res = athena_http_post_form('https://discord.com/api/oauth2/token', $body);
    if ($res === null) {
        return null;
    }
    $data = json_decode($res, true);
    return is_array($data) ? $data : null;
}

/** Fetch the authenticated user's profile (id, username, avatar, ...). */
function discord_fetch_me(string $accessToken): ?array
{
    $res = athena_http_get_bearer('https://discord.com/api/users/@me', $accessToken);
    if ($res === null) {
        return null;
    }
    $data = json_decode($res, true);
    return is_array($data) ? $data : null;
}

// ---------------------------------------------------------------------------
// Tiny cURL helpers (php-curl is installed by deploy.sh).
// ---------------------------------------------------------------------------

function athena_http_post_form(string $url, string $body): ?string
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($res === false || $code < 200 || $code >= 300) {
        return null;
    }
    return (string) $res;
}

function athena_http_get_bearer(string $url, string $bearer): ?string
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $bearer, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($res === false || $code < 200 || $code >= 300) {
        return null;
    }
    return (string) $res;
}
