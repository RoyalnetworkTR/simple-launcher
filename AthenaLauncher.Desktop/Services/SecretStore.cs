using System;
using System.IO;
using System.Security.Cryptography;
using System.Text;

namespace OfflineMinecraftLauncher.Services;

/// <summary>
/// Encrypts the auth blob (JWT + per-account secrets) at rest with AES-GCM. The
/// 256-bit key is generated once and stored beside the data, restricted to the
/// current user (chmod 600 on Unix). Cross-platform, no extra dependencies.
/// </summary>
public static class SecretStore
{
    private static string Dir =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), AppConfig.AppDataFolder);

    private static string DataFile => Path.Combine(Dir, "auth.dat");
    private static string KeyFile => Path.Combine(Dir, "auth.key");

    private static byte[] GetKey()
    {
        Directory.CreateDirectory(Dir);
        if (File.Exists(KeyFile))
        {
            var existing = File.ReadAllBytes(KeyFile);
            if (existing.Length == 32) return existing;
        }
        var key = RandomNumberGenerator.GetBytes(32);
        File.WriteAllBytes(KeyFile, key);
        Restrict(KeyFile);
        return key;
    }

    private static void Restrict(string path)
    {
        try
        {
            if (!OperatingSystem.IsWindows())
                File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
        }
        catch { /* best effort */ }
    }

    public static void Save(string plaintext)
    {
        try
        {
            var key = GetKey();
            var nonce = RandomNumberGenerator.GetBytes(12);
            var pt = Encoding.UTF8.GetBytes(plaintext);
            var ct = new byte[pt.Length];
            var tag = new byte[16];
            using (var aes = new AesGcm(key, 16))
                aes.Encrypt(nonce, pt, ct, tag);

            var blob = new byte[nonce.Length + tag.Length + ct.Length];
            Buffer.BlockCopy(nonce, 0, blob, 0, nonce.Length);
            Buffer.BlockCopy(tag, 0, blob, nonce.Length, tag.Length);
            Buffer.BlockCopy(ct, 0, blob, nonce.Length + tag.Length, ct.Length);

            Directory.CreateDirectory(Dir);
            File.WriteAllBytes(DataFile, blob);
            Restrict(DataFile);
        }
        catch { /* non-fatal */ }
    }

    public static string? Load()
    {
        try
        {
            if (!File.Exists(DataFile)) return null;
            var key = GetKey();
            var blob = File.ReadAllBytes(DataFile);
            if (blob.Length < 28) return null;
            var nonce = new byte[12];
            var tag = new byte[16];
            var ct = new byte[blob.Length - 28];
            Buffer.BlockCopy(blob, 0, nonce, 0, 12);
            Buffer.BlockCopy(blob, 12, tag, 0, 16);
            Buffer.BlockCopy(blob, 28, ct, 0, ct.Length);
            var pt = new byte[ct.Length];
            using (var aes = new AesGcm(key, 16))
                aes.Decrypt(nonce, ct, tag, pt);
            return Encoding.UTF8.GetString(pt);
        }
        catch { return null; }
    }

    public static void Clear()
    {
        try { if (File.Exists(DataFile)) File.Delete(DataFile); } catch { }
    }
}
