<?php
/**
 * Athena Studios Launcher - Admin dashboard renderer.
 *
 * Returns a full self-contained HTML page (dark theme). All mutating actions
 * are plain HTML forms that POST to /api/admin/* carrying the password from
 * the query string, plus a small JS fetch for the ban management form.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/builder.php';

/**
 * Small helper: htmlspecialchars shorthand.
 */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Load the active server config as an associative array.
 */
function admin_load_config(): array
{
    $decoded = read_json_file(FILES_DIR . '/server_config.json'); // BOM-safe
    return is_array($decoded) ? $decoded : [];
}

/**
 * Render the full admin dashboard HTML.
 */
function render_admin(): string
{
    $pdo = db();
    $pw  = isset($_GET['password']) ? (string) $_GET['password'] : '';
    $pwQ = rawurlencode($pw);

    // ---- Stats ----
    $totalPlays = (int) $pdo->query("SELECT COUNT(*) FROM metrics WHERE action='play'")->fetchColumn();
    $uniquePlayers = (int) $pdo->query(
        "SELECT COUNT(DISTINCT username) FROM metrics WHERE username IS NOT NULL AND username != '' AND username != 'Bilinmiyor'"
    )->fetchColumn();
    $todayPlays = (int) $pdo->query(
        "SELECT COUNT(*) FROM metrics WHERE action='play' AND date(timestamp) = date('now')"
    )->fetchColumn();
    $avgRam = (float) ($pdo->query("SELECT AVG(ram_total) FROM metrics WHERE ram_total > 0")->fetchColumn() ?: 0);

    // ---- Recent activity (last 100) ----
    $recent = $pdo->query(
        "SELECT timestamp, username, action, ip, os, cpu, gpu, ram_total, resolution, mc_version, loader_type, launcher_version, hwid
         FROM metrics ORDER BY id DESC LIMIT 100"
    )->fetchAll();

    // ---- Bans ----
    $bans = $pdo->query("SELECT hwid, reason, date_banned FROM bans ORDER BY date_banned DESC LIMIT 200")->fetchAll();

    // ---- Discord users + Minecraft accounts (auth/skin system) ----
    $discordUsers = function_exists('admin_all_discord_users') ? admin_all_discord_users() : [];
    $accounts     = function_exists('admin_all_accounts') ? admin_all_accounts() : [];

    // ---- Pack version + auto-generated changelog ----
    $versionInfo = read_json_file(VERSION_PATH);
    $packVersion = (is_array($versionInfo) && isset($versionInfo['Version'])) ? (int) $versionInfo['Version'] : 0;
    $changelog   = load_changelog();

    // ---- Active config ----
    $config = admin_load_config();
    $cfg = static function (string $key, $default = '') use ($config) {
        return $config[$key] ?? $default;
    };

    // ---- Mods present on disk + classification ----
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

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(BRAND) ?> &mdash; Yönetim Paneli</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0d1117; color: #c9d1d9; padding: 24px; }
        a { color: #58a6ff; }
        .header { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
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
        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        button, .btn {
            background: #238636; color: #fff; border: none; border-radius: 8px;
            padding: 9px 16px; font-size: 13px; font-weight: 600; cursor: pointer; margin-top: 14px;
        }
        button:hover, .btn:hover { background: #2ea043; }
        button.secondary { background: #1f6feb; }
        button.secondary:hover { background: #388bfd; }
        button.danger { background: #da3633; }
        button.danger:hover { background: #f85149; }

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

        .mod-row { border-bottom: 1px solid #21262d; padding: 12px 0; }
        .mod-row:last-child { border-bottom: none; }
        .mod-name { font-weight: 600; color: #c9d1d9; font-size: 14px; }
        .mod-controls { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; margin-top: 8px; }
        .mod-controls label { display: inline-flex; align-items: center; gap: 5px; margin: 0; color: #c9d1d9; }
        .muted { color: #8b949e; font-size: 13px; }
        .full { grid-column: 1 / -1; }
        #ban-result { margin-top: 10px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="header">
        <?= $logoTag ?>
        <h1><?= h(BRAND) ?> &mdash; Yönetim Paneli</h1>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="number"><?= $totalPlays ?></div><div class="label">Toplam Başlatma</div></div>
        <div class="stat-card"><div class="number"><?= $uniquePlayers ?></div><div class="label">Benzersiz Oyuncu</div></div>
        <div class="stat-card"><div class="number"><?= $todayPlays ?></div><div class="label">Bugünkü Başlatma</div></div>
        <div class="stat-card"><div class="number"><?= number_format($avgRam, 0) ?> MB</div><div class="label">Ortalama Sistem RAM</div></div>
        <div class="stat-card"><div class="number">v<?= $packVersion ?></div><div class="label">Paket Sürümü</div></div>
    </div>

    <div class="card-container">
        <!-- Aktif Yapılandırma -->
        <div class="card">
            <h3>Aktif Yapılandırma</h3>
            <form method="post" action="/api/admin/config?password=<?= h($pwQ) ?>">
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

        <!-- Index yeniden oluştur -->
        <div class="card">
            <h3>Index Yönetimi</h3>
            <p class="muted">
                <code>files/</code> klasörüne yeni dosya bıraktıktan veya mod sınıflandırmasını
                değiştirdikten sonra index'i yeniden oluşturun. SHA-256 doğrulaması ile
                korunan zorunlu dosyalar <code>index.json</code>, isteğe bağlı modlar ise
                <code>mods.json</code> içine yazılır.
            </p>
            <form method="post" action="/api/admin/rebuild?password=<?= h($pwQ) ?>">
                <button type="submit" class="secondary">Index'i Yeniden Oluştur</button>
            </form>
        </div>

        <!-- Mod sınıflandırma -->
        <div class="card full">
            <h3>Mod Sınıflandırma</h3>
            <p class="muted">
                <code>files/mods/</code> içindeki her <code>.jar</code> dosyasını "zorunlu"
                (kilitli / anti-cheat) veya "isteğe bağlı" (oyuncu seçebilir) olarak işaretleyin.
                Sınıflandırılmamış modlar varsayılan olarak <strong>zorunlu</strong> kabul edilir.
                Kaydetmek aynı zamanda index'i yeniden oluşturur.
            </p>
            <?php if (empty($modFiles)): ?>
                <p class="muted" style="margin-top:12px;"><code>files/mods/</code> klasöründe .jar dosyası bulunamadı.</p>
            <?php else: ?>
            <form method="post" action="/api/admin/classify?password=<?= h($pwQ) ?>">
                <?php foreach ($modFiles as $file):
                    $info = $classification[$file] ?? [];
                    $type = (isset($info['type']) && $info['type'] === 'optional') ? 'optional' : 'required';
                    $name = isset($info['name']) ? (string) $info['name'] : '';
                    $desc = isset($info['description']) ? (string) $info['description'] : '';
                    $def  = !empty($info['default']);
                    $fk   = h($file);
                ?>
                <div class="mod-row">
                    <div class="mod-name"><?= h($file) ?></div>
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

        <!-- Sürüm notları / otomatik changelog -->
        <div class="card full">
            <h3>Sürüm Notları (Otomatik Changelog) &mdash; Güncel: <span class="badge badge-green">v<?= $packVersion ?></span></h3>
            <p class="muted">
                <code>files/</code> içeriği her değiştiğinde (mod eklenir/güncellenir/kaldırılır
                veya sınıflandırma değişir) sürüm otomatik artar ve aşağıya kayıt düşülür.
                Launcher bu notları oyunculara gösterir.
            </p>
            <?php if (empty($changelog)): ?>
                <p class="muted" style="margin-top:12px;">Henüz kayıt yok. <code>files/</code> klasörüne dosya bırakıp index'i yenileyin.</p>
            <?php else: ?>
            <div class="table-scroll" style="margin-top:12px;max-height:320px;">
                <table>
                    <tr><th>Sürüm</th><th>Tarih (UTC)</th><th>Değişiklik</th></tr>
                    <?php foreach ($changelog as $c): ?>
                    <tr>
                        <td><span class="badge badge-green">v<?= (int) ($c['Version'] ?? 0) ?></span></td>
                        <td><?= h($c['Timestamp'] ?? '') ?></td>
                        <td style="white-space:normal;"><?= h($c['Note'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>

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

        <!-- Discord kullanıcıları -->
        <div class="card full">
            <h3>Kullanıcılar (Discord)</h3>
            <p class="muted">
                Discord ile giriş yapan hesaplar ve sahip oldukları Minecraft hesabı sayısı.
                Bir Discord hesabını banlamak; tüm oturumlarını, aktif giriş tokenlarını iptal eder
                ve hesaplarının oyuna girişini engeller.
            </p>
            <div class="table-scroll" style="margin-top:12px;max-height:320px;">
                <table>
                    <tr><th>Discord ID</th><th>Kullanıcı</th><th>Hesap</th><th>Durum</th><th>İşlem</th></tr>
                    <?php foreach ($discordUsers as $du): $dbn = (int) $du['is_banned'] === 1; ?>
                    <tr>
                        <td><?= h($du['discord_id']) ?></td>
                        <td><?= h($du['username'] ?? '') ?></td>
                        <td><?= (int) $du['account_count'] ?></td>
                        <td><?= $dbn ? '<span class="badge" style="background:#da363333;color:#f85149">Banlı</span>' : '<span class="badge badge-green">Aktif</span>' ?></td>
                        <td>
                            <?php if ($dbn): ?>
                            <button class="secondary" onclick="banAcct('discord','<?= h($du['discord_id']) ?>','unban')">Ban Kaldır</button>
                            <?php else: ?>
                            <button class="danger" onclick="banAcct('discord','<?= h($du['discord_id']) ?>','ban')">Banla</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($discordUsers)): ?>
                    <tr><td colspan="5" class="muted">Henüz Discord kullanıcısı yok.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Minecraft hesapları + skin moderasyonu -->
        <div class="card full">
            <h3>Minecraft Hesapları &amp; Skinler</h3>
            <p class="muted">
                Kayıtlı Minecraft hesapları, sahibi ve yüklenen skin. Hesabı UUID üzerinden
                banlayabilir, uygunsuz bir skini sıfırlayabilirsiniz (oyuncu varsayılana döner).
            </p>
            <div class="table-scroll" style="margin-top:12px;max-height:420px;">
                <table>
                    <tr><th>Skin</th><th>Kullanıcı Adı</th><th>UUID</th><th>Discord</th><th>Durum</th><th>İşlem</th></tr>
                    <?php foreach ($accounts as $a): $abn = (int) $a['is_banned'] === 1; $sk = (string) ($a['skin_hash'] ?? ''); ?>
                    <tr>
                        <td><?php if ($sk !== ''): ?><img src="/files/skins/<?= h($sk) ?>.png" alt="" style="width:24px;height:24px;image-rendering:pixelated;border-radius:4px" onerror="this.style.display='none'"><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <td><strong style="color:#3fb950"><?= h($a['username']) ?></strong></td>
                        <td class="muted"><?= h(substr((string) $a['uuid'], 0, 13)) ?>&hellip;</td>
                        <td><?= h($a['discord_username'] ?? $a['discord_id']) ?></td>
                        <td><?= $abn ? '<span class="badge" style="background:#da363333;color:#f85149">Banlı</span>' : '<span class="badge badge-green">Aktif</span>' ?></td>
                        <td>
                            <?php if ($abn): ?>
                            <button class="secondary" onclick="banAcct('uuid','<?= h($a['uuid']) ?>','unban')">Ban Kaldır</button>
                            <?php else: ?>
                            <button class="danger" onclick="banAcct('uuid','<?= h($a['uuid']) ?>','ban')">Banla</button>
                            <?php endif; ?>
                            <?php if ($sk !== ''): ?>
                            <button class="secondary" onclick="resetSkin(<?= (int) $a['id'] ?>)">Skini Sıfırla</button>
                            <?php endif; ?>
                        </td>
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
        const ADMIN_PW = <?= json_encode($pw) ?>;
        function doBan(action) {
            const hwid = document.getElementById('ban-hwid').value.trim();
            const reason = document.getElementById('ban-reason').value.trim();
            const out = document.getElementById('ban-result');
            if (!hwid) { out.textContent = 'HWID gerekli.'; out.style.color = '#f85149'; return; }
            fetch('/api/ban?password=' + encodeURIComponent(ADMIN_PW), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ hwid: hwid, reason: reason, action: action })
            })
            .then(r => r.json())
            .then(d => {
                out.style.color = (d.status === 'ok') ? '#3fb950' : '#f85149';
                out.textContent = (d.status === 'ok')
                    ? (action === 'ban' ? 'HWID yasaklandı.' : 'Ban kaldırıldı.') + ' Sayfayı yenileyin.'
                    : ('Hata: ' + (d.error || JSON.stringify(d)));
            })
            .catch(e => { out.style.color = '#f85149'; out.textContent = 'İstek başarısız: ' + e; });
        }

        function banAcct(type, value, action) {
            const verb = action === 'ban' ? 'banla' : 'ban kaldır';
            if (!confirm(type.toUpperCase() + ' "' + value + '" -> ' + verb + '?')) return;
            fetch('/api/admin/ban_account?password=' + encodeURIComponent(ADMIN_PW), {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: type, value: value, action: action })
            })
            .then(r => r.json())
            .then(d => { if (d.status === 'ok') location.reload(); else alert('Hata: ' + (d.error || JSON.stringify(d))); })
            .catch(e => alert('İstek başarısız: ' + e));
        }

        function resetSkin(accountId) {
            if (!confirm('Bu hesabın skini sıfırlansın mı?')) return;
            fetch('/api/admin/skin?password=' + encodeURIComponent(ADMIN_PW), {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'reset', account_id: accountId })
            })
            .then(r => r.json())
            .then(d => { if (d.status === 'ok') location.reload(); else alert('Hata'); })
            .catch(e => alert('İstek başarısız: ' + e));
        }
    </script>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}
