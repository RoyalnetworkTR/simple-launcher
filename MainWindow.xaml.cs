using System;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media.Animation;
using System.Windows.Threading;
using OfflineMinecraftLauncher.Pages;

namespace OfflineMinecraftLauncher;

public partial class MainWindow : Window
{
    private readonly LauncherCore _core = new();
    private HomePage? _home;
    private ModsPage? _mods;
    private SettingsPage? _settings;
    private NewsPage? _news;
    private DispatcherTimer? _pingTimer;

    public MainWindow()
    {
        InitializeComponent();
        SidebarVersion.Text = "Launcher v" + LauncherCore.LauncherVersion;

        _core.GameStarted += () => Dispatcher.Invoke(() => { Hide(); });
        _core.GameExited += (code) => Dispatcher.Invoke(() => { Show(); Activate(); });

        Loaded += MainWindow_Loaded;
    }

    private async void MainWindow_Loaded(object sender, RoutedEventArgs e)
    {
        // Fade-in
        BeginAnimation(OpacityProperty, new DoubleAnimation(0, 1, TimeSpan.FromMilliseconds(350)));

        // Pages
        _home = new HomePage(_core);
        _mods = new ModsPage(_core);
        _settings = new SettingsPage(_core);
        _news = new NewsPage(_core);
        PageHost.Content = _home;

        // Ban check
        LoadingText.Text = "Erişim kontrol ediliyor…";
        var ban = await _core.CheckBanAsync();
        if (ban.banned)
        {
            BanReason.Text = "Sebep: " + ban.reason;
            BanOverlay.Visibility = Visibility.Visible;
            LoadingOverlay.Visibility = Visibility.Collapsed;
            return;
        }

        // Config
        LoadingText.Text = "Sunucu bilgileri alınıyor…";
        try { await _core.FetchConfigAsync(); } catch { }
        _home.ApplyConfig(_core.Config);

        // Version note
        try
        {
            var (ver, note) = await _core.FetchVersionAsync();
            _home.SetNews(ver, note);
        }
        catch { }

        // Ping timer
        _pingTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(5) };
        _pingTimer.Tick += async (s, a) => await RefreshPing();
        _pingTimer.Start();
        await RefreshPing();

        // Hide loading overlay (fade)
        var fade = new DoubleAnimation(1, 0, TimeSpan.FromMilliseconds(400));
        fade.Completed += (s, a) => LoadingOverlay.Visibility = Visibility.Collapsed;
        LoadingOverlay.BeginAnimation(OpacityProperty, fade);
    }

    private async System.Threading.Tasks.Task RefreshPing()
    {
        var p = await _core.PingAsync();
        if (p.online)
        {
            SidebarStatus.Text = $"🟢 Çevrimiçi  {p.players}/{p.max}  ·  {p.latency}ms";
            _home?.UpdateServerStatus(true, p.players, p.max, p.latency, _core.Config.ServerIp);
        }
        else
        {
            SidebarStatus.Text = "🔴 Sunucuya ulaşılamıyor";
            _home?.UpdateServerStatus(false, 0, 0, 0, _core.Config.ServerIp);
        }
    }

    private void Nav_Checked(object sender, RoutedEventArgs e)
    {
        if (PageHost == null) return;
        if (sender is not RadioButton rb || rb.Tag is not string tag) return;
        UserControl? page = tag switch
        {
            "home" => _home,
            "mods" => _mods,
            "settings" => _settings,
            "news" => _news,
            _ => _home
        };
        if (page == null) return;

        if (tag == "mods") _mods?.Reload();
        if (tag == "news") _news?.Reload();

        PageHost.Content = page;
        page.BeginAnimation(OpacityProperty, new DoubleAnimation(0, 1, TimeSpan.FromMilliseconds(250)));
    }

    private void BtnMinimize_Click(object sender, RoutedEventArgs e) => WindowState = WindowState.Minimized;
    private void BtnClose_Click(object sender, RoutedEventArgs e) => Close();

    private void Discord_Click(object sender, RoutedEventArgs e) => OpenUrl(AppConfig.DiscordUrl);
    private void Website_Click(object sender, RoutedEventArgs e) => OpenUrl(AppConfig.WebsiteUrl);

    private static void OpenUrl(string url)
    {
        try { System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(url) { UseShellExecute = true }); }
        catch { }
    }
}
