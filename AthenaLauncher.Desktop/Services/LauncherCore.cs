using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Threading.Tasks;
using CmlLib.Core;
using CmlLib.Core.Auth;
using CmlLib.Core.ProcessBuilder;
using CmlLib.Core.Installer.Forge;
using CmlLib.Core.ModLoaders.FabricMC;
using CmlLib.Core.Installer.NeoForge;

namespace OfflineMinecraftLauncher.Services;

/// <summary>
/// Cross-platform (Windows + Linux) launcher core. Reuses the CmlLib install/launch
/// flow from the WPF version, but replaces WMI/WPF dependencies with PlatformInfo
/// and authenticates the play session through the Athena backend (Discord login +
/// per-account join token written for the AthenaCore mod).
/// </summary>
public class LauncherCore
{
    private readonly MinecraftLauncher _launcher;
    private readonly MinecraftPath _launcherPath;
    private readonly AuthService _auth;
    private readonly LocalSettings _settings;

    public PackageManager Package { get; }
    public ServerConfig Config { get; private set; } = new ServerConfig();
    public string PackageDir { get; }
    public const string LauncherVersion = "2.0.0";

    public event Action<string>? Status;
    public event Action<int, int>? FileProgress;
    public event Action<int>? ByteProgress;
    public event Action<string>? LogLine;
    public event Action? GameStarted;
    public event Action<int>? GameExited;

    public LauncherCore(AuthService auth, LocalSettings settings)
    {
        _auth = auth;
        _settings = settings;

        var appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
        PackageDir = Path.Combine(appData, AppConfig.AppDataFolder);

        _launcherPath = new MinecraftPath(PackageDir);
        _launcherPath.CreateDirs();
        _launcher = new MinecraftLauncher(_launcherPath);

        _launcher.FileProgressChanged += (s, a) => FileProgress?.Invoke(a.ProgressedTasks, a.TotalTasks);
        _launcher.ByteProgressChanged += (s, a) =>
        {
            long max = a.TotalBytes / 1024 / 1024;
            long val = a.ProgressedBytes / 1024 / 1024;
            if (max > 0) ByteProgress?.Invoke((int)((double)val / max * 100));
        };

        Package = new PackageManager(AppConfig.BackendUrl, PackageDir);
        Package.StatusChanged += (s, msg) => Status?.Invoke(msg);
        Package.ProgressChanged += (s, pct) => FileProgress?.Invoke(pct, 100);
    }

