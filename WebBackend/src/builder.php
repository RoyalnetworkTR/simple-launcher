<?php
/**
 * Athena Studios Launcher - Dynamic index / optional-mods / changelog engine.
 *
 * The package is regenerated DYNAMICALLY: every time the launcher pulls
 * /api/index.json or /api/mods.json the router calls refresh_pack(), which
 * cheaply stat-scans files/ and re-hashes ONLY the files whose size/mtime
 * changed (so large packs are not fully re-hashed on every request). When a
 * change (add / update / remove / reclassify) is detected it:
 *   - rewrites files/index.json (REQUIRED, locked, SHA-256) and files/mods.json
 *     (OPTIONAL, user-selectable mods),
 *   - bumps the pack version,
 *   - appends an auto-generated release note to the changelog.
 *
 * Classification comes from files/mods_classification.json; any mod not marked
 * "optional" defaults to "required" (everything locked / anti-cheat).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ===========================================================================
// JSON helpers (BOM-safe)
// ===========================================================================

/**
 * Read + json_decode a file, transparently stripping a leading UTF-8 BOM
 * (Windows editors / PowerShell emit one by default and it breaks json_decode).
 *
 * @return mixed|null Decoded value, or null if missing/unreadable/invalid.
 */
function read_json_file(string $path)
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }
    return json_decode($raw, true);
}

/**
 * Pretty-print JSON to disk (atomic write).
 *
 * @param mixed $data
 */
function write_json(string $path, $data): void
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if ($json === false) {
        throw new RuntimeException('JSON kodlanamadı: ' . $path);
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Dosya yazılamadı: ' . $path);
    }
    if (!@rename($tmp, $path)) {
        @unlink($path);
        if (!@rename($tmp, $path)) {
            throw new RuntimeException('Dosya değiştirilemedi: ' . $path);
        }
    }
}

// ===========================================================================
// Classification
// ===========================================================================

/**
 * Load the mods classification map ("mods" key). Returns [] on any problem.
 *
 * @return array<string, array<string, mixed>>
 */
function load_classification(): array
{
    $decoded = read_json_file(FILES_DIR . '/mods_classification.json');
    if (!is_array($decoded) || !isset($decoded['mods']) || !is_array($decoded['mods'])) {
        return [];
    }
    return $decoded['mods'];
}

/**
 * Build a sanitized mod id from a filename: lowercase, drop .jar, non-alnum -> '-'.
 */
function mod_id_from_filename(string $filename): string
{
    $base = preg_replace('/\.jar$/i', '', $filename);
    $base = strtolower((string) $base);
    $base = preg_replace('/[^a-z0-9]+/', '-', (string) $base);
    return trim((string) $base, '-');
}

// ===========================================================================
// Scanning / assembling
// ===========================================================================

/** True for backend-managed files that must never appear in the client pack. */
function is_meta_or_data(string $relative): bool
{
    // Meta dosyaları yalnız files/ KÖKünde hariç tut; alt klasörlerdeki aynı adlı
    // dosyalar (örn. config/x/index.json) korunur.
    if (strpos($relative, '/') === false && in_array($relative, META_FILES, true)) {
        return true;
    }
    $segments = explode('/', $relative);
    return ($segments[0] ?? '') === 'data';
}

/**
 * Cheap scan of files/ : relative-path => ['size'=>int, 'mtime'=>int, 'abs'=>string].
 * No hashing happens here.
 *
 * @return array<string, array{size:int, mtime:int, abs:string}>
 */
function scan_files(): array
{
    $out = [];
    if (!is_dir(FILES_DIR)) {
        return $out;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(FILES_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (!$fileInfo->isFile()) {
            continue;
        }
        $absolute = $fileInfo->getPathname();
        $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen(FILES_DIR))), '/');
        if ($relative === '' || is_meta_or_data($relative)) {
            continue;
        }
        $out[$relative] = [
            'size'  => (int) $fileInfo->getSize(),
            'mtime' => (int) $fileInfo->getMTime(),
            'abs'   => $absolute,
        ];
    }
    return $out;
}

/**
 * Split a hashed file map into index (required) + optional catalogue arrays,
 * applying the classification (mods/ files marked "optional" go to mods.json).
 *
 * @param array<string, array{size:int, hash:string}> $fileMap rel => {size,hash}
 * @param array<string, array<string,mixed>>          $classification
 * @return array{index: array<int,array>, optional: array<int,array>}
 */
