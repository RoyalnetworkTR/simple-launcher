using CmlLib.Core;
using CmlLib.Core.Auth;
using CmlLib.Core.Installers;
using CmlLib.Core.ProcessBuilder;
using CmlLib.Core.VersionMetadata;
using CmlLib.Core.Installer.Forge;
using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Net.Http;
using System.Windows.Forms;
using System.Text.Json;
using System.Linq;
using System.Management;
using System.Runtime.InteropServices;
using CmlLib.Core.ModLoaders.FabricMC;
using CmlLib.Core.Installer.NeoForge;

namespace OfflineMinecraftLauncher;

public partial class LauncherForm : Form
{
    private readonly MinecraftLauncher _launcher;
    private MinecraftPath _launcherPath;
    private PackageManager _packageManager;
    private ServerConfig _serverConfig = new ServerConfig();
    private System.Windows.Forms.Timer _pingTimer;
    private System.Windows.Forms.Timer _fadeTimer;

    public LauncherForm()
    {
        var appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
        string packageDir = Path.Combine(appData, ".Royalnetwork");
        
        _launcherPath = new MinecraftPath(packageDir);
        _launcherPath.CreateDirs();
        _launcher = new MinecraftLauncher(_launcherPath);

        // Paralel indirme: 8 checker + 8 downloader = çok daha hızlı
        _launcher.GameInstaller = new ParallelGameInstaller(8, 8, 32, new HttpClient());

        _launcher.FileProgressChanged += _launcher_FileProgressChanged;
        _launcher.ByteProgressChanged += _launcher_ByteProgressChanged;

        _packageManager = new PackageManager("http://node-kyb.bariskeser.com:10230", packageDir);
        _packageManager.StatusChanged += (s, msg) => Invoke(new Action(() => lbProgress.Text = msg));
        _packageManager.ProgressChanged += (s, pct) => Invoke(new Action(() => pbFiles.Value = pct));

        InitializeComponent();

        // Boot fade-in animation
        this.Opacity = 0;
        _fadeTimer = new System.Windows.Forms.Timer();
        _fadeTimer.Interval = 20;
        _fadeTimer.Tick += (s, e) => {
            if (this.Opacity >= 1) {
                _fadeTimer.Stop();
            } else {
                this.Opacity += 0.05;
            }
        };
        _fadeTimer.Start();

        // Periodic server ping (every 5 seconds)
        _pingTimer = new System.Windows.Forms.Timer();
        _pingTimer.Interval = 5000;
        _pingTimer.Tick += async (s, e) => await CheckServerPing();
    }

    private async void LauncherForm_Load(object sender, EventArgs e)
    {
        usernameInput.Text = Properties.Settings.Default.Username;
        if (string.IsNullOrEmpty(usernameInput.Text))
            usernameInput.Text = Environment.UserName;

        try
        {
            _serverConfig = await _packageManager.FetchConfigAsync();
            lblStatus.Text = $"Durum: Aktif ({_serverConfig.ServerIp})";
            lblStatus.ForeColor = Color.Green;
            
            _pingTimer.Start();
            await CheckServerPing();
        }
        catch
        {
            lblStatus.Text = "Durum: Çevrimdışı";
            lblStatus.ForeColor = Color.Red;
            _serverConfig = new ServerConfig { ServerIp = "oyna.royalnetwork.xyz", AutoConnect = true, MinecraftVersion="1.20.1", LoaderType="Vanilla", MaxRamMb=4096, MinRamMb=1024 };
        }
    }

    private async System.Threading.Tasks.Task CheckServerPing()
    {
        if (string.IsNullOrEmpty(_serverConfig.ServerIp)) return;
        try {
            using (var client = new HttpClient())
            {
                client.Timeout = TimeSpan.FromSeconds(4);
                var response = await client.GetStringAsync($"http://node-kyb.bariskeser.com:10230/api/ping?ip={_serverConfig.ServerIp}");
                using (JsonDocument doc = JsonDocument.Parse(response))
                {
                    var root = doc.RootElement;
                    if (root.GetProperty("status").GetString() == "online")
                    {
                        var online = root.GetProperty("players_online");
                        var max = root.GetProperty("players_max");
                        var ms = root.GetProperty("latency");
                        lblStatus.Text = $"Durum: Aktif ({online}/{max}) - {ms}ms";
                        lblStatus.ForeColor = Color.Green;
                    } else {
                        lblStatus.Text = "Durum: Kapalı";
                        lblStatus.ForeColor = Color.Red;
                    }
                }
            }
        } catch { 
            lblStatus.Text = "Durum: Ulaşılamıyor";
            lblStatus.ForeColor = Color.Orange;
        }
    }

