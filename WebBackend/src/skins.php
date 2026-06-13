<?php
/**
 * Athena Studios Launcher - Skin validation & storage.
 *
 * Players upload a Minecraft skin PNG from the launcher; we validate it is a real
 * 64x64 (or legacy 64x32) PNG, re-encode it through GD to strip any embedded
 * payload, and store it by content hash. The AthenaCore mod fetches these and
 * applies them in-game (no Yggdrasil / no texture signing needed).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const SKIN_MAX_BYTES = 262144; // 256 KB

function skin_file_path(string $hash): string
{
    return SKINS_DIR . '/' . $hash . '.png';
}

/**
 * Validate + re-encode + store an uploaded skin. $tmpPath is a local file path
 * (e.g. $_FILES['skin']['tmp_name']). Returns ['hash','model','width','height']
 * on success, or null when the input is not an acceptable skin PNG.
 */
function validate_and_store_skin(string $tmpPath, string $model): ?array
{
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return null;
    }
    $size = filesize($tmpPath);
    if ($size === false || $size <= 0 || $size > SKIN_MAX_BYTES) {
        return null;
    }

    $info = @getimagesize($tmpPath);
    if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG) {
        return null;
    }
    $w = (int) $info[0];
    $h = (int) $info[1];
    if (!(($w === 64 && $h === 64) || ($w === 64 && $h === 32))) {
        return null;
    }

    $src = @imagecreatefrompng($tmpPath);
    if ($src === false) {
        return null;
    }
    // Preserve transparency and re-encode to a clean PNG.
    imagealphablending($src, false);
    imagesavealpha($src, true);
    ob_start();
    $ok  = imagepng($src, null, 9);
    $png = ob_get_clean();
    imagedestroy($src);

    if (!$ok || !is_string($png) || $png === '' || strlen($png) > SKIN_MAX_BYTES) {
        return null;
    }

    $hash = hash('sha256', $png);
    $dest = skin_file_path($hash);
    if (!is_file($dest)) {
        if (@file_put_contents($dest, $png) === false) {
            return null;
        }
        @chmod($dest, 0644);
    }

    return [
        'hash'   => $hash,
        'model'  => ($model === 'slim') ? 'slim' : 'default',
        'width'  => $w,
        'height' => $h,
    ];
}
