# Athena Studios Launcher - multi-resolution .ico generator
# Builds minecraft.ico (kept filename so csproj/installer stay valid) from a brand PNG.
# Uses .NET System.Drawing only (no ImageMagick). PNG-compressed frames (valid for Vista+).
# Run once:  powershell -ExecutionPolicy Bypass -File tools\make_icon.ps1

param(
    [string]$Source = "images\ChatGPT_Image_20_Sub_2026_23_34_00.png",
    [string]$Output = "minecraft.ico"
)

Add-Type -AssemblyName System.Drawing

$sizes = @(16, 24, 32, 48, 64, 128, 256)

$src = [System.Drawing.Image]::FromFile((Resolve-Path $Source))

$frames = @()
foreach ($s in $sizes) {
    $bmp = New-Object System.Drawing.Bitmap($s, $s, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.Clear([System.Drawing.Color]::Transparent)
    # Fit source into square, preserving aspect (source is square already)
    $g.DrawImage($src, 0, 0, $s, $s)
    $g.Dispose()

    $ms = New-Object System.IO.MemoryStream
    $bmp.Save($ms, [System.Drawing.Imaging.ImageFormat]::Png)
    $bytes = $ms.ToArray()
    $ms.Dispose()
    $bmp.Dispose()
    $frames += [pscustomobject]@{ Size = $s; Bytes = $bytes }
}
$src.Dispose()

# Build ICO container
$out = New-Object System.IO.MemoryStream
$bw = New-Object System.IO.BinaryWriter($out)

# ICONDIR header
$bw.Write([uint16]0)              # reserved
$bw.Write([uint16]1)              # type = 1 (icon)
$bw.Write([uint16]$frames.Count)  # count

# Directory entries are 16 bytes each; image data starts after all entries.
$offset = 6 + (16 * $frames.Count)
foreach ($f in $frames) {
    $wh = $f.Size
    $byteWH = if ($wh -ge 256) { [byte]0 } else { [byte]$wh }
    $bw.Write([byte]$byteWH)      # width
    $bw.Write([byte]$byteWH)      # height
    $bw.Write([byte]0)            # color count (0 = >=256 colors)
    $bw.Write([byte]0)            # reserved
    $bw.Write([uint16]1)         # color planes
    $bw.Write([uint16]32)        # bits per pixel
    $bw.Write([uint32]$f.Bytes.Length)  # size of image data
    $bw.Write([uint32]$offset)          # offset of image data
    $offset += $f.Bytes.Length
}
foreach ($f in $frames) { $bw.Write($f.Bytes) }

$bw.Flush()
[System.IO.File]::WriteAllBytes((Join-Path (Get-Location) $Output), $out.ToArray())
$bw.Dispose(); $out.Dispose()

Write-Host "Olusturuldu: $Output ($($frames.Count) kare: $($sizes -join ', ') px)"