    private void usernameInput_TextChanged(object sender, EventArgs e)
    {
        Properties.Settings.Default.Username = usernameInput.Text;
        Properties.Settings.Default.Save();
    }

    private void btnSettings_Click(object sender, EventArgs e)
    {
        var sf = new SettingsForm();
        sf.ShowDialog();
    }

    private async void btnStart_Click(object sender, EventArgs e)
    {
        if (string.IsNullOrWhiteSpace(usernameInput.Text))
        {
            MessageBox.Show("Lütfen bir kullanıcı adı girin.");
            return;
        }

        btnStart.Enabled = false;
        usernameInput.Enabled = false;
        btnSettings.Enabled = false;

        try
        {
            // Sync Package
            await _packageManager.SyncPackageAsync();

            int maxRam = Properties.Settings.Default.MaxRamMb > 0 ? Properties.Settings.Default.MaxRamMb : _serverConfig.MaxRamMb;
            int minRam = Properties.Settings.Default.MinRamMb > 0 ? Properties.Settings.Default.MinRamMb : _serverConfig.MinRamMb;

            // Resolve Java path: user setting > bundled JRE > system default
            string? javaPath = null;
            if (!string.IsNullOrWhiteSpace(Properties.Settings.Default.JavaPath))
                javaPath = Properties.Settings.Default.JavaPath;
            else if (File.Exists(Path.Combine(_launcherPath.BasePath, "runtime", "java-runtime-gamma", "bin", "javaw.exe")))
                javaPath = Path.Combine(_launcherPath.BasePath, "runtime", "java-runtime-gamma", "bin", "javaw.exe");

            // Track Metric — mümkün olan tüm bilgileri gönder
            try {
                using (var client = new HttpClient())
                {
                    var screenBounds = Screen.PrimaryScreen?.Bounds;
                    string resolution = screenBounds != null ? $"{screenBounds.Value.Width}x{screenBounds.Value.Height}" : "unknown";
                    long totalRamMb = 0;
                    try {
                        var ci = new Microsoft.VisualBasic.Devices.ComputerInfo();
                        totalRamMb = (long)(ci.TotalPhysicalMemory / 1024 / 1024);
                    } catch { }

                    string cpuName = "unknown";
                    try {
                        using var searcher = new ManagementObjectSearcher("select Name from Win32_Processor");
                        foreach (var item in searcher.Get()) { cpuName = item["Name"]?.ToString() ?? "unknown"; break; }
                    } catch { }

                    string gpuName = "unknown";
                    try {
                        using var searcher = new ManagementObjectSearcher("select Name from Win32_VideoController");
                        foreach (var item in searcher.Get()) { gpuName = item["Name"]?.ToString() ?? "unknown"; break; }
                    } catch { }

                    var qs = string.Join("&", new[] {
                        $"uuid={Guid.NewGuid()}",
                        $"username={Uri.EscapeDataString(usernameInput.Text)}",
                        $"os={Uri.EscapeDataString(RuntimeInformation.OSDescription)}",
                        $"os_arch={RuntimeInformation.OSArchitecture}",
                        $"dotnet={Uri.EscapeDataString(RuntimeInformation.FrameworkDescription)}",
                        $"ram_total={totalRamMb}",
                        $"ram_max={maxRam}",
                        $"ram_min={minRam}",
                        $"resolution={Uri.EscapeDataString(resolution)}",
                        $"mc_version={Uri.EscapeDataString(_serverConfig.MinecraftVersion)}",
                        $"loader_type={Uri.EscapeDataString(_serverConfig.LoaderType)}",
                        $"loader_version={Uri.EscapeDataString(_serverConfig.LoaderVersion ?? "")}",
                        $"launcher_version=1.0.0",
                        $"cpu={Uri.EscapeDataString(cpuName)}",
                        $"gpu={Uri.EscapeDataString(gpuName)}",
                        $"java_path={Uri.EscapeDataString(javaPath ?? "default")}"
                    });
                    await client.PostAsync($"http://node-kyb.bariskeser.com:10230/api/metric/play?{qs}", null);
                }
            } catch { }

            string versionToRun = _serverConfig.MinecraftVersion;

            // Mod Loader installation
            if (_serverConfig.LoaderType.Equals("Forge", StringComparison.OrdinalIgnoreCase))
            {
                lbProgress.Text = "Forge kuruluyor...";
                var forgeInstaller = new ForgeInstaller(_launcher);

                if (!string.IsNullOrWhiteSpace(_serverConfig.LoaderVersion))
                    versionToRun = await forgeInstaller.Install(_serverConfig.MinecraftVersion, _serverConfig.LoaderVersion);
                else
                    versionToRun = await forgeInstaller.Install(_serverConfig.MinecraftVersion);
            }
            else if (_serverConfig.LoaderType.Equals("Fabric", StringComparison.OrdinalIgnoreCase))
            {
                lbProgress.Text = "Fabric kuruluyor...";
                var fabricInstaller = new FabricInstaller(new HttpClient());
                
                if (!string.IsNullOrWhiteSpace(_serverConfig.LoaderVersion))
                    versionToRun = await fabricInstaller.Install(_serverConfig.MinecraftVersion, _serverConfig.LoaderVersion, _launcherPath);
                else
                    versionToRun = await fabricInstaller.Install(_serverConfig.MinecraftVersion, _launcherPath);
            }
            else if (_serverConfig.LoaderType.Equals("NeoForge", StringComparison.OrdinalIgnoreCase))
            {
                lbProgress.Text = "NeoForge kuruluyor...";
                var neoInstaller = new NeoForgeInstaller(_launcher);
                
                if (!string.IsNullOrWhiteSpace(_serverConfig.LoaderVersion))
                    versionToRun = await neoInstaller.Install(_serverConfig.MinecraftVersion, _serverConfig.LoaderVersion);
                else
                    versionToRun = await neoInstaller.Install(_serverConfig.MinecraftVersion);
            }

            var launchOption = new MLaunchOption
            {
                Session = MSession.CreateOfflineSession(usernameInput.Text),
                MaximumRamMb = maxRam,
                MinimumRamMb = minRam,
                ServerIp = _serverConfig.AutoConnect ? _serverConfig.ServerIp : null
            };

            if (!string.IsNullOrEmpty(javaPath))
                launchOption.JavaPath = javaPath;

            if (!string.IsNullOrWhiteSpace(Properties.Settings.Default.JvmArguments))
            {
                var customArgs = Properties.Settings.Default.JvmArguments.Split(new[] { ' ' }, StringSplitOptions.RemoveEmptyEntries);
                launchOption.ExtraJvmArguments = customArgs.Select(a => new MArgument(a)).ToList();
            }

            // Oyun dosyalarını indir (assets, libraries, client.jar)
            lbProgress.Text = "Oyun dosyaları indiriliyor...";
            await _launcher.InstallAsync(versionToRun);

            var process = await _launcher.BuildProcessAsync(versionToRun, launchOption);

            var processStartInfo = new ProcessStartInfo
            {
                FileName = process.StartInfo.FileName,
                Arguments = process.StartInfo.Arguments,
                WorkingDirectory = process.StartInfo.WorkingDirectory,
                UseShellExecute = false
            };

            var mcProcess = Process.Start(processStartInfo);
            if (mcProcess != null)
            {
                mcProcess.EnableRaisingEvents = true;
                this.Hide();
                await mcProcess.WaitForExitAsync();
            }
            this.Show();
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Hata", MessageBoxButtons.OK, MessageBoxIcon.Error);
            this.Show();
        }
        finally
        {
            btnStart.Enabled = true;
            usernameInput.Enabled = true;
            btnSettings.Enabled = true;
            lbProgress.Text = "Hazır";
        }
    }

    private void _launcher_FileProgressChanged(object? sender, InstallerProgressChangedEventArgs args)
    {
        if (InvokeRequired)
        {
            Invoke(new Action(() => _launcher_FileProgressChanged(sender, args)));
            return;
        }

        pbFiles.Maximum = args.TotalTasks;
        pbFiles.Value = args.ProgressedTasks;
        lbProgress.Text = $"{args.Name} - {args.ProgressedTasks}/{args.TotalTasks}";
    }

    private void _launcher_ByteProgressChanged(object? sender, ByteProgress args)
    {
        if (InvokeRequired)
        {
            Invoke(new Action(() => _launcher_ByteProgressChanged(sender, args)));
            return;
        }

        long max = args.TotalBytes / 1024 / 1024;
        long val = args.ProgressedBytes / 1024 / 1024;
        if (max > 0)
        {
            pbProgress.Maximum = 100;
            pbProgress.Value = (int)((double)val / max * 100);
        }
    }
}
