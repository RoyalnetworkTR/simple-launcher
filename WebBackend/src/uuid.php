<?php
/**
 * Athena Studios Launcher - Deterministic offline-player UUID.
 *
 * Produces a UUID that is byte-for-byte identical to:
 *   - the C# launcher (Character.GenerateUuidFromUsername in Character.cs):
 *     MD5 of ASCII "OfflinePlayer:" + username, version 3, RFC 4122 variant;
 *   - the Minecraft server in offline mode:
 *     UUID.nameUUIDFromBytes(("OfflinePlayer:"+name).getBytes(UTF_8)).
 *
 * Usernames are constrained to ASCII (^[A-Za-z0-9_]{3,16}$), so ASCII == UTF-8
 * and all three implementations agree. Keeping the same UUID is what lets the
 * server stay in offline mode without migrating existing world/inventory data.
 */

declare(strict_types=1);

/** Dashed lowercase UUID, e.g. "a1b2c3d4-e5f6-3a7b-8c9d-0e1f2a3b4c5d". */
function generate_offline_uuid(string $username): string
{
    if ($username === '') {
        $username = 'Player';
    }
    $data = 'OfflinePlayer:' . $username;
    $hash = md5($data, true);                              // raw 16 bytes (MD5)
    $hash[6] = chr((ord($hash[6]) & 0x0F) | 0x30);         // version 3
    $hash[8] = chr((ord($hash[8]) & 0x3F) | 0x80);         // RFC 4122 variant
    $h = bin2hex($hash);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($h, 0, 8),
        substr($h, 8, 4),
        substr($h, 12, 4),
        substr($h, 16, 4),
        substr($h, 20, 12)
    );
}

/** Strip dashes -> 32 hex chars (Mojang "undashed" form). */
function uuid_undashed(string $uuid): string
{
    return str_replace('-', '', $uuid);
}

/** Add dashes to a 32-hex string; returns input unchanged if not 32 hex. */
function uuid_dashed(string $uuid): string
{
    $u = str_replace('-', '', $uuid);
    if (strlen($u) !== 32 || !ctype_xdigit($u)) {
        return $uuid;
    }
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($u, 0, 8),
        substr($u, 8, 4),
        substr($u, 12, 4),
        substr($u, 16, 4),
        substr($u, 20, 12)
    );
}
