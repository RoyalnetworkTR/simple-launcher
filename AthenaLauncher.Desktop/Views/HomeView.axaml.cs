using System;
using System.Collections.Generic;
using System.Linq;
using System.Text.Json;
using System.Threading.Tasks;
using Avalonia.Controls;
using Avalonia.Interactivity;
using Avalonia.Threading;
using OfflineMinecraftLauncher.Services;

namespace OfflineMinecraftLauncher.Views;

public partial class HomeView : UserControl
{
    private readonly LauncherCore _core;
    private readonly LocalSettings _settings;
    private Account? _account;

    public event Action? ChangeAccountRequested;

    public HomeView(LauncherCore core, LocalSettings settings)
    {
        _core = core;
        _settings = settings;
        InitializeComponent();

        _core.Status += s => Ui(() => StatusText.Text = s);
        _core.FileProgress += (v, t) => Ui(() =>
        {
            FileBar.IsVisible = true;
            FileBar.Maximum = t <= 0 ? 100 : t;
            FileBar.Value = v;
        });
        _core.ByteProgress += p => Ui(() => { ByteBar.IsVisible = true; ByteBar.Value = p; });
        _core.GameExited += code => Ui(() =>
        {
            FileBar.IsVisible = false;
            ByteBar.IsVisible = false;
            PlayButton.IsEnabled = true;
            StatusText.Text = $"Oyun kapandı (kod {code}).";
        });
    }

    public void SetAccount(Account? acc)
    {
        _account = acc;
        AccountName.Text = acc?.Username ?? "(hesap seçilmedi)";
    }

    public async Task RefreshStatusAsync()
    {
        try
        {
            var (online, players, max, latency) = await _core.PingAsync();
            StatusLine.Text = online ? "🟢 Çevrimiçi" : "🔴 Çevrimdışı";
            PlayersLine.Text = online ? $"{players}/{max} oyuncu • {latency} ms" : "";
        }
        catch { }
    }

    private async void OnPlay(object? sender, RoutedEventArgs e)
    {
        if (_account == null) { ChangeAccountRequested?.Invoke(); return; }
        PlayButton.IsEnabled = false;
        try
        {
            var enabled = await ResolveEnabledModsAsync();
            await _core.LaunchAsync(_account, enabled);
        }
        catch (Exception ex)
        {
            StatusText.Text = "Hata: " + ex.Message;
            PlayButton.IsEnabled = true;
        }
    }

    private void OnChange(object? sender, RoutedEventArgs e) => ChangeAccountRequested?.Invoke();

    private async Task<List<OptionalMod>> ResolveEnabledModsAsync()
    {
        var ids = new HashSet<string>();
        try
        {
            if (!string.IsNullOrWhiteSpace(_settings.EnabledOptionalMods))
            {
                var arr = JsonSerializer.Deserialize<List<string>>(_settings.EnabledOptionalMods);
                if (arr != null) foreach (var i in arr) ids.Add(i);
            }
        }
        catch { }
        var all = await _core.FetchOptionalModsAsync();
        return all.Where(m => ids.Contains(m.Id)).ToList();
    }

    private static void Ui(Action a) => Dispatcher.UIThread.Post(a);
}
