<?php
/**
 * Athena Studios Launcher - Modrinth modpack (.mrpack) üretici.
 *
 * files/ içeriğinden standart bir Modrinth modpack (.mrpack) ZIP üretir; böylece
 * telefon istemcileri (MojoLauncher / PojavLauncher / Amethyst / PrismLauncher)
 * tek dokunuşla Athena modpack'ini (1.12.2 Forge + modlar + configler) kurabilir.
 *
 * Biçim: ZIP (modrinth.index.json + overrides/). mods/*.jar dosyaları
 * files[] içine (sha1+sha512+download URL) yazılır; mod olmayan dosyalar
 * (config/ vb.) overrides/ altına eklenir.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/builder.php'; // read_json_file, is_meta_or_data

/**
 * @return string Üretilen .mrpack dosyasının yolu (DATA_DIR içinde).
 */
function build_mrpack(string $baseUrl): string
{
    $cfg   = read_json_file(FILES_DIR . '/server_config.json');
    $mc    = is_array($cfg) && !empty($cfg['MinecraftVersion']) ? (string) $cfg['MinecraftVersion'] : '1.12.2';
    $forge = is_array($cfg) && !empty($cfg['LoaderVersion']) ? (string) $cfg['LoaderVersion'] : '14.23.5.2860';

    $files = [];
    $overrides = []; // relPath => absPath

    if (is_dir(FILES_DIR)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(FILES_DIR, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $fi) {
            /** @var SplFileInfo $fi */
            if (!$fi->isFile()) {
                continue;
            }
            $abs = $fi->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen(FILES_DIR))), '/');
            if ($rel === '' || is_meta_or_data($rel)) {
                continue;
            }
            $top = explode('/', $rel)[0];

            if ($top === 'mods' && preg_match('/\.jar$/i', $rel)) {
                $encoded = implode('/', array_map('rawurlencode', explode('/', $rel)));
                $files[] = [
                    'path'     => $rel,
                    'hashes'   => ['sha1' => hash_file('sha1', $abs), 'sha512' => hash_file('sha512', $abs)],
                    'env'      => ['client' => 'required', 'server' => 'unsupported'],
                    'downloads'=> [$baseUrl . '/files/' . $encoded],
                    'fileSize' => (int) $fi->getSize(),
                ];
            } else {
                // config/ ve diğer mod-olmayan içerik overrides/ altına gömülür
                $overrides[$rel] = $abs;
            }
        }
    }
    usort($files, static fn($a, $b) => strcmp($a['path'], $b['path']));

    $ver = read_json_file(VERSION_PATH);
    $versionId = (is_array($ver) && isset($ver['Version'])) ? (string) $ver['Version'] : '1';

    $index = [
        'formatVersion' => 1,
        'game'          => 'minecraft',
        'versionId'     => $versionId,
        'name'          => BRAND,
        'summary'       => BRAND . ' ' . $mc . ' Forge modpack',
        'files'         => $files,
        'dependencies'  => ['minecraft' => $mc, 'forge' => $forge],
    ];

    // Per-request benzersiz geçici dosya (eşzamanlı isteklerde yarış/yarım-dosya önler).
    try {
        $tmp = DATA_DIR . '/mrpack_' . bin2hex(random_bytes(6)) . '.mrpack';
    } catch (Throwable $e) {
        $tmp = DATA_DIR . '/mrpack_' . uniqid('', true) . '.mrpack';
    }
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('mrpack ZIP açılamadı.');
    }
    $zip->addFromString('modrinth.index.json', (string) json_encode(
        $index,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));
    foreach ($overrides as $rel => $abs) {
        $zip->addFile($abs, 'overrides/' . $rel);
    }
    $zip->close();

    return $tmp;
}
