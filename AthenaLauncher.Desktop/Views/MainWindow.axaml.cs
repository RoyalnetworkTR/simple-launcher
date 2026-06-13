using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Linq;
using System.Threading.Tasks;
using Avalonia.Controls;
using Avalonia.Interactivity;
using OfflineMinecraftLauncher.Services;

namespace OfflineMinecraftLauncher.Views;

public partial class MainWindow : Window
{
    private readonly LocalSettings _settings = LocalSettings.Load();
    private readonly AuthService _auth = new();
    private readonly LauncherCore _core;

    private Account? _selected;
    private List<Account> _accounts = new();

    private HomeView? _home;
    private AccountsView? _accountsView;
    private ModsView? _modsView;
    private SettingsView? _settingsView;
    private NewsView? _newsView;

    public MainWindow()
    {
        InitializeComponent();
        _core = new LauncherCore(_auth, _settings);
        Opened += async (_, _) => await InitAsync();
    }

    private async Task InitAsync()
    {
        SetLoading("Bağlanılıyor...");
        try
        {
            var (banned, reason) = await _core.CheckBanAsync();
            if (banned) { ShowBan(reason); return; }
            try { await _core.FetchConfigAsync(); } catch { }
            if (!_auth.HasValidSession) { ShowLogin(); return; }
            await LoadAccountsAndContinue();
        }
        catch (Exception e)
        {
            SetLoading("Hata: " + e.Message);
        }
    }

    private async Task LoadAccountsAndContinue()
    {
        SetLoading("Hesaplar yükleniyor...");
        _accounts = await _auth.GetAccountsAsync();
        _selected = _accounts.FirstOrDefault(a => a.Id == _settings.SelectedAccountId) ?? _accounts.FirstOrDefault();
        if (_selected != null) { _settings.SelectedAccountId = _selected.Id; _settings.Save(); }

        LoadingOverlay.IsVisible = false;
        LoginHost.IsVisible = false;
        BanOverlay.IsVisible = false;
        Shell.IsVisible = true;
        NavigateTo(_selected == null ? "accounts" : "home");
    }

    private void ShowLogin()
    {
        var login = new LoginView(_auth);
        login.LoggedIn += async () =>
        {
            LoginHost.IsVisible = false;
            SetLoading("Giriş yapıldı...");
            await LoadAccountsAndContinue();
        };
        LoginHost.Content = login;
        Shell.IsVisible = false;
        LoadingOverlay.IsVisible = false;
        BanOverlay.IsVisible = false;
        LoginHost.IsVisible = true;
    }

    private void ShowBan(string reason)
    {
        BanReason.Text = string.IsNullOrWhiteSpace(reason) ? "Bu cihaz yasaklandı." : reason;
        LoadingOverlay.IsVisible = false;
        Shell.IsVisible = false;
        LoginHost.IsVisible = false;
        BanOverlay.IsVisible = true;
    }

    private void SetLoading(string text)
    {
        LoadingText.Text = text;
        LoadingOverlay.IsVisible = true;
    }

    private void NavigateTo(string tag)
    {
        switch (tag)
        {
            case "home":
                _home ??= CreateHome();
                _home.SetAccount(_selected);
                _ = _home.RefreshStatusAsync();
                PageHost.Content = _home;
                break;
            case "accounts":
                _accountsView ??= CreateAccounts();
                _ = _accountsView.RefreshAsync();
                PageHost.Content = _accountsView;
                break;
            case "mods":
                _modsView ??= new ModsView(_core, _settings);
                _ = _modsView.RefreshAsync();
                PageHost.Content = _modsView;
                break;
            case "news":
                _newsView ??= new NewsView(_core);
                _ = _newsView.RefreshAsync();
                PageHost.Content = _newsView;
                break;
            case "settings":
                _settingsView ??= new SettingsView(_settings);
                PageHost.Content = _settingsView;
                break;
        }
        SetActiveNav(tag);
    }

    private HomeView CreateHome()
    {
        var h = new HomeView(_core, _settings);
        h.ChangeAccountRequested += () => NavigateTo("accounts");
        return h;
    }

    private AccountsView CreateAccounts()
    {
        var v = new AccountsView(_auth, _settings);
        v.AccountChosen += acc =>
        {
            _selected = acc;
            _settings.SelectedAccountId = acc.Id;
            _settings.Save();
            NavigateTo("home");
        };
        return v;
    }

    private void SetActiveNav(string tag)
    {
        foreach (var b in new[] { NavHome, NavAccounts, NavMods, NavNews, NavSettings })
            b.Classes.Remove("active");
        Button? active = tag switch
        {
            "home" => NavHome,
            "accounts" => NavAccounts,
            "mods" => NavMods,
            "news" => NavNews,
            "settings" => NavSettings,
            _ => null
        };
        active?.Classes.Add("active");
    }

    private void OnNav(object? sender, RoutedEventArgs e)
    {
        if (sender is Button b && b.Tag is string tag) NavigateTo(tag);
    }

    private void OnOpenDiscord(object? sender, RoutedEventArgs e)
    {
        try { Process.Start(new ProcessStartInfo { FileName = AppConfig.DiscordUrl, UseShellExecute = true }); }
        catch { }
    }

    private void OnLogout(object? sender, RoutedEventArgs e)
    {
        _auth.Logout();
        _selected = null;
        _home = null; _accountsView = null; _modsView = null; _newsView = null; _settingsView = null;
        ShowLogin();
    }
}
