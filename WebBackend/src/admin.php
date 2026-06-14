<?php
/**
 * Athena Studios Launcher - Admin dashboard + login renderer.
 *
 * Auth is now session-based (see admin_auth.php). render_admin_login() draws the
 * sign-in page served at "/"; render_admin() draws the dashboard for a logged-in
 * admin and hides controls the admin's role is not allowed to use. All mutating
 * forms/fetches carry a CSRF token (field for forms, X-CSRF-Token for fetch).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/builder.php';
require_once __DIR__ . '/admin_auth.php';

/** Small helper: htmlspecialchars shorthand. */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Load the active server config as an associative array. */
function admin_load_config(): array
{
    $decoded = read_json_file(FILES_DIR . '/server_config.json'); // BOM-safe
    return is_array($decoded) ? $decoded : [];
}

/** Shared dark-theme CSS for both the login page and the dashboard. */
function admin_styles(): string
{
    return <<<CSS
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0d1117; color: #c9d1d9; padding: 24px; }
        a { color: #58a6ff; }
        .header { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
        .header .spacer { flex: 1; }
        .whoami { font-size: 13px; color: #8b949e; text-align: right; }
        .whoami strong { color: #c9d1d9; }
        .logo { height: 48px; width: auto; border-radius: 8px; }
        h1 { color: #58a6ff; font-size: 26px; }
        h2 { color: #79c0ff; margin: 28px 0 12px; font-size: 20px; border-bottom: 1px solid #21262d; padding-bottom: 8px; }
        h3 { color: #d2a8ff; margin-bottom: 12px; font-size: 16px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: linear-gradient(135deg, #161b22, #1c2333); border: 1px solid #30363d; border-radius: 12px; padding: 20px; text-align: center; }
        .stat-card .number { font-size: 34px; font-weight: bold; color: #58a6ff; }
        .stat-card .label { font-size: 13px; color: #8b949e; margin-top: 4px; }

        .card-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; }
        .card { background-color: #161b22; padding: 20px; border-radius: 12px; border: 1px solid #30363d; }

        label { display: block; font-size: 13px; color: #8b949e; margin: 10px 0 4px; }
        input[type=text], input[type=number], input[type=password], select, textarea {
            width: 100%; background: #0d1117; border: 1px solid #30363d; color: #c9d1d9;
            border-radius: 8px; padding: 8px 10px; font-size: 13px;
        }
        textarea { min-height: 72px; resize: vertical; font-family: inherit; }
        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        button, .btn {
            background: #238636; color: #fff; border: none; border-radius: 8px;
            padding: 9px 16px; font-size: 13px; font-weight: 600; cursor: pointer; margin-top: 14px;
            text-decoration: none; display: inline-block;
        }
        button:hover, .btn:hover { background: #2ea043; }
        button.secondary { background: #1f6feb; }
        button.secondary:hover { background: #388bfd; }
        button.danger { background: #da3633; }
        button.danger:hover { background: #f85149; }
        button.small { padding: 4px 10px; margin-top: 0; font-size: 12px; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; background-color: #161b22; border-radius: 12px; overflow: hidden; font-size: 12px; }
        th, td { padding: 9px 11px; text-align: left; white-space: nowrap; }
        th { background-color: #21262d; color: #d2a8ff; font-weight: 600; position: sticky; top: 0; }
        tr:nth-child(even) { background-color: #1c2128; }
        tr:hover { background-color: #263040; }
        .table-scroll { overflow: auto; max-height: 620px; border-radius: 12px; border: 1px solid #30363d; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .badge-green { background: #23863633; color: #3fb950; }
        .badge-blue { background: #1f6feb33; color: #58a6ff; }
        .badge-purple { background: #8957e533; color: #d2a8ff; }
        .badge-role { background: #8957e533; color: #d2a8ff; text-transform: capitalize; }

        .mod-row { border-bottom: 1px solid #21262d; padding: 12px 0; }
        .mod-row:last-child { border-bottom: none; }
        .mod-name { font-weight: 600; color: #c9d1d9; font-size: 14px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .mod-controls { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; margin-top: 8px; }
        .mod-controls label { display: inline-flex; align-items: center; gap: 5px; margin: 0; color: #c9d1d9; }
        .muted { color: #8b949e; font-size: 13px; }
        .full { grid-column: 1 / -1; }
        #ban-result { margin-top: 10px; font-size: 13px; }
        .logout-form { display:inline; }
        .logout-form button { margin-top:0; background:#30363d; }
        .logout-form button:hover { background:#484f58; }
CSS;
}

/**
 * Render the sign-in page (served at "/" when logged out).
 */
function render_admin_login(string $csrf, string $error = ''): string
{
    $discordReady = function_exists('discord_admin_oauth_configured') && discord_admin_oauth_configured();
    $styles = admin_styles();
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(BRAND) ?> &mdash; Panel Girişi</title>
    <style>
        <?= $styles ?>
        body { display:flex; min-height:100vh; align-items:center; justify-content:center; }
        .login-card { width:100%; max-width:380px; background:#161b22; border:1px solid #30363d; border-radius:14px; padding:28px; }
        .login-card h1 { font-size:22px; text-align:center; margin-bottom:6px; }
        .login-sub { text-align:center; color:#8b949e; font-size:13px; margin-bottom:22px; }
        .btn-discord { width:100%; text-align:center; background:#5865F2; padding:11px; font-size:14px; }
        .btn-discord:hover { background:#6b78ff; }
        .sep { display:flex; align-items:center; gap:10px; color:#8b949e; font-size:12px; margin:18px 0; }
        .sep::before, .sep::after { content:''; flex:1; height:1px; background:#21262d; }
        .err { background:#da363322; border:1px solid #da3633; color:#f85149; padding:9px 12px; border-radius:8px; font-size:13px; margin-bottom:16px; }
        .login-card button[type=submit] { width:100%; }
    </style>
</head>
<body>
    <div class="login-card">
        <h1><?= h(BRAND) ?></h1>
        <div class="login-sub">Yönetim Paneli Girişi</div>
        <?php if ($error !== ''): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

        <?php if ($discordReady): ?>
        <a class="btn btn-discord" href="/api/admin/auth/discord/start">Discord ile Giriş</a>
        <div class="sep">veya</div>
        <?php endif; ?>

        <form method="post" action="/api/admin/auth/local">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label>Kullanıcı Adı</label>
            <input type="text" name="username" autocomplete="username" autofocus>
            <label>Parola</label>
            <input type="password" name="password" autocomplete="current-password">
            <button type="submit">Giriş Yap</button>
        </form>
    </div>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}

/**
 * Render the full admin dashboard for a logged-in admin. Controls are gated by
 * the admin's role (see admin_permissions()).
 */
function render_admin(array $admin, string $csrf): string
{
    $pdo   = db();
    $role  = (string) ($admin['role'] ?? '');
    $perms = admin_permissions($role);
    $can   = static fn(string $p): bool => in_array($p, $perms, true);

    // ---- Stats ----
    $totalPlays = (int) $pdo->query("SELECT COUNT(*) FROM metrics WHERE action='play'")->fetchColumn();
    $uniquePlayers = (int) $pdo->query(
        "SELECT COUNT(DISTINCT username) FROM metrics WHERE username IS NOT NULL AND username != '' AND username != 'Bilinmiyor'"
    )->fetchColumn();
    $todayPlays = (int) $pdo->query(
        "SELECT COUNT(*) FROM metrics WHERE action='play' AND date(timestamp) = date('now')"
    )->fetchColumn();
    $avgRam = (float) ($pdo->query("SELECT AVG(ram_total) FROM metrics WHERE ram_total > 0")->fetchColumn() ?: 0);

    $recent = $pdo->query(
        "SELECT timestamp, username, action, ip, os, cpu, gpu, ram_total, resolution, mc_version, loader_type, launcher_version, hwid
         FROM metrics ORDER BY id DESC LIMIT 100"
    )->fetchAll();

    $bans = $pdo->query("SELECT hwid, reason, date_banned FROM bans ORDER BY date_banned DESC LIMIT 200")->fetchAll();
    $discordUsers = function_exists('admin_all_discord_users') ? admin_all_discord_users() : [];
    $accounts     = function_exists('admin_all_accounts') ? admin_all_accounts() : [];
    $roles        = function_exists('admin_all_roles') ? admin_all_roles() : [];

    $versionInfo = read_json_file(VERSION_PATH);
    $packVersion = (is_array($versionInfo) && isset($versionInfo['Version'])) ? (int) $versionInfo['Version'] : 0;
    $changelog   = load_changelog();

    $config = admin_load_config();
    $cfg = static function (string $key, $default = '') use ($config) {
        return $config[$key] ?? $default;
    };

    $classification = function_exists('load_classification') ? load_classification() : [];
    $modsDir = FILES_DIR . '/mods';
    $modFiles = [];
    if (is_dir($modsDir)) {
        foreach (scandir($modsDir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            if (is_file($modsDir . '/' . $f) && preg_match('/\.jar$/i', $f)) {
                $modFiles[] = $f;
            }
        }
        sort($modFiles);
    }

    $logoTag = is_file(FILES_DIR . '/athena_logo.png')
        ? '<img src="/files/athena_logo.png" alt="' . h(BRAND) . '" class="logo">'
        : '';

    $autoConnect = (bool) $cfg('AutoConnect', true);
    $founderId   = (string) ATHENA_FOUNDER_DISCORD_ID;
    $styles      = admin_styles();

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(BRAND) ?> &mdash; Yönetim Paneli</title>
    <style><?= $styles ?></style>
</head>
<body>
    <div class="header">
        <?= $logoTag ?>
        <h1><?= h(BRAND) ?> &mdash; Yönetim Paneli</h1>
        <div class="spacer"></div>
        <div class="whoami">
            <strong><?= h($admin['name'] ?? '') ?></strong>
            <span class="badge badge-role"><?= h($role) ?></span>
            <form class="logout-form" method="post" action="/api/admin/auth/logout">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <button type="submit" class="small">Çıkış</button>
            </form>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="number"><?= $totalPlays ?></div><div class="label">Toplam Başlatma</div></div>
        <div class="stat-card"><div class="number"><?= $uniquePlayers ?></div><div class="label">Benzersiz Oyuncu</div></div>
        <div class="stat-card"><div class="number"><?= $todayPlays ?></div><div class="label">Bugünkü Başlatma</div></div>
        <div class="stat-card"><div class="number"><?= number_format($avgRam, 0) ?> MB</div><div class="label">Ortalama Sistem RAM</div></div>
        <div class="stat-card"><div class="number">v<?= $packVersion ?></div><div class="label">Paket Sürümü</div></div>
    </div>

    <div class="card-container">
        <?php if ($can('cfg')): ?>
        <!-- Aktif Yapılandırma -->
        <div class="card">
            <h3>Aktif Yapılandırma</h3>
            <form method="post" action="/api/admin/config">
                <?= csrf_field() ?>
                <label>Sunucu IP (ServerIp)</label>
                <input type="text" name="ServerIp" value="<?= h($cfg('ServerIp', DEFAULT_SERVER_IP)) ?>">
                <div class="row2">
                    <div>
                        <label>Minecraft Sürümü</label>
                        <input type="text" name="MinecraftVersion" value="<?= h($cfg('MinecraftVersion', '1.12.2')) ?>">
                    </div>
                    <div>
                        <label>Loader Türü</label>
                        <input type="text" name="LoaderType" value="<?= h($cfg('LoaderType', 'Forge')) ?>">
                    </div>
                </div>
                <label>Loader Sürümü</label>
                <input type="text" name="LoaderVersion" value="<?= h($cfg('LoaderVersion', '14.23.5.2860')) ?>">
                <div class="row2">
                    <div>
                        <label>Maksimum RAM (MB)</label>
                        <input type="number" name="MaxRamMb" value="<?= h((string)(int)$cfg('MaxRamMb', 4096)) ?>">
                    </div>
                    <div>
                        <label>Minimum RAM (MB)</label>
                        <input type="number" name="MinRamMb" value="<?= h((string)(int)$cfg('MinRamMb', 2048)) ?>">
                    </div>
                </div>
                <label>Java Yolu (JavaPath)</label>
                <input type="text" name="JavaPath" value="<?= h($cfg('JavaPath', '')) ?>">
                <label style="display:inline-flex;align-items:center;gap:6px;margin-top:12px;">
                    <input type="checkbox" name="AutoConnect" value="1" <?= $autoConnect ? 'checked' : '' ?>>
                    Otomatik Bağlan (AutoConnect)
                </label>
                <br>
                <button type="submit">Yapılandırmayı Kaydet</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($can('index')): ?>
        <!-- Index yeniden oluştur -->
        <div class="card">
            <h3>Index Yönetimi</h3>
            <p class="muted">
                <code>files/</code> klasörüne yeni dosya bıraktıktan veya mod sınıflandırmasını
                değiştirdikten sonra index'i yeniden oluşturun. SHA-256 doğrulaması ile
                korunan zorunlu dosyalar <code>index.json</code>, isteğe bağlı modlar ise
                <code>mods.json</code> içine yazılır.
            </p>
            <form method="post" action="/api/admin/rebuild">
                <?= csrf_field() ?>
                <button type="submit" class="secondary">Index'i Yeniden Oluştur</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($can('mods')): ?>
        <!-- Mod yükle -->
        <div class="card">
            <h3>Mod Yükle</h3>
            <p class="muted"><code>files/mods/</code> klasörüne tarayıcıdan <code>.jar</code> yükleyin (en fazla 25MB). Yükleme index'i otomatik yeniler.</p>
            <form method="post" action="/api/admin/mod/upload" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="file" name="mod" accept=".jar" required>
                <label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;">
                    <input type="checkbox" name="overwrite" value="1"> Aynı adlıysa üzerine yaz
                </label>
                <br>
                <button type="submit" class="secondary">Yükle</button>
            </form>
        </div>

        <!-- Mod sınıflandırma + sil -->
        <div class="card full">
            <h3>Mod Sınıflandırma</h3>
            <p class="muted">
                Her <code>.jar</code>'ı "zorunlu" (kilitli / anti-cheat) veya "isteğe bağlı"
                (oyuncu seçebilir) olarak işaretleyin. Sınıflandırılmamış modlar varsayılan olarak
                <strong>zorunlu</strong>dur. Kaydetmek index'i yeniden oluşturur. Bir modu kalıcı
                silmek için satırdaki <strong>Sil</strong>'i kullanın.
            </p>
            <?php if (empty($modFiles)): ?>
                <p class="muted" style="margin-top:12px;"><code>files/mods/</code> klasöründe .jar dosyası bulunamadı.</p>
            <?php else: ?>
            <form method="post" action="/api/admin/classify">
                <?= csrf_field() ?>
                <?php foreach ($modFiles as $file):
                    $info = $classification[$file] ?? [];
                    $type = (isset($info['type']) && $info['type'] === 'optional') ? 'optional' : 'required';
                    $name = isset($info['name']) ? (string) $info['name'] : '';
                    $desc = isset($info['description']) ? (string) $info['description'] : '';
                    $def  = !empty($info['default']);
                    $fk   = h($file);
                ?>
                <div class="mod-row">
                    <div class="mod-name">
                        <span><?= h($file) ?></span>
                        <button type="button" class="danger small" onclick="delMod(<?= h(json_encode($file)) ?>)">Sil</button>
                    </div>
                    <div class="mod-controls">
                        <label><input type="radio" name="type[<?= $fk ?>]" value="required" <?= $type === 'required' ? 'checked' : '' ?>> Zorunlu</label>
                        <label><input type="radio" name="type[<?= $fk ?>]" value="optional" <?= $type === 'optional' ? 'checked' : '' ?>> İsteğe bağlı</label>
                        <label><input type="checkbox" name="default[<?= $fk ?>]" value="1" <?= $def ? 'checked' : '' ?>> Varsayılan açık</label>
                    </div>
                    <div class="row2" style="margin-top:8px;">
                        <div>
                            <label>Görünen Ad</label>
                            <input type="text" name="name[<?= $fk ?>]" value="<?= h($name) ?>" placeholder="<?= h($file) ?>">
                        </div>
                        <div>
                            <label>Açıklama</label>
                            <input type="text" name="description[<?= $fk ?>]" value="<?= h($desc) ?>">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <button type="submit">Sınıflandırmayı Kaydet ve Index'i Yenile</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($can('announce')): ?>
        <!-- Duyuru gönder -->
        <div class="card full">
            <h3>Duyuru / Sürüm Notu Gönder</h3>
            <p class="muted">Launcher'da changelog/duyuru olarak görünür. Paket sürümünü değiştirmez (en fazla 500 karakter).</p>
            <textarea id="announce-note" placeholder="Örn: Yeni sezon başladı! Sunucu Cuma 20:00'de açılıyor."></textarea>
            <button class="secondary" onclick="postAnnounce()">Duyuruyu Yayınla</button>
            <div id="announce-result" class="muted" style="margin-top:8px;"></div>
        </div>
        <?php endif; ?>

        <!-- Sürüm notları / changelog (görüntüleme) -->
        <div class="card full">
            <h3>Sürüm Notları (Changelog) &mdash; Güncel: <span class="badge badge-green">v<?= $packVersion ?></span></h3>
            <?php if (empty($changelog)): ?>
                <p class="muted" style="margin-top:12px;">Henüz kayıt yok.</p>
            <?php else: ?>
            <div class="table-scroll" style="margin-top:12px;max-height:320px;">
                <table>
                    <tr><th>Sürüm</th><th>Tarih (UTC)</th><th>Değişiklik</th></tr>
                    <?php foreach ($changelog as $c): ?>
                    <tr>
                        <td><span class="badge <?= !empty($c['IsAnnouncement']) ? 'badge-blue' : 'badge-green' ?>"><?= !empty($c['IsAnnouncement']) ? 'Duyuru' : ('v' . (int) ($c['Version'] ?? 0)) ?></span></td>
                        <td><?= h($c['Timestamp'] ?? '') ?></td>
                        <td style="white-space:normal;"><?= h($c['Note'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($can('ban')): ?>
        <!-- Ban yönetimi -->
        <div class="card full">
            <h3>Ban Yönetimi (HWID)</h3>
            <div class="row2">
                <div>
                    <label>HWID</label>
                    <input type="text" id="ban-hwid" placeholder="Donanım kimliği">
                </div>
                <div>
                    <label>Sebep (opsiyonel)</label>
                    <input type="text" id="ban-reason" placeholder="Ban sebebi">
                </div>
            </div>
            <button class="danger" onclick="doBan('ban')">Banla</button>
            <button class="secondary" onclick="doBan('unban')">Ban Kaldır</button>
            <div id="ban-result"></div>

            <div class="table-scroll" style="margin-top:16px;max-height:280px;">
                <table>
                    <tr><th>HWID</th><th>Sebep</th><th>Tarih</th></tr>
                    <?php foreach ($bans as $b): ?>
                    <tr>
                        <td><?= h($b['hwid']) ?></td>
                        <td><?= h($b['reason'] ?? '') ?></td>
                        <td><?= h($b['date_banned']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bans)): ?>
                    <tr><td colspan="3" class="muted">Yasaklı HWID yok.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($can('roles')): ?>
        <!-- Rol yönetimi (sadece kurucu) -->
        <div class="card full">
            <h3>Rol Yönetimi</h3>
            <p class="muted">Discord ID'ye panel rolü ver/al. Roller: <strong>moderator</strong> (ban+skin), <strong>admin</strong> (rol hariç her şey), <strong>kurucu</strong> (her şey). Rolü olmayan kimse paneli göremez.</p>
            <div class="row2">
                <div>
                    <label>Discord ID</label>
                    <input type="text" id="role-discord-id" placeholder="örn: 637985724007841812">
                </div>
                <div>
                    <label>Rol</label>
                    <select id="role-select">
                        <option value="moderator">moderator</option>
                        <option value="admin">admin</option>
                        <option value="kurucu">kurucu</option>
                    </select>
                </div>
            </div>
            <button onclick="grantRole()">Rol Ver / Güncelle</button>
            <div id="role-result" class="muted" style="margin-top:8px;"></div>

            <div class="table-scroll" style="margin-top:16px;max-height:280px;">
                <table>
                    <tr><th>Discord</th><th>ID</th><th>Rol</th><th>Veren</th><th>İşlem</th></tr>
                    <?php foreach ($roles as $r): $rid = (string) $r['discord_id']; $isFounder = ($rid === $founderId); ?>
                    <tr>
                        <td><?= h($r['discord_username'] ?? '—') ?></td>
                        <td class="muted"><?= h($rid) ?></td>
                        <td><span class="badge badge-role"><?= h($r['role']) ?></span><?= $isFounder ? ' <span class="badge badge-green">kurucu (sabit)</span>' : '' ?></td>
                        <td class="muted"><?= h($r['granted_by'] ?? '') ?></td>
                        <td>
                            <?php if (!$isFounder): ?>
                            <button class="danger small" onclick="revokeRole(<?= h(json_encode($rid)) ?>)">Kaldır</button>
                            <?php else: ?>
                            <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($roles)): ?>
                    <tr><td colspan="5" class="muted">Henüz rol atanmamış.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Discord kullanıcıları -->
        <div class="card full">
            <h3>Kullanıcılar (Discord)</h3>
            <div class="table-scroll" style="margin-top:12px;max-height:320px;">
                <table>
                    <tr><th>Discord ID</th><th>Kullanıcı</th><th>Hesap</th><th>Durum</th><?php if ($can('ban')): ?><th>İşlem</th><?php endif; ?></tr>
                    <?php foreach ($discordUsers as $du): $dbn = (int) $du['is_banned'] === 1; ?>
                    <tr>
                        <td><?= h($du['discord_id']) ?></td>
                        <td><?= h($du['username'] ?? '') ?></td>
                        <td><?= (int) $du['account_count'] ?></td>
                        <td><?= $dbn ? '<span class="badge" style="background:#da363333;color:#f85149">Banlı</span>' : '<span class="badge badge-green">Aktif</span>' ?></td>
                        <?php if ($can('ban')): ?>
                        <td>
                            <?php if ($dbn): ?>
                            <button class="secondary small" onclick="banAcct('discord','<?= h($du['discord_id']) ?>','unban')">Ban Kaldır</button>
                            <?php else: ?>
                            <button class="danger small" onclick="banAcct('discord','<?= h($du['discord_id']) ?>','ban')">Banla</button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($discordUsers)): ?>
                    <tr><td colspan="5" class="muted">Henüz Discord kullanıcısı yok.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Minecraft hesapları + skin -->
        <div class="card full">
            <h3>Minecraft Hesapları &amp; Skinler</h3>
            <p class="muted">Kayıtlı hesaplar, sahibi ve skin. <?php if ($can('account_delete')): ?>İsim hakkını kalıcı silmek için <strong>Sil</strong>'i kullanın.<?php endif; ?></p>
            <div class="table-scroll" style="margin-top:12px;max-height:420px;">
                <table>
                    <tr><th>Skin</th><th>Kullanıcı Adı</th><th>UUID</th><th>Discord</th><th>Durum</th><?php if ($can('ban') || $can('skin') || $can('account_delete')): ?><th>İşlem</th><?php endif; ?></tr>
                    <?php foreach ($accounts as $a): $abn = (int) $a['is_banned'] === 1; $sk = (string) ($a['skin_hash'] ?? ''); ?>
                    <tr>
                        <td><?php if ($sk !== ''): ?><img src="/files/skins/<?= h($sk) ?>.png" alt="" style="width:24px;height:24px;image-rendering:pixelated;border-radius:4px" onerror="this.style.display='none'"><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <td><strong style="color:#3fb950"><?= h($a['username']) ?></strong></td>
                        <td class="muted"><?= h(substr((string) $a['uuid'], 0, 13)) ?>&hellip;</td>
                        <td><?= h($a['discord_username'] ?? $a['discord_id']) ?></td>
                        <td><?= $abn ? '<span class="badge" style="background:#da363333;color:#f85149">Banlı</span>' : '<span class="badge badge-green">Aktif</span>' ?></td>
                        <?php if ($can('ban') || $can('skin') || $can('account_delete')): ?>
                        <td>
                            <?php if ($can('ban')): ?>
                                <?php if ($abn): ?>
                                <button class="secondary small" onclick="banAcct('uuid','<?= h($a['uuid']) ?>','unban')">Ban Kaldır</button>
                                <?php else: ?>
                                <button class="danger small" onclick="banAcct('uuid','<?= h($a['uuid']) ?>','ban')">Banla</button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($can('skin') && $sk !== ''): ?>
                            <button class="secondary small" onclick="resetSkin(<?= (int) $a['id'] ?>)">Skini Sıfırla</button>
                            <?php endif; ?>
                            <?php if ($can('account_delete')): ?>
                            <button class="danger small" onclick="delAccount(<?= (int) $a['id'] ?>, <?= h(json_encode($a['username'])) ?>)">Sil</button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($accounts)): ?>
                    <tr><td colspan="6" class="muted">Henüz Minecraft hesabı yok.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <h2>Son Aktiviteler (Son 100 İşlem)</h2>
    <div class="table-scroll">
        <table>
            <tr>
                <th>Tarih</th><th>Kullanıcı</th><th>İşlem</th><th>IP</th><th>İşletim Sistemi</th>
                <th>CPU</th><th>GPU</th><th>RAM</th><th>Ekran</th><th>MC Sür.</th><th>Loader</th><th>Launcher</th><th>HWID</th>
            </tr>
            <?php foreach ($recent as $r): ?>
            <tr>
                <td><?= h($r['timestamp']) ?></td>
                <td><strong style="color:#3fb950;"><?= h($r['username'] ?: 'N/A') ?></strong></td>
                <td><span class="badge badge-blue"><?= h($r['action']) ?></span></td>
                <td><?= h($r['ip'] ?: 'N/A') ?></td>
                <td><?= h(mb_substr((string) $r['os'], 0, 40)) ?></td>
                <td><?= h(mb_substr((string) $r['cpu'], 0, 30)) ?></td>
                <td><?= h(mb_substr((string) $r['gpu'], 0, 30)) ?></td>
                <td><?= (int) $r['ram_total'] ?> MB</td>
                <td><?= h($r['resolution'] ?: 'N/A') ?></td>
                <td><span class="badge badge-purple"><?= h($r['mc_version'] ?: 'N/A') ?></span></td>
                <td><?= h($r['loader_type'] ?: 'Vanilla') ?></td>
                <td><span class="badge badge-green">v<?= h($r['launcher_version'] ?: '?') ?></span></td>
                <td><?= h(mb_substr((string) ($r['hwid'] ?? ''), 0, 24)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recent)): ?>
            <tr><td colspan="13" class="muted">Kayıt bulunamadı.</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <script>
        const CSRF = <?= json_encode($csrf) ?>;
        function api(path, body) {
            return fetch(path, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify(body || {})
            }).then(async r => {
                let d = {}; try { d = await r.json(); } catch (e) {}
                return { ok: r.ok, status: r.status, data: d };
            });
        }

        function doBan(action) {
            const hwid = document.getElementById('ban-hwid').value.trim();
            const reason = document.getElementById('ban-reason').value.trim();
            const out = document.getElementById('ban-result');
            if (!hwid) { out.textContent = 'HWID gerekli.'; out.style.color = '#f85149'; return; }
            api('/api/ban', { hwid, reason, action }).then(({ data }) => {
                out.style.color = (data.status === 'ok') ? '#3fb950' : '#f85149';
                out.textContent = (data.status === 'ok')
                    ? (action === 'ban' ? 'HWID yasaklandı.' : 'Ban kaldırıldı.') + ' Yenileyin.'
                    : ('Hata: ' + (data.error || 'bilinmeyen'));
            });
        }

        function banAcct(type, value, action) {
            const verb = action === 'ban' ? 'banla' : 'ban kaldır';
            if (!confirm(type.toUpperCase() + ' "' + value + '" -> ' + verb + '?')) return;
            api('/api/admin/ban_account', { type, value, action }).then(({ data }) => {
                if (data.status === 'ok') location.reload(); else alert('Hata: ' + (data.error || 'bilinmeyen'));
            });
        }

        function resetSkin(accountId) {
            if (!confirm('Bu hesabın skini sıfırlansın mı?')) return;
            api('/api/admin/skin', { action: 'reset', account_id: accountId }).then(({ data }) => {
                if (data.status === 'ok') location.reload(); else alert('Hata');
            });
        }

        function delMod(file) {
            if (!confirm('"' + file + '" kalıcı olarak silinsin mi?')) return;
            api('/api/admin/mod/delete', { file }).then(({ data }) => {
                if (data.status === 'ok') location.reload(); else alert('Hata: ' + (data.error || 'bilinmeyen'));
            });
        }

        function delAccount(id, name) {
            if (!confirm('"' + name + '" hesabı (isim hakkı) kalıcı silinsin mi? Bu geri alınamaz.')) return;
            api('/api/admin/account/delete', { account_id: id }).then(({ data }) => {
                if (data.status === 'ok') location.reload(); else alert('Hata: ' + (data.error || 'bilinmeyen'));
            });
        }

        function grantRole() {
            const id = document.getElementById('role-discord-id').value.trim();
            const role = document.getElementById('role-select').value;
            const out = document.getElementById('role-result');
            if (!id) { out.textContent = 'Discord ID gerekli.'; out.style.color = '#f85149'; return; }
            api('/api/admin/role/grant', { discord_id: id, role }).then(({ data }) => {
                if (data.status === 'ok') { location.reload(); }
                else { out.style.color = '#f85149'; out.textContent = 'Hata: ' + (data.error || 'bilinmeyen'); }
            });
        }

        function revokeRole(id) {
            if (!confirm(id + ' rolü kaldırılsın mı?')) return;
            api('/api/admin/role/revoke', { discord_id: id }).then(({ data }) => {
                if (data.status === 'ok') location.reload(); else alert('Hata: ' + (data.error || 'bilinmeyen'));
            });
        }

        function postAnnounce() {
            const note = document.getElementById('announce-note').value.trim();
            const out = document.getElementById('announce-result');
            if (!note) { out.textContent = 'Metin gerekli.'; out.style.color = '#f85149'; return; }
            api('/api/admin/announce', { note }).then(({ data }) => {
                if (data.status === 'ok') { out.style.color = '#3fb950'; out.textContent = 'Yayınlandı ✓'; document.getElementById('announce-note').value = ''; }
                else { out.style.color = '#f85149'; out.textContent = 'Hata: ' + (data.error || 'bilinmeyen'); }
            });
        }
    </script>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}
