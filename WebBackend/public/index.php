<?php
/**
 * Athena Studios Launcher - Front controller / router.
 *
 * All requests are routed here (see public/.htaccess for Apache and
 * nginx.conf.sample for nginx). Serves JSON APIs, raw client files, the ping
 * proxy, metric ingestion, ban checks and the admin dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/ping.php';
require_once __DIR__ . '/../src/builder.php';
require_once __DIR__ . '/../src/mrpack.php';
require_once __DIR__ . '/../src/admin.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/discord.php';
require_once __DIR__ . '/../src/skins.php';
require_once __DIR__ . '/../src/uuid.php';

init_db();

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function send_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Render a small branded HTML page (used by the OAuth callback) and exit. */
function send_html_message(string $title, string $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $b = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . "<title>$t</title>"
        . '<body style="margin:0;font-family:system-ui,Segoe UI,Roboto,sans-serif;background:#0E1116;color:#E6EDF3;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center">'
        . "<div style=\"max-width:420px;padding:24px\"><h2 style=\"color:#2F81F7;margin:0 0 10px\">$t</h2>"
        . "<p style=\"color:#8B98A9;line-height:1.5\">$b</p></div></body>";
    exit;
}

/** Decode a JSON request body into an array (empty array if absent/invalid). */
function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Serve a JSON file from FILES_DIR verbatim (no re-encode). */
function serve_json_file(string $absolute): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . filesize($absolute));
    readfile($absolute);
    exit;
}