function assemble_artifacts(array $fileMap, array $classification): array
{
    $index = [];
    $optional = [];

    foreach ($fileMap as $relative => $meta) {
        $segments   = explode('/', $relative);
        $topSegment = $segments[0] ?? '';
        $basename   = basename($relative);

        $isOptionalMod = false;
        if ($topSegment === 'mods') {
            $info = $classification[$basename] ?? null;
            if (is_array($info) && (($info['type'] ?? '') === 'optional')) {
                $isOptionalMod = true;
                $optional[] = [
                    'Id'          => mod_id_from_filename($basename),
                    'Name'        => (isset($info['name']) && $info['name'] !== '') ? (string) $info['name'] : $basename,
                    'Description' => isset($info['description']) ? (string) $info['description'] : '',
                    'Path'        => $relative,
                    'Hash'        => $meta['hash'],
                    'Size'        => $meta['size'],
                    'Default'     => !empty($info['default']),
                ];
            }
        }

        if (!$isOptionalMod) {
            $index[] = [
                'Path' => $relative,
                'Hash' => $meta['hash'],
                'Size' => $meta['size'],
            ];
        }
    }

    usort($index, static fn($a, $b) => strcmp($a['Path'], $b['Path']));
    usort($optional, static fn($a, $b) => strcmp($a['Id'], $b['Id']));

    return ['index' => $index, 'optional' => $optional];
}

// ===========================================================================
// State + changelog
// ===========================================================================

/**
 * @return array{version:int, class_hash:string, files:array<string,array>}
 */
function load_state(): array
{
    $d = read_json_file(STATE_PATH);
    if (!is_array($d)) {
        $d = [];
    }
    return [
        'version'    => isset($d['version']) ? (int) $d['version'] : 0,
        'class_hash' => isset($d['class_hash']) ? (string) $d['class_hash'] : '',
        'files'      => (isset($d['files']) && is_array($d['files'])) ? $d['files'] : [],
    ];
}

/** @return array<int,array> newest-first changelog entries */
function load_changelog(): array
{
    $d = read_json_file(CHANGELOG_PATH);
    return is_array($d) ? $d : [];
}

/**
 * Human-friendly display label for a changed path (uses the classification
 * display name for mods, otherwise the file/relative name).
 *
 * @param array<string, array<string,mixed>> $classification
 */
function display_label(string $relative, array $classification): string
{
    $basename = basename($relative);
    if (strncmp($relative, 'mods/', 5) === 0) {
        $info = $classification[$basename] ?? null;
        if (is_array($info) && isset($info['name']) && $info['name'] !== '') {
            return (string) $info['name'];
        }
        return preg_replace('/\.jar$/i', '', $basename) ?: $basename;
    }
    return $relative;
}

/**
 * Build a concise Turkish release note from the detected diff.
 *
 * @param array<int,string> $added
 * @param array<int,string> $updated
 * @param array<int,string> $removed
 * @param array<string, array<string,mixed>> $classification
 */
function human_note(array $added, array $updated, array $removed, array $classification, bool $classChanged, bool $isFirst): string
{
    if ($isFirst) {
        return 'İlk paket sürümü oluşturuldu.';
    }

    $fmt = static function (array $paths) use ($classification): string {
        $labels = array_map(static fn($p) => display_label($p, $classification), $paths);
        $labels = array_values(array_unique($labels));
        $shown = array_slice($labels, 0, 6);
        $note = implode(', ', $shown);
        $extra = count($labels) - count($shown);
        if ($extra > 0) {
            $note .= ' (+' . $extra . ' daha)';
        }
        return $note;
    };

    $parts = [];
    if (!empty($added)) {
        $parts[] = '➕ Eklendi: ' . $fmt($added);
    }
    if (!empty($updated)) {
        $parts[] = '🔄 Güncellendi: ' . $fmt($updated);
    }
    if (!empty($removed)) {
        $parts[] = '➖ Kaldırıldı: ' . $fmt($removed);
    }
    if (empty($parts) && $classChanged) {
        return 'Mod sınıflandırması güncellendi (zorunlu / isteğe bağlı).';
    }
    if (empty($parts)) {
        return 'Paket güncellendi.';
    }
    return implode('  |  ', $parts);
}

// ===========================================================================
// Main engine
// ===========================================================================

