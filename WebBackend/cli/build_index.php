<?php
/**
 * Athena Studios Launcher - CLI index builder.
 *
 * Rebuilds files/index.json and files/mods.json from the current contents of
 * files/. Intended to run from cron, e.g. (every 5 minutes):
 *
 *   (slash-five) * * * * php /path/to/WebBackend/cli/build_index.php
 *
 * See README.md / nginx.conf.sample for the exact crontab line.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/builder.php';

// Incremental by default (cheap: only re-hashes changed files) so it is safe
// to run frequently from cron. Pass --force / -f for a full re-hash.
$force = in_array('--force', $argv ?? [], true) || in_array('-f', $argv ?? [], true);

$start = microtime(true);

try {
    $result = refresh_pack($force);
} catch (Throwable $e) {
    fwrite(STDERR, '[Athena] Index oluşturma hatası: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$ms = (int) round((microtime(true) - $start) * 1000);

echo '[Athena Studios] Paket ' . ($result['changed'] ? 'güncellendi' : 'zaten güncel') . '.' . PHP_EOL;
echo '  Paket sürümü                : v' . $result['version'] . PHP_EOL;
if ($result['index'] !== null) {
    echo '  Zorunlu dosya (index.json)  : ' . $result['index'] . PHP_EOL;
    echo '  İsteğe bağlı mod (mods.json): ' . $result['optional'] . PHP_EOL;
}
if ($result['changed']) {
    echo '  Eklendi   : ' . count($result['added']) . PHP_EOL;
    echo '  Güncellendi: ' . count($result['updated']) . PHP_EOL;
    echo '  Kaldırıldı : ' . count($result['removed']) . PHP_EOL;
}
echo '  Süre                        : ' . $ms . ' ms' . PHP_EOL;
echo '  files/                      : ' . FILES_DIR . PHP_EOL;

exit(0);