function ext_to_mime(string $path): string
{
    static $map = [
        'json' => 'application/json',
        'jar'  => 'application/java-archive',
        'zip'  => 'application/zip',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'txt'  => 'text/plain; charset=utf-8',
        'cfg'  => 'text/plain; charset=utf-8',
        'toml' => 'text/plain; charset=utf-8',
        'properties' => 'text/plain; charset=utf-8',
        'js'   => 'application/javascript',
        'exe'  => 'application/octet-stream',
        'dll'  => 'application/octet-stream',
        'gz'   => 'application/gzip',
        'lzma' => 'application/octet-stream',
        'pack' => 'application/octet-stream',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $map[$ext] ?? 'application/octet-stream';
}

function require_admin(): void
{
    $pw = $_GET['password'] ?? ($_POST['password'] ?? '');
    if (!is_string($pw) || !hash_equals(ADMIN_PASSWORD, $pw)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access Denied.';
        exit;
    }
}

function is_trusted_proxy(string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    // Loopback'i TAM eşle ('::1' prefix'i '::100' gibi adresleri yanlışlıkla eşlemesin).
    if ($ip === '::1' || $ip === '127.0.0.1') {
        return true;
    }
    foreach (TRUSTED_PROXY_PREFIXES as $prefix) {
        if ($prefix === '::1') {
            continue; // yukarıda tam eşlendi
        }
        if (strncmp($ip, $prefix, strlen($prefix)) === 0) {
            return true;
        }
    }
    return false;
}

function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $fwd    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    // Only trust X-Forwarded-For when the immediate peer is a known reverse
    // proxy; otherwise any client could spoof the recorded IP.
    if ($fwd !== '' && is_trusted_proxy($remote)) {
        $parts = explode(',', $fwd);
        return trim($parts[0]); // original client (first hop)
    }
    return $remote;
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = parse_url($uri, PHP_URL_PATH);
$path   = $path === null ? '/' : rawurldecode($path);
$path   = '/' . ltrim($path, '/');
// Normalize trailing slash (except root).
if ($path !== '/' && substr($path, -1) === '/') {
    $path = rtrim($path, '/');
}

// ---- GET /api/server_config.json ----
if ($method === 'GET' && $path === '/api/server_config.json') {
    $file = FILES_DIR . '/server_config.json';
    if (!is_file($file) && is_file(SEED_DIR . '/server_config.json')) {
        @copy(SEED_DIR . '/server_config.json', $file);
    }
    if (is_file($file)) {
        serve_json_file($file);
    }
    send_json((object) []);
}

// ---- GET /api/index.json ----  (DYNAMIC: always reflects current files/)
if ($method === 'GET' && $path === '/api/index.json') {
    $file = FILES_DIR . '/index.json';
    try { refresh_pack(false); } catch (Throwable $e) { /* serve last-good artifact below */ }
    if (is_file($file)) {
        serve_json_file($file);
    }
    send_json([]);
}

// ---- GET /api/mods.json ----  (DYNAMIC)
if ($method === 'GET' && $path === '/api/mods.json') {
    $file = FILES_DIR . '/mods.json';
    try { refresh_pack(false); } catch (Throwable $e) { /* serve last-good artifact below */ }
    if (is_file($file)) {
        serve_json_file($file);
    }
    send_json(['Optional' => []]);
}

// ---- GET /api/version.json ----  (current pack version + latest note)
if ($method === 'GET' && $path === '/api/version.json') {
    try { refresh_pack(false); } catch (Throwable $e) {}
    if (is_file(VERSION_PATH)) {
        serve_json_file(VERSION_PATH);
    }
    send_json(['Version' => 0, 'UpdatedAt' => null, 'Note' => '']);
}

// ---- GET /api/changelog.json ----  (auto-generated release notes, newest first)
if ($method === 'GET' && $path === '/api/changelog.json') {
    try { refresh_pack(false); } catch (Throwable $e) {}
    if (is_file(CHANGELOG_PATH)) {
        serve_json_file(CHANGELOG_PATH);
    }
    send_json([]);
}

// ---- GET /api/modpack.mrpack ----  (telefon istemcileri için Modrinth modpack)
if ($method === 'GET' && $path === '/api/modpack.mrpack') {
    try { refresh_pack(false); } catch (Throwable $e) {}
    try {
        // Host-header injection önleme: indirme URL'leri SABİT kanonik adresten üretilir.
        $file = build_mrpack('https://' . DEFAULT_SERVER_IP);
        header('Content-Type: application/x-modrinth-modpack+zip');
        header('Content-Disposition: attachment; filename="Athena-Modpack.mrpack"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        @unlink($file); // per-request geçici dosya
        exit;
    } catch (Throwable $e) {
        send_json(['error' => 'mrpack build failed', 'detail' => $e->getMessage()], 500);
    }
}

// ---- GET /files/<relpath> ----
if ($method === 'GET' && preg_match('#^/files/(.+)$#', $path, $m)) {
    $rel = $m[1]; // already urldecoded as part of $path

    // SECURITY: reject traversal in any segment.
    $segments = explode('/', $rel);
    foreach ($segments as $seg) {
        if ($seg === '..' || $seg === '.') {
            send_json(['error' => 'forbidden'], 403);
        }
    }

    // Backend-managed meta files (yalnız KÖKtekiler) /api uçlarıyla sunulur; ham
    // sunulmaz (mod listesi sızıntısını önler). Alt klasördeki aynı adlı dosyalar korunur.
    if (strpos($rel, '/') === false && in_array($rel, META_FILES, true)) {
        send_json(['error' => 'not found'], 404);
    }

    $candidate = FILES_DIR . '/' . $rel;
    $real = realpath($candidate);
    $base = realpath(FILES_DIR);

    if ($real === false || $base === false || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0 || !is_file($real)) {
        send_json(['error' => 'not found'], 404);
    }

    header('Content-Type: ' . ext_to_mime($real));
    header('Content-Length: ' . filesize($real));
    readfile($real);
    exit;
}

// ---- GET /api/ping ----
if ($method === 'GET' && $path === '/api/ping') {
    // SSRF önleme: istemci girdisini YOK SAY; yalnız yapılandırılmış Athena sunucusunu pingle.
    $pingCfg = read_json_file(FILES_DIR . '/server_config.json');
    $ip = (is_array($pingCfg) && !empty($pingCfg['ServerIp'])) ? (string) $pingCfg['ServerIp'] : DEFAULT_SERVER_IP;
    try {
        $result = mc_ping($ip);
        send_json($result);
    } catch (Throwable $e) {
        send_json(['status' => 'offline', 'error' => $e->getMessage()], 503);
    }
}

// ---- POST /api/metric/<action> ----
if ($method === 'POST' && preg_match('#^/api/metric/([\w-]+)$#', $path, $m)) {
    $action = $m[1];
    $q = $_GET;

    $data = [
        'uuid'             => $q['uuid'] ?? 'unknown',
        'action'           => $action,
        'ip'               => client_ip(),
        'username'         => $q['username'] ?? 'Bilinmiyor',
        'os'               => $q['os'] ?? '',
        'os_arch'          => $q['os_arch'] ?? '',
        'dotnet'           => $q['dotnet'] ?? '',
        'ram_total'        => $q['ram_total'] ?? 0,
        'ram_max'          => $q['ram_max'] ?? 0,
        'ram_min'          => $q['ram_min'] ?? 0,
        'resolution'       => $q['resolution'] ?? '',
        'mc_version'       => $q['mc_version'] ?? '',
        'loader_type'      => $q['loader_type'] ?? '',
        'loader_version'   => $q['loader_version'] ?? '',
        'launcher_version' => $q['launcher_version'] ?? '',
        'cpu'              => $q['cpu'] ?? '',
        'gpu'              => $q['gpu'] ?? '',
        'java_path'        => $q['java_path'] ?? '',
        'machine_name'     => $q['machine_name'] ?? '',
        'cpu_cores'        => $q['cpu_cores'] ?? 0,
        'gpu_ram'          => $q['gpu_ram'] ?? '',
        'disk_total'       => $q['disk_total'] ?? '',
        'disk_free'        => $q['disk_free'] ?? '',
        'locale'           => $q['locale'] ?? '',
        'motherboard'      => $q['motherboard'] ?? '',
        'is_64bit_process' => $q['is_64bit_process'] ?? '',
        'hwid'             => $q['hwid'] ?? '',
    ];

    try {
        insert_metric($data);
    } catch (Throwable $e) {
        // Never break the launcher over a metrics failure.
    }
    send_json(['status' => 'ok']);
}

// ---- GET /api/check_ban ----
if ($method === 'GET' && $path === '/api/check_ban') {
    $hwid = isset($_GET['hwid']) ? (string) $_GET['hwid'] : '';
    $reason = is_banned($hwid);
    if ($reason === null) {
        send_json(['banned' => false, 'reason' => null]);
    }
    send_json(['banned' => true, 'reason' => $reason]);
}

// ---- POST /api/ban ----
if ($method === 'POST' && $path === '/api/ban') {
    // Password may arrive via query or JSON body.
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $body = is_array($body) ? $body : [];

    $pw = $_GET['password'] ?? ($body['password'] ?? '');
    if (!is_string($pw) || !hash_equals(ADMIN_PASSWORD, $pw)) {
        send_json(['status' => 'error', 'error' => 'unauthorized'], 403);
    }

    $hwid   = isset($body['hwid']) ? trim((string) $body['hwid']) : '';
    $reason = isset($body['reason']) ? (string) $body['reason'] : null;
    $action = isset($body['action']) && $body['action'] === 'unban' ? 'unban' : 'ban';

    if ($hwid === '') {
        send_json(['status' => 'error', 'error' => 'hwid required'], 400);
    }

    set_ban($hwid, $reason, $action);
    send_json(['status' => 'ok', 'action' => $action, 'hwid' => $hwid]);
}

// ---- GET /admin ----
if ($method === 'GET' && $path === '/admin') {
    require_admin();
    header('Content-Type: text/html; charset=utf-8');
    echo render_admin();
    exit;
}

// ---- POST /api/admin/rebuild ----
if ($method === 'POST' && $path === '/api/admin/rebuild') {
    require_admin();
    build_index();
    header('Location: /admin?password=' . rawurlencode((string) ($_GET['password'] ?? $_POST['password'] ?? '')));
    http_response_code(303);
    exit;
}

// ---- POST /api/admin/classify ----
if ($method === 'POST' && $path === '/api/admin/classify') {
    require_admin();

    $types    = isset($_POST['type']) && is_array($_POST['type']) ? $_POST['type'] : [];
    $names    = isset($_POST['name']) && is_array($_POST['name']) ? $_POST['name'] : [];
    $descs    = isset($_POST['description']) && is_array($_POST['description']) ? $_POST['description'] : [];
    $defaults = isset($_POST['default']) && is_array($_POST['default']) ? $_POST['default'] : [];

    $mods = [];
    foreach ($types as $file => $type) {
        $file = (string) $file;
        if ($type === 'optional') {
            $entry = ['type' => 'optional'];
            $name = trim((string) ($names[$file] ?? ''));
            $desc = trim((string) ($descs[$file] ?? ''));
            if ($name !== '') {
                $entry['name'] = $name;
            }
            if ($desc !== '') {
                $entry['description'] = $desc;
            }
            $entry['default'] = !empty($defaults[$file]);
            $mods[$file] = $entry;
        } else {
            $mods[$file] = ['type' => 'required'];
        }
    }

    write_json(FILES_DIR . '/mods_classification.json', ['mods' => $mods]);
    build_index();

    header('Location: /admin?password=' . rawurlencode((string) ($_GET['password'] ?? $_POST['password'] ?? '')));
    http_response_code(303);
    exit;
}

// ---- POST /api/admin/config ----
if ($method === 'POST' && $path === '/api/admin/config') {
    require_admin();

    // Preserve any existing keys, overwrite the editable ones.
    $existing = [];
    $cfgFile = FILES_DIR . '/server_config.json';
    if (is_file($cfgFile)) {
        $decoded = json_decode((string) file_get_contents($cfgFile), true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }

    $config = array_merge($existing, [
        'ServerIp'         => trim((string) ($_POST['ServerIp'] ?? DEFAULT_SERVER_IP)),
        'AutoConnect'      => !empty($_POST['AutoConnect']),
        'MinecraftVersion' => trim((string) ($_POST['MinecraftVersion'] ?? '1.12.2')),
        'LoaderType'       => trim((string) ($_POST['LoaderType'] ?? 'Forge')),
        'LoaderVersion'    => trim((string) ($_POST['LoaderVersion'] ?? '')),
        'MaxRamMb'         => (int) ($_POST['MaxRamMb'] ?? 4096),
        'MinRamMb'         => (int) ($_POST['MinRamMb'] ?? 2048),
        'JavaPath'         => trim((string) ($_POST['JavaPath'] ?? '')),
    ]);

    write_json($cfgFile, $config);

    header('Location: /admin?password=' . rawurlencode((string) ($_GET['password'] ?? $_POST['password'] ?? '')));
    http_response_code(303);
    exit;
}

// ---- POST /api/admin/ban_account ---- (ban/unban by discord id, username, or uuid)
if ($method === 'POST' && $path === '/api/admin/ban_account') {
    require_admin();
    $body = request_json();
    if (empty($body)) {
        $body = $_POST;
    }
    $type   = (string) ($body['type'] ?? '');
    $value  = trim((string) ($body['value'] ?? ''));
    $reason = isset($body['reason']) ? (string) $body['reason'] : null;
    $banned = (($body['action'] ?? 'ban') !== 'unban');
    if ($value === '') {
        send_json(['status' => 'error', 'error' => 'value required'], 400);
    }
    if ($type === 'discord') {
        set_discord_ban($value, $reason, $banned);
    } elseif ($type === 'username') {
        $a = get_account_by_username($value);
        if ($a === null) {
            send_json(['status' => 'error', 'error' => 'account not found'], 404);
        }
        set_account_ban((int) $a['id'], $reason, $banned);
    } elseif ($type === 'uuid') {
        $a = get_account_by_uuid(uuid_dashed($value));
        if ($a === null) {
            send_json(['status' => 'error', 'error' => 'account not found'], 404);
        }
        set_account_ban((int) $a['id'], $reason, $banned);
    } else {
        send_json(['status' => 'error', 'error' => 'bad type'], 400);
    }
    send_json(['status' => 'ok', 'type' => $type, 'value' => $value, 'banned' => $banned]);
}

// ---- POST /api/admin/skin ---- (moderation: reset an account's skin, or approve/reject by hash)
if ($method === 'POST' && $path === '/api/admin/skin') {
    require_admin();
    $body = request_json();
    if (empty($body)) {
        $body = $_POST;
    }
    $action = (string) ($body['action'] ?? '');
    if ($action === 'reset' && isset($body['account_id'])) {
        clear_account_skin((int) $body['account_id']);
        send_json(['status' => 'ok', 'action' => 'reset']);
    }
    if (($action === 'approve' || $action === 'reject') && isset($body['hash'])) {
        set_skin_approved((string) $body['hash'], $action === 'approve');
        send_json(['status' => 'ok', 'action' => $action]);
    }
    send_json(['status' => 'error', 'error' => 'bad request'], 400);
}

// ===========================================================================
// Discord auth
// ===========================================================================

// ---- GET /api/auth/discord/start ----
if ($method === 'GET' && $path === '/api/auth/discord/start') {
    if (!discord_oauth_configured() || jwt_secret() === null) {
        send_json(['error' => 'auth_not_configured'], 503);
    }
    if (!rate_check('auth:' . client_ip(), 30, 60)) {
        send_json(['error' => 'rate_limited'], 429);
    }
    $platform = (($_GET['platform'] ?? 'desktop') === 'android') ? 'android' : 'desktop';
    $port  = isset($_GET['port']) ? (int) $_GET['port'] : 0;
    $nonce = isset($_GET['nonce']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $_GET['nonce']) : '';
    if ($nonce === '' || strlen($nonce) > 128) {
        send_json(['error' => 'bad_nonce'], 400);
    }
    if ($platform === 'desktop' && ($port < 1 || $port > 65535)) {
        send_json(['error' => 'bad_port'], 400);
    }
    $state = sign_state(['p' => $platform, 'port' => $port, 'n' => $nonce, 'e' => time() + 600]);
    header('Location: ' . discord_authorize_url($state));
    http_response_code(302);
    exit;
}

// ---- GET /api/auth/discord/callback ----
if ($method === 'GET' && $path === '/api/auth/discord/callback') {
    $st   = verify_state((string) ($_GET['state'] ?? ''));
    $code = (string) ($_GET['code'] ?? '');
    if ($st === null || $code === '') {
        send_html_message('Giriş başarısız', 'Geçersiz veya süresi dolmuş istek. Lütfen launcher\'dan tekrar deneyin.', 400);
    }
    $tok    = discord_exchange_code($code);
    $access = is_array($tok) ? (string) ($tok['access_token'] ?? '') : '';
    if ($access === '') {
        send_html_message('Giriş başarısız', 'Discord doğrulaması tamamlanamadı.', 502);
    }
    $me = discord_fetch_me($access);
    if (!is_array($me) || empty($me['id'])) {
        send_html_message('Giriş başarısız', 'Discord profili alınamadı.', 502);
    }
    $user = upsert_discord_user($me);
    if ((int) ($user['is_banned'] ?? 0) === 1) {
        send_html_message('Erişim engellendi', 'Bu Discord hesabı yasaklı.', 403);
    }
    $issued = issue_jwt((string) $me['id'], $_SERVER['HTTP_USER_AGENT'] ?? null, client_ip());
    $tokenQ = rawurlencode((string) $issued['token']);
    $nonce  = rawurlencode((string) ($st['n'] ?? ''));
    if (($st['p'] ?? '') === 'android') {
        $redir = "athena://auth?token=$tokenQ&state=$nonce";
    } else {
        $port  = (int) ($st['port'] ?? 0);
        $redir = "http://127.0.0.1:$port/?token=$tokenQ&state=$nonce";
    }
    header('Location: ' . $redir);
    http_response_code(302);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Athena</title>'
        . '<body style="font-family:system-ui,Segoe UI,sans-serif;background:#0E1116;color:#E6EDF3;text-align:center;padding-top:80px">'
        . '<h2 style="color:#2F81F7">Giriş başarılı ✓</h2><p style="color:#8B98A9">Launcher\'a dönebilirsiniz; bu sekmeyi kapatabilirsiniz.</p></body>';
    exit;
}

// ---- POST /api/auth/refresh ----
if ($method === 'POST' && $path === '/api/auth/refresh') {
    $auth = require_bearer();
    revoke_session($auth['jti']);
    $issued = issue_jwt($auth['discord_id'], $_SERVER['HTTP_USER_AGENT'] ?? null, client_ip());
    send_json(['token' => $issued['token'], 'exp' => $issued['exp']]);
}

// ===========================================================================
// Accounts (Bearer JWT)
// ===========================================================================

// ---- GET /api/accounts ----
if ($method === 'GET' && $path === '/api/accounts') {
    $auth = require_bearer();
    $out = [];
    foreach (list_accounts($auth['discord_id']) as $a) {
        $skin = get_skin_for_uuid((string) $a['uuid']);
        $out[] = [
            'id'        => (int) $a['id'],
            'username'  => (string) $a['username'],
            'uuid'      => (string) $a['uuid'],
            'is_banned' => (int) $a['is_banned'] === 1,
            'skin'      => $skin ? ['hash' => $skin['hash'], 'model' => $skin['model']] : null,
        ];
    }
    send_json(['accounts' => $out, 'cap' => ACCOUNT_CAP]);
}

// ---- POST /api/accounts ----
if ($method === 'POST' && $path === '/api/accounts') {
    $auth = require_bearer();
    if (!rate_check('acct_create:' . $auth['discord_id'], 10, 3600)) {
        send_json(['error' => 'rate_limited'], 429);
    }
    $body = request_json();
    $username = trim((string) ($body['username'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $username)) {
        send_json(['error' => 'invalid_username', 'message' => '3-16 karakter; sadece harf, rakam, _'], 400);
    }
    if (account_count($auth['discord_id']) >= ACCOUNT_CAP) {
        send_json(['error' => 'cap_reached', 'cap' => ACCOUNT_CAP], 409);
    }
    if (get_account_by_username($username) !== null) {
        send_json(['error' => 'username_taken'], 409);
    }
    $uuid   = generate_offline_uuid($username);
    $secret = bin2hex(random_bytes(24));
    try {
        $id = create_account($auth['discord_id'], $username, $uuid, hash_secret($secret));
    } catch (Throwable $e) {
        send_json(['error' => 'username_taken'], 409);
    }
    send_json(['id' => $id, 'username' => $username, 'uuid' => $uuid, 'secret' => $secret], 201);
}

// ---- DELETE /api/accounts/{id} ----
if ($method === 'DELETE' && preg_match('#^/api/accounts/(\d+)$#', $path, $m)) {
    $auth = require_bearer();
    $acc = get_account((int) $m[1]);
    if ($acc === null || (string) $acc['discord_id'] !== $auth['discord_id']) {
        send_json(['error' => 'not_found'], 404);
    }
    delete_account((int) $m[1]);
    send_json(['status' => 'ok']);
}

// ---- POST /api/accounts/{id}/regenerate-secret ----
if ($method === 'POST' && preg_match('#^/api/accounts/(\d+)/regenerate-secret$#', $path, $m)) {
    $auth = require_bearer();
    $acc = get_account((int) $m[1]);
    if ($acc === null || (string) $acc['discord_id'] !== $auth['discord_id']) {
        send_json(['error' => 'not_found'], 404);
    }
    $secret = bin2hex(random_bytes(24));
    update_account_secret((int) $m[1], hash_secret($secret));
    send_json(['id' => (int) $m[1], 'secret' => $secret]);
}

// ===========================================================================
// Join verification (launcher <-> AthenaCore mod)
// ===========================================================================

// ---- POST /api/join/prepare (Bearer; launcher gets a single-use token) ----
if ($method === 'POST' && $path === '/api/join/prepare') {
    $auth  = require_bearer();
    $body  = request_json();
    $accId = (int) ($body['account_id'] ?? 0);
    $acc   = get_account($accId);
    if ($acc === null || (string) $acc['discord_id'] !== $auth['discord_id']) {
        send_json(['error' => 'not_found'], 404);
    }
    if ((int) $acc['is_banned'] === 1) {
        send_json(['error' => 'banned', 'reason' => (string) ($acc['ban_reason'] ?? '')], 403);
    }
    if (!rate_check('join_prep:' . $accId, 20, 60)) {
        send_json(['error' => 'rate_limited'], 429);
    }
    $token = bin2hex(random_bytes(32));
    $row   = create_join_token($accId, (string) $acc['username'], (string) $acc['uuid'], $token, 120);
    send_json([
        'join_token' => $row['token'],
        'username'   => $row['username'],
        'uuid'       => $row['uuid'],
        'expires_at' => $row['expires_at'],
    ]);
}

// ---- POST /api/join/verify (AthenaCore server mod; X-Athena-Server-Key) ----
if ($method === 'POST' && $path === '/api/join/verify') {
    $serverKey = env_get('ATHENA_SERVER_KEY');
    $given     = (string) ($_SERVER['HTTP_X_ATHENA_SERVER_KEY'] ?? '');
    if ($serverKey === null || !hash_equals($serverKey, $given)) {
        send_json(['ok' => false, 'reason' => 'forbidden'], 403);
    }
    $body     = request_json();
    $token    = (string) ($body['join_token'] ?? '');
    $username = (string) ($body['username'] ?? '');
    $row = consume_join_token($token, $username, client_ip());
    if ($row === null) {
        send_json(['ok' => false, 'reason' => 'invalid_or_expired']);
    }
    $acc = get_account((int) $row['mc_account_id']);
    if ($acc === null) {
        send_json(['ok' => false, 'reason' => 'no_account']);
    }
    if ((int) $acc['is_banned'] === 1) {
        send_json(['ok' => false, 'reason' => 'account_banned']);
    }
    $du = get_discord_user((string) $acc['discord_id']);
    if ($du !== null && (int) $du['is_banned'] === 1) {
        send_json(['ok' => false, 'reason' => 'discord_banned']);
    }
    send_json(['ok' => true, 'uuid' => (string) $acc['uuid'], 'username' => (string) $acc['username']]);
}

// ===========================================================================
// Skins
// ===========================================================================

// ---- POST /api/skin/upload (Bearer; multipart skin + account_id + model) ----
if ($method === 'POST' && $path === '/api/skin/upload') {
    $auth = require_bearer();
    if (!rate_check('skin:' . $auth['discord_id'], 20, 600)) {
        send_json(['error' => 'rate_limited'], 429);
    }
    $accId = (int) ($_POST['account_id'] ?? 0);
    $model = ((string) ($_POST['model'] ?? 'default') === 'slim') ? 'slim' : 'default';
    $acc   = get_account($accId);
    if ($acc === null || (string) $acc['discord_id'] !== $auth['discord_id']) {
        send_json(['error' => 'not_found'], 404);
    }
    if (!isset($_FILES['skin']) || ($_FILES['skin']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        send_json(['error' => 'no_file'], 400);
    }
    $stored = validate_and_store_skin((string) $_FILES['skin']['tmp_name'], $model);
    if ($stored === null) {
        send_json(['error' => 'invalid_skin', 'message' => 'PNG, 64x64 veya 64x32, en fazla 256KB olmalı.'], 400);
    }
    upsert_skin($stored['hash'], $stored['model'], $stored['width'], $stored['height'], $auth['discord_id']);
    set_account_skin($accId, $stored['hash']);
    send_json(['status' => 'ok', 'hash' => $stored['hash'], 'model' => $stored['model']]);
}

// ---- POST /api/skin/delete (Bearer) ----
if ($method === 'POST' && $path === '/api/skin/delete') {
    $auth  = require_bearer();
    $body  = request_json();
    $accId = (int) ($body['account_id'] ?? 0);
    $acc   = get_account($accId);
    if ($acc === null || (string) $acc['discord_id'] !== $auth['discord_id']) {
        send_json(['error' => 'not_found'], 404);
    }
    clear_account_skin($accId);
    send_json(['status' => 'ok']);
}

// ---- GET /api/skin/{uuid}.png ----
if ($method === 'GET' && preg_match('#^/api/skin/([0-9a-fA-F-]{32,36})\.png$#', $path, $m)) {
    $uuid = uuid_dashed($m[1]);
    $skin = get_skin_for_uuid($uuid);
    if ($skin === null || (int) ($skin['approved'] ?? 1) !== 1) {
        send_json(['error' => 'not_found'], 404); // mod falls back to vanilla default
    }
    $file = skin_file_path((string) $skin['hash']);
    if (!is_file($file)) {
        send_json(['error' => 'not_found'], 404);
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: public, max-age=60');
    readfile($file);
    exit;
}

// ---- GET /api/skin/{uuid}.json ----
if ($method === 'GET' && preg_match('#^/api/skin/([0-9a-fA-F-]{32,36})\.json$#', $path, $m)) {
    $uuid = uuid_dashed($m[1]);
    $skin = get_skin_for_uuid($uuid);
    if ($skin === null || (int) ($skin['approved'] ?? 1) !== 1) {
        send_json(['custom' => false, 'model' => 'default', 'hash' => null, 'url' => null]);
    }
    send_json([
        'custom' => true,
        'model'  => (string) $skin['model'],
        'hash'   => (string) $skin['hash'],
        'url'    => 'https://' . DEFAULT_SERVER_IP . '/api/skin/' . $uuid . '.png',
    ]);
}

// ---- Fallback ----
send_json(['error' => 'not found'], 404);
