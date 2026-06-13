using System;
using System.Collections.Generic;
using System.Linq;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Media.Imaging;

namespace OfflineMinecraftLauncher.Pages;

public partial class HomePage : UserControl
{
    private readonly LauncherCore _core;
    private bool _busy;

    public HomePage(LauncherCore core)
    {
        _core = core;
        InitializeComponent();

        UsernameBox.Text = string.IsNullOrWhiteSpace(Properties.Settings.Default.Username)
            ? Environment.UserName
            : Properties.Settings.Default.Username;

        _core.Status += s => Dispatcher.Invoke(() => ProgressStatus.Text = s);
        _core.FileProgress += (v, t) => Dispatcher.Invoke(() => { FileBar.Maximum = t <= 0 ? 100 : t; FileBar.Value = v; });
        _core.ByteProgress += p => Dispatcher.Invoke(() => ByteBar.Value = p);

        UpdateSkin();
    }

    public void ApplyConfig(ServerConfig cfg)
    {
        VersionText.Text = string.IsNullOrWhiteSpace(cfg.MinecraftVersion) ? "1.12.2" : cfg.MinecraftVersion;
        LoaderText.Text = string.IsNullOrWhiteSpace(cfg.LoaderType) ? "Forge" : cfg.LoaderType;
        UpdateSkin();
    }

    public void SetNews(int version, string note)
    {
        PackVersionText.Text = "v" + version;
        NewsText.Text = string.IsNullOrWhiteSpace(note) ? "Henüz sürüm notu yok." : note;
    }

    public void UpdateServerStatus(bool online, int players, int max, int latency, string? ip)
    {
        ServerIpText.Text = ip ?? "";
        if (online)
        {
            StatusDot.Fill = (Brush)Application.Current.Resources["SuccessBrush"];
            ServerStatusText.Text = "Çevrimiçi";
            PlayersText.Text = $"{players}/{max}";
            PingText.Text = $"Gecikme: {latency}ms";
        }
        else
        {
            StatusDot.Fill = (Brush)Application.Current.Resources["DangerBrush"];
            ServerStatusText.Text = "Çevrimdışı";
            PlayersText.Text = "—";
            PingText.Text = "Gecikme: —";
        }
    }

    private void UsernameBox_TextChanged(object sender, TextChangedEventArgs e)
    {
        Properties.Settings.Default.Username = UsernameBox.Text;
        Properties.Settings.Default.Save();
        UpdateSkin();
    }

    private void UsernameBox_KeyDown(object sender, System.Windows.Input.KeyEventArgs e)
    {
        if (e.Key == System.Windows.Input.Key.Enter && PlayButton.IsEnabled)
            PlayButton_Click(PlayButton, new RoutedEventArgs());
    }

    private void UpdateSkin()
    {
        try
        {
            string ver = string.IsNullOrWhiteSpace(_core.Config.MinecraftVersion) ? "1.12.2" : _core.Config.MinecraftVersion;
            string name = Character.GetCharacterResourceNameFromUsernameAndGameVersion(
                string.IsNullOrWhiteSpace(UsernameBox.Text) ? "Player" : UsernameBox.Text, ver);
            SkinImage.Source = new BitmapImage(new Uri($"pack://application:,,,/Resources/{name}.png"));
        }
        catch { }
    }

    private async void PlayButton_Click(object sender, RoutedEventArgs e)
    {
        if (_busy) return;
        if (string.IsNullOrWhiteSpace(UsernameBox.Text))
        {
            MessageBox.Show("Lütfen bir kullanıcı adı girin.", "Athena Studios Launcher", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        _busy = true;
        PlayButton.IsEnabled = false;
        UsernameBox.IsEnabled = false;
        try
        {
            // Seçili opsiyonel modları çöz
            var selected = new List<OptionalMod>();
            try
            {
                var ids = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                var saved = Properties.Settings.Default.EnabledOptionalMods;
                if (!string.IsNullOrWhiteSpace(saved))
                {
                    var arr = JsonSerializer.Deserialize<List<string>>(saved);
                    if (arr != null) foreach (var id in arr) if (!string.IsNullOrWhiteSpace(id)) ids.Add(id);
                }
                if (ids.Count > 0)
                {
                    var catalog = await _core.FetchOptionalModsAsync();
                    selected = catalog.Where(m => m != null && ids.Contains(m.Id)).ToList();
                }
            }
            catch { }

            await _core.LaunchAsync(UsernameBox.Text, selected);
        }
        catch (Exception ex)
        {
            App.LogCrash(ex);
            MessageBox.Show(ex.Message, "Hata", MessageBoxButton.OK, MessageBoxImage.Error);
        }
        finally
        {
            _busy = false;
            PlayButton.IsEnabled = true;
            UsernameBox.IsEnabled = true;
            ProgressStatus.Text = "Hazır";
        }
    }
}
