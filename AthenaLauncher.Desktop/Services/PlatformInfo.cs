using System;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;

namespace OfflineMinecraftLauncher.Services;

/// <summary>Cross-platform machine info (replaces the Windows-only WMI calls).</summary>
public static class PlatformInfo
{
    /// <summary>
    /// Stable per-machine (or per-install) hardware id, MD5-hex like the legacy
    /// launcher so the backend's HWID ban list keeps working. Uses /etc/machine-id
    /// on Linux and a persisted GUID file otherwise.
    /// </summary>
    public static string GetHwid()
    {
        try
        {
            string? raw = null;
            foreach (var p in new[] { "/etc/machine-id", "/var/lib/dbus/machine-id" })
            {
                if (File.Exists(p))
                {
                    var v = File.ReadAllText(p).Trim();
                    if (!string.IsNullOrWhiteSpace(v)) { raw = v; break; }
                }
            }
            if (string.IsNullOrWhiteSpace(raw))
                raw = PersistedMachineGuid();

            using var md5 = MD5.Create();
            return Convert.ToHexString(md5.ComputeHash(Encoding.ASCII.GetBytes(raw!))).ToLowerInvariant();
        }
        catch { return "UNKNOWN_HWID"; }
    }

    private static string PersistedMachineGuid()
    {
        var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), AppConfig.AppDataFolder);
        Directory.CreateDirectory(dir);
        var f = Path.Combine(dir, "machine.id");
        if (File.Exists(f))
        {
            var v = File.ReadAllText(f).Trim();
            if (!string.IsNullOrWhiteSpace(v)) return v;
        }
        var g = Guid.NewGuid().ToString("N");
        File.WriteAllText(f, g);
        return g;
    }

    public static long GetTotalRamMb()
    {
        try
        {
            if (OperatingSystem.IsLinux() && File.Exists("/proc/meminfo"))
            {
                foreach (var line in File.ReadLines("/proc/meminfo"))
                {
                    if (line.StartsWith("MemTotal:", StringComparison.Ordinal))
                    {
                        var parts = line.Split(new[] { ' ', '\t' }, StringSplitOptions.RemoveEmptyEntries);
                        if (parts.Length >= 2 && long.TryParse(parts[1], out var kb))
                            return kb / 1024;
                    }
                }
            }
            var info = GC.GetGCMemoryInfo();
            if (info.TotalAvailableMemoryBytes > 0)
                return info.TotalAvailableMemoryBytes / 1024 / 1024;
        }
        catch { }
        return 0;
    }

    public static string GetCpu()
    {
        try
        {
            if (OperatingSystem.IsLinux() && File.Exists("/proc/cpuinfo"))
            {
                var model = File.ReadLines("/proc/cpuinfo")
                    .FirstOrDefault(l => l.StartsWith("model name", StringComparison.OrdinalIgnoreCase));
                if (model != null)
                {
                    var idx = model.IndexOf(':');
                    if (idx >= 0) return model[(idx + 1)..].Trim();
                }
            }
        }
        catch { }
        // Reasonable cross-platform fallback.
        var arch = RuntimeInformation.ProcessArchitecture.ToString();
        return $"{arch} ({Environment.ProcessorCount} cores)";
    }
}