/**
 * Incrementally refresh the pack. Cheap when nothing changed (stat-only).
 * Re-hashes only changed files unless $force is true (full re-hash).
 *
 * @return array{index:?int, optional:?int, version:int, changed:bool,
 *               added:array, updated:array, removed:array}
 */
function refresh_pack(bool $force = false): array
{
    $lockFp = @fopen(LOCK_PATH, 'c');
    if ($lockFp) {
        @flock($lockFp, LOCK_EX);
    }

    try {
        $state         = load_state();
        $prevFiles     = $state['files'];
        $version       = (int) $state['version'];
        $prevClassHash = $state['class_hash'];

        $classification = load_classification();
        $classHash      = hash('sha256', (string) json_encode($classification));

        $scan = scan_files();

        $newFiles = [];   // rel => ['size','mtime','hash']
        $added    = [];
        $updated  = [];
        $removed  = [];

        foreach ($scan as $rel => $meta) {
            $prev = $prevFiles[$rel] ?? null;
            $needHash = $force
                || $prev === null
                || (int) ($prev['size'] ?? -1) !== $meta['size']
                || (int) ($prev['mtime'] ?? -1) !== $meta['mtime']
                || empty($prev['hash']);

            if ($needHash) {
                $h = hash_file('sha256', $meta['abs']);
                if ($h === false) {
                    continue; // unreadable; skip
                }
            } else {
                $h = (string) $prev['hash'];
            }

            $newFiles[$rel] = ['size' => $meta['size'], 'mtime' => $meta['mtime'], 'hash' => $h];

            if ($prev === null) {
                $added[] = $rel;
            } elseif ((string) ($prev['hash'] ?? '') !== $h) {
                $updated[] = $rel;
            }
        }

        foreach ($prevFiles as $rel => $_) {
            if (!isset($scan[$rel])) {
                $removed[] = $rel;
            }
        }

        $contentChanged   = !empty($added) || !empty($updated) || !empty($removed);
        $classChanged     = ($classHash !== $prevClassHash);
        $artifactsMissing = !is_file(FILES_DIR . '/index.json') || !is_file(FILES_DIR . '/mods.json');
        $isFirst          = ($version === 0 && empty($prevFiles));

        // Fast path: nothing changed and artifacts already exist.
        if (!$force && !$contentChanged && !$classChanged && !$artifactsMissing) {
            return [
                'index' => null, 'optional' => null, 'version' => $version,
                'changed' => false, 'added' => [], 'updated' => [], 'removed' => [],
            ];
        }

        // (Re)assemble + write artifacts.
        $art = assemble_artifacts($newFiles, $classification);
        write_json(FILES_DIR . '/index.json', $art['index']);
        write_json(FILES_DIR . '/mods.json', ['Optional' => $art['optional']]);

        // Bump version + write a changelog entry only on a real change.
        if ($contentChanged || $classChanged || $isFirst) {
            $version++;
            $timestamp = gmdate('c');
            $note = human_note($added, $updated, $removed, $classification, $classChanged, $isFirst);

            $entry = [
                'Version'   => $version,
                'Timestamp' => $timestamp,
                'Note'      => $note,
                'Added'     => array_values($added),
                'Updated'   => array_values($updated),
                'Removed'   => array_values($removed),
            ];

            $log = load_changelog();
            array_unshift($log, $entry);          // newest first
            if (count($log) > 200) {
                $log = array_slice($log, 0, 200);
            }
            write_json(CHANGELOG_PATH, $log);
            write_json(VERSION_PATH, ['Version' => $version, 'UpdatedAt' => $timestamp, 'Note' => $note]);
        }

        // Persist new state.
        write_json(STATE_PATH, [
            'version'    => $version,
            'class_hash' => $classHash,
            'updated_at' => gmdate('c'),
            'files'      => $newFiles,
        ]);

        return [
            'index' => count($art['index']), 'optional' => count($art['optional']),
            'version' => $version, 'changed' => ($contentChanged || $classChanged),
            'added' => $added, 'updated' => $updated, 'removed' => $removed,
        ];
    } finally {
        if ($lockFp) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }
    }
}

/**
 * Full rebuild (force re-hash of everything). Used by the cron CLI and the
 * admin "rebuild" button. Kept as a stable entry point.
 *
 * @return array{index:?int, optional:?int, version:int, changed:bool,
 *               added:array, updated:array, removed:array}
 */
function build_index(): array
{
    return refresh_pack(true);
}
