<?php
/**
 * Athena Studios Launcher - Admin RBAC migration / seeder (CLI).
 *
 * Run once on the server after deploying the panel-auth changes:
 *
 *   sudo -u www-data php /var/www/athena/cli/admin_migrate.php
 *
 * It creates the admin_roles / admin_local tables (via init_db), seeds the
 * founder Discord id as 'kurucu', and generates the one-time break-glass local
 * admin if none exists - printing its username/password to STDOUT so you can
 * capture it out-of-band, then delete data/breakglass_credentials.txt.
 *
 * Flags:
 *   --reset-breakglass   delete all admin_local rows and regenerate a new one
 *                        (escape hatch if the break-glass credential is lost).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/admin_auth.php';

$args  = $argv ?? [];
$reset = in_array('--reset-breakglass', $args, true);

try {
    init_db();                                  // creates tables + seeds founder role

    if ($reset) {
        delete_all_admin_local();
        @unlink(DATA_DIR . '/breakglass_credentials.txt');
        echo "[Athena] Mevcut break-glass hesapları silindi, yenisi üretiliyor..." . PHP_EOL;
    }

    $hadLocal = admin_local_count() > 0;
    ensure_breakglass_account();                // no-op if one already exists
} catch (Throwable $e) {
    fwrite(STDERR, '[Athena] Migration hatası: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo '[Athena Studios] Admin RBAC migration tamam.' . PHP_EOL;
echo '  Kurucu (founder) Discord ID : ' . ATHENA_FOUNDER_DISCORD_ID . ' -> kurucu' . PHP_EOL;
echo '  Kurucu rol sayısı           : ' . count_kurucu() . PHP_EOL;
echo '  Break-glass local hesap     : ' . admin_local_count() . ' adet' . PHP_EOL;

$credFile = DATA_DIR . '/breakglass_credentials.txt';
if (!$hadLocal && is_file($credFile)) {
    echo PHP_EOL . '  >>> BREAK-GLASS GİRİŞ BİLGİLERİ (bir kez gösterilir) <<<' . PHP_EOL;
    echo file_get_contents($credFile) . PHP_EOL;
    echo '  Not: Bu bilgileri kaydedin, sonra şu dosyayı SİLİN:' . PHP_EOL;
    echo '       ' . $credFile . PHP_EOL;
} elseif ($hadLocal) {
    echo '  (Break-glass hesabı zaten vardı; bilgiler yeniden gösterilmez. Kayıpsa --reset-breakglass kullanın.)' . PHP_EOL;
}

exit(0);