    // --------------------------------------------------------------- Ban
    public async Task<(bool banned, string reason)> CheckBanAsync()
    {
        try
        {
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(6) };
            var res = await client.GetStringAsync($"{AppConfig.BackendUrl}/api/check_ban?hwid={PlatformInfo.GetHwid()}");
            using var doc = JsonDocument.Parse(res);
            if (doc.RootElement.TryGetProperty("banned", out var b) && b.GetBoolean())
            {
                string reason = doc.RootElement.TryGetProperty("reason", out var r) && r.ValueKind == JsonValueKind.String
                    ? (r.GetString() ?? "HWID Ban.") : "HWID Ban.";
                return (true, reason);
            }
        }
        catch { }
        return (false, "");
    }

    // --------------------------------------------------------------- Config
    public async Task<ServerConfig> FetchConfigAsync()
    {
        Config = await Package.FetchConfigAsync();
        if (string.IsNullOrWhiteSpace(Config.ServerIp)) Config.ServerIp = "oyna.athenastudios.com.tr";
        if (string.IsNullOrWhiteSpace(Config.MinecraftVersion)) Config.MinecraftVersion = "1.12.2";
        if (string.IsNullOrWhiteSpace(Config.LoaderType)) Config.LoaderType = "Forge";
        Config.LoaderVersion ??= "";
        return Config;
    }

    // --------------------------------------------------------------- Ping
    public async Task<(bool online, int players, int max, int latency)> PingAsync()
    {
        if (string.IsNullOrEmpty(Config.ServerIp)) return (false, 0, 0, 0);
        try
        {
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(4) };
            var res = await client.GetStringAsync($"{AppConfig.BackendUrl}/api/ping?ip={Config.ServerIp}");
            using var doc = JsonDocument.Parse(res);
            var root = doc.RootElement;
            if (root.GetProperty("status").GetString() == "online")
                return (true,
                    root.GetProperty("players_online").GetInt32(),
                    root.GetProperty("players_max").GetInt32(),
                    root.GetProperty("latency").GetInt32());
        }
        catch { }
        return (false, 0, 0, 0);
    }

    // --------------------------------------------------------------- Version / news
    public async Task<(int version, string note)> FetchVersionAsync()
    {
        try
        {
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
            var json = await client.GetStringAsync($"{AppConfig.BackendUrl}/api/version.json");
            using var doc = JsonDocument.Parse(json);
            var root = doc.RootElement;
            int ver = root.TryGetProperty("Version", out var v) && v.ValueKind == JsonValueKind.Number && v.TryGetInt32(out var vi) ? vi : 0;
            string note = root.TryGetProperty("Note", out var n) && n.ValueKind == JsonValueKind.String ? (n.GetString() ?? "") : "";
            return (ver, note);
        }
        catch { return (0, ""); }
    }

    public async Task<List<ChangelogEntry>> FetchChangelogAsync()
    {
        var list = new List<ChangelogEntry>();
        try
        {
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(6) };
            var json = await client.GetStringAsync($"{AppConfig.BackendUrl}/api/changelog.json");
            using var doc = JsonDocument.Parse(json);
            if (doc.RootElement.ValueKind == JsonValueKind.Array)
                foreach (var e in doc.RootElement.EnumerateArray())
                    list.Add(new ChangelogEntry
                    {
                        Version = e.TryGetProperty("Version", out var v) && v.ValueKind == JsonValueKind.Number ? v.GetInt32() : 0,
                        Timestamp = e.TryGetProperty("Timestamp", out var t) ? (t.GetString() ?? "") : "",
                        Note = e.TryGetProperty("Note", out var n) ? (n.GetString() ?? "") : "",
                    });
        }
        catch { }
        return list;
    }

    public Task<List<OptionalMod>> FetchOptionalModsAsync() => Package.FetchOptionalModsAsync();

    // --------------------------------------------------------------- Launch
    public async Task LaunchAsync(Account account, IEnumerable<OptionalMod> selectedOptional)
    {
        // 1) Sync required + selected optional files (SHA-256 verified).
        await Package.SyncPackageAsync(selectedOptional);

        int maxRam = _settings.MaxRamMb > 0 ? _settings.MaxRamMb : Config.MaxRamMb;
        int minRam = _settings.MinRamMb > 0 ? _settings.MinRamMb : Config.MinRamMb;

        // 2) Java resolution: user setting > packaged runtime > null (CmlLib resolves).
        string javaExe = OperatingSystem.IsWindows() ? "javaw.exe" : "java";
        string? javaPath = null;
        if (!string.IsNullOrWhiteSpace(_settings.JavaPath))
            javaPath = _settings.JavaPath;
        else
        {
            try
            {
                string runtimeDir = Path.Combine(_launcherPath.BasePath, "runtime");
                if (Directory.Exists(runtimeDir))
                {
                    var match = Directory.GetDirectories(runtimeDir)
                        .Select(d => Path.Combine(d, "bin", javaExe))
                        .FirstOrDefault(File.Exists);
                    if (match != null) javaPath = match;
                }
            }
            catch { }
        }

        long totalRamMb = PlatformInfo.GetTotalRamMb();
        if (totalRamMb > 2048 && maxRam > totalRamMb - 1536)
            maxRam = (int)(totalRamMb - 1536);
        if (maxRam < 1024) maxRam = 1024;
        if (minRam > maxRam) minRam = maxRam;

        await SendMetricAsync(account.Username, maxRam, minRam, javaPath, totalRamMb);

        // 3) Mod loader install.
        string versionToRun = Config.MinecraftVersion;
        if (Config.LoaderType.Equals("Forge", StringComparison.OrdinalIgnoreCase))
        {
            Status?.Invoke("Forge kuruluyor...");
            var fi = new ForgeInstaller(_launcher);
            versionToRun = string.IsNullOrWhiteSpace(Config.LoaderVersion)
                ? await fi.Install(Config.MinecraftVersion)
                : await fi.Install(Config.MinecraftVersion, Config.LoaderVersion);
        }
        else if (Config.LoaderType.Equals("Fabric", StringComparison.OrdinalIgnoreCase))
        {
            Status?.Invoke("Fabric kuruluyor...");
            var fi = new FabricInstaller(new HttpClient());
            versionToRun = string.IsNullOrWhiteSpace(Config.LoaderVersion)
                ? await fi.Install(Config.MinecraftVersion, _launcherPath)
                : await fi.Install(Config.MinecraftVersion, Config.LoaderVersion, _launcherPath);
        }
        else if (Config.LoaderType.Equals("NeoForge", StringComparison.OrdinalIgnoreCase))
        {
            Status?.Invoke("NeoForge kuruluyor...");
            var ni = new NeoForgeInstaller(_launcher);
            versionToRun = string.IsNullOrWhiteSpace(Config.LoaderVersion)
                ? await ni.Install(Config.MinecraftVersion)
                : await ni.Install(Config.MinecraftVersion, Config.LoaderVersion);
        }

        Status?.Invoke("Oyun dosyaları indiriliyor...");
        await _launcher.InstallAsync(versionToRun);

        // 4) Mint the join token LAST (after downloads) so its short TTL doesn't
        //    expire before the client connects. AthenaCore reads this file on join.
        Status?.Invoke("Oturum hazırlanıyor...");
        bool joinReady = await _auth.PrepareJoinAsync(account.Id, PackageDir);
        if (!joinReady)
            Status?.Invoke("Uyarı: oturum doğrulaması hazırlanamadı (giriş gerekebilir).");

        // Register the Athena server in the in-game multiplayer list (servers.dat),
        // independent of auto-connect, so players can always find it.
        ServersDat.WriteOrMerge(PackageDir, "Athena Studios", Config.ServerIp);

        // Auto-join only when BOTH the backend allows it and the user hasn't
        // disabled it in launcher settings.
        bool autoJoin = Config.AutoConnect && _settings.AutoConnectOnLaunch;
        var launchOption = new MLaunchOption
        {
            Session = MSession.CreateOfflineSession(account.Username),
            MaximumRamMb = maxRam,
            MinimumRamMb = minRam,
            ServerIp = autoJoin ? Config.ServerIp : null
        };
        if (!string.IsNullOrEmpty(javaPath)) launchOption.JavaPath = javaPath;
        if (!string.IsNullOrWhiteSpace(_settings.JvmArguments))
            launchOption.ExtraJvmArguments = _settings.JvmArguments
                .Split(' ', StringSplitOptions.RemoveEmptyEntries).Select(a => new MArgument(a)).ToList();

        var process = await _launcher.BuildProcessAsync(versionToRun, launchOption);

        var psi = new ProcessStartInfo
        {
            FileName = process.StartInfo.FileName,
            Arguments = process.StartInfo.Arguments,
            WorkingDirectory = process.StartInfo.WorkingDirectory,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true
        };

        var mc = new Process { StartInfo = psi, EnableRaisingEvents = true };
        mc.OutputDataReceived += (s, e) => { if (e.Data != null) LogLine?.Invoke(e.Data); };
        mc.ErrorDataReceived += (s, e) => { if (e.Data != null) LogLine?.Invoke(e.Data); };
        mc.Start();
        mc.BeginOutputReadLine();
        mc.BeginErrorReadLine();

        Status?.Invoke("Oyun çalışıyor...");
        GameStarted?.Invoke();
        await mc.WaitForExitAsync();
        GameExited?.Invoke(mc.ExitCode);
        Status?.Invoke("Hazır");
    }

    private async Task SendMetricAsync(string username, int maxRam, int minRam, string? javaPath, long totalRamMb)
    {
        try
        {
            string diskFree = "?", diskTotal = "?";
            try
            {
                var d = new DriveInfo(Path.GetPathRoot(PackageDir) ?? "/");
                diskFree = (d.AvailableFreeSpace / 1073741824) + "GB";
                diskTotal = (d.TotalSize / 1073741824) + "GB";
            }
            catch { }

            var qs = string.Join("&", new[]
            {
                $"uuid={Guid.NewGuid()}",
                $"username={Uri.EscapeDataString(username)}",
                $"os={Uri.EscapeDataString(RuntimeInformation.OSDescription)}",
                $"os_arch={RuntimeInformation.OSArchitecture}",
                $"dotnet={Uri.EscapeDataString(RuntimeInformation.FrameworkDescription)}",
                $"ram_total={totalRamMb}",
                $"ram_max={maxRam}",
                $"ram_min={minRam}",
                $"resolution=unknown",
                $"mc_version={Uri.EscapeDataString(Config.MinecraftVersion)}",
                $"loader_type={Uri.EscapeDataString(Config.LoaderType)}",
                $"loader_version={Uri.EscapeDataString(Config.LoaderVersion ?? "")}",
                $"launcher_version={LauncherVersion}",
                $"cpu={Uri.EscapeDataString(PlatformInfo.GetCpu())}",
                $"gpu=unknown",
                $"java_path={Uri.EscapeDataString(javaPath ?? "default")}",
                $"machine_name={Uri.EscapeDataString(Environment.MachineName)}",
                $"cpu_cores={Environment.ProcessorCount}",
                $"gpu_ram=unknown",
                $"disk_total={Uri.EscapeDataString(diskTotal)}",
                $"disk_free={Uri.EscapeDataString(diskFree)}",
                $"locale={Uri.EscapeDataString(System.Globalization.CultureInfo.CurrentCulture.Name)}",
                $"motherboard=unknown",
                $"is_64bit_process={(Environment.Is64BitProcess ? "64-bit" : "32-bit")}",
                $"hwid={Uri.EscapeDataString(PlatformInfo.GetHwid())}"
            });
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(6) };
            await client.PostAsync($"{AppConfig.BackendUrl}/api/metric/play?{qs}", null);
        }
        catch { }
    }
}

public class ChangelogEntry
{
    public int Version { get; set; }
    public string Timestamp { get; set; } = "";
    public string Note { get; set; } = "";
}
