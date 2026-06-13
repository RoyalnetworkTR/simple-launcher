<?php
/**
 * Athena Studios Launcher - Minecraft Server List Ping (1.7+ protocol)
 *
 * Pure-PHP implementation of the modern (post-1.7) status handshake so we do
 * not depend on any external service. Returns player counts + latency or
 * throws on any failure.
 */

declare(strict_types=1);

/**
 * Write a Minecraft VarInt for $n.
 */
function write_varint(int $n): string
{
    // Operate on the unsigned 32-bit representation.
    $value = $n & 0xFFFFFFFF;
    $out = '';
    do {
        $temp = $value & 0x7F;
        $value = ($value >> 7) & 0x01FFFFFF; // logical shift (PHP int is 64-bit)
        if ($value !== 0) {
            $temp |= 0x80;
        }
        $out .= chr($temp);
    } while ($value !== 0);
    return $out;
}

/**
 * Read a Minecraft VarInt from an open socket. Throws on EOF / overlong value.
 */
function read_varint($fp): int
{
    $numRead = 0;
    $result  = 0;
    do {
        $byte = fread($fp, 1);
        if ($byte === '' || $byte === false) {
            throw new RuntimeException('Bağlantı zaman aşımına uğradı veya kapandı (VarInt okunamadı).');
        }
        $value = ord($byte);
        $result |= ($value & 0x7F) << (7 * $numRead);
        $numRead++;
        if ($numRead > 5) {
            throw new RuntimeException('VarInt çok uzun.');
        }
    } while (($value & 0x80) !== 0);
    return $result;
}

/**
 * Best-effort SRV resolution for a Minecraft host. Returns [host, port] or null.
 */
function mc_resolve_srv(string $host): ?array
{
    if (!function_exists('dns_get_record')) {
        return null;
    }
    // Suppress warnings; SRV lookups frequently fail and that is fine.
    $records = @dns_get_record('_minecraft._tcp.' . $host, DNS_SRV);
    if (is_array($records) && !empty($records)) {
        $rec = $records[0];
        if (!empty($rec['target']) && !empty($rec['port'])) {
            return [(string) $rec['target'], (int) $rec['port']];
        }
    }
    return null;
}

/**
 * Ping a Minecraft Java server.
 *
 * @param string $host   Hostname, optionally "host:port".
 * @param int    $port   Default port if not embedded in $host.
 * @param float  $timeout Socket timeout in seconds.
 *
 * @return array{status:string,players_online:int,players_max:int,latency:int}
 * @throws RuntimeException on any connectivity / protocol error.
 */
function mc_ping(string $host, int $port = 25565, float $timeout = 4.0): array
{
    $host = trim($host);
    if ($host === '') {
        throw new RuntimeException('Geçersiz sunucu adresi.');
    }

    // Allow "host:port" form. Guard against IPv6 literals (rare here).
    if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
        [$h, $p] = explode(':', $host, 2);
        $host = $h;
        if (ctype_digit($p)) {
            $port = (int) $p;
        }
    }

    // The hostname that is sent in the handshake should be the user-facing one.
    $handshakeHost = $host;
    $connectHost   = $host;
    $connectPort   = $port;

    // Try SRV only when the user did not explicitly give a port.
    if ($port === 25565) {
        $srv = mc_resolve_srv($host);
        if ($srv !== null) {
            $connectHost = $srv[0];
            $connectPort = $srv[1];
        }
    }

    $errno  = 0;
    $errstr = '';
    $fp = @fsockopen($connectHost, $connectPort, $errno, $errstr, $timeout);
    if ($fp === false) {
        throw new RuntimeException("Sunucuya bağlanılamadı: {$errstr} ({$errno})");
    }

    try {
        stream_set_timeout($fp, (int) $timeout, (int) (($timeout - (int) $timeout) * 1_000_000));

        // ---- Handshake packet (state = 1 / status) ----
        $data  = write_varint(0x00);               // packet id
        $data .= write_varint(47);                 // protocol version (47 = 1.8, widely accepted)
        $data .= write_varint(strlen($handshakeHost));
        $data .= $handshakeHost;
        $data .= pack('n', $connectPort);          // unsigned short, big-endian
        $data .= write_varint(0x01);               // next state: status

        if (fwrite($fp, write_varint(strlen($data)) . $data) === false) {
            throw new RuntimeException('El sıkışma paketi gönderilemedi.');
        }

        // ---- Status request packet: length(1) + id(0) ----
        $start = microtime(true);
        if (fwrite($fp, chr(0x01) . chr(0x00)) === false) {
            throw new RuntimeException('Durum isteği gönderilemedi.');
        }

        // ---- Read status response ----
        $packetLength = read_varint($fp);          // total length (unused beyond sanity)
        if ($packetLength <= 0) {
            throw new RuntimeException('Boş yanıt alındı.');
        }
        $packetId = read_varint($fp);              // should be 0x00
        if ($packetId !== 0x00) {
            throw new RuntimeException('Beklenmeyen paket kimliği: ' . $packetId);
        }
        $jsonLength = read_varint($fp);
        if ($jsonLength <= 0 || $jsonLength > 5_000_000) {
            throw new RuntimeException('Geçersiz JSON uzunluğu.');
        }

        $json = '';
        $remaining = $jsonLength;
        while ($remaining > 0) {
            $chunk = fread($fp, $remaining);
            if ($chunk === '' || $chunk === false) {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('Yanıt okunurken zaman aşımı.');
                }
                throw new RuntimeException('Yanıt eksik okundu.');
            }
            $json .= $chunk;
            $remaining -= strlen($chunk);
        }

        $latencyMs = (microtime(true) - $start) * 1000.0;

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Sunucu yanıtı çözümlenemedi.');
        }

        $online = 0;
        $max    = 0;
        if (isset($decoded['players']) && is_array($decoded['players'])) {
            $online = (int) ($decoded['players']['online'] ?? 0);
            $max    = (int) ($decoded['players']['max'] ?? 0);
        }

        return [
            'status'         => 'online',
            'players_online' => $online,
            'players_max'    => $max,
            'latency'        => (int) round($latencyMs),
        ];
    } finally {
        if (is_resource($fp)) {
            fclose($fp);
        }
    }
}
