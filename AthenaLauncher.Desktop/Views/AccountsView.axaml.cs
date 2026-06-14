using System;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using Avalonia;
using Avalonia.Controls;
using Avalonia.Interactivity;
using Avalonia.Layout;
using Avalonia.Media;
using Avalonia.Platform.Storage;
using OfflineMinecraftLauncher.Services;

namespace OfflineMinecraftLauncher.Views;

public partial class AccountsView : UserControl
{
    private readonly AuthService _auth;
    private readonly LocalSettings _settings;
    private const int Cap = 5;

    public event Action<Account>? AccountChosen;

    public AccountsView(AuthService auth, LocalSettings settings)
    {
        _auth = auth;
        _settings = settings;
        InitializeComponent();
    }

    public async Task RefreshAsync()
    {
        AccountList.Children.Clear();
        Empty.IsVisible = true;
        Empty.Text = "Yükleniyor...";

        var accounts = await _auth.GetAccountsAsync();
        CapLabel.Text = $"{accounts.Count} / {Cap} hesap";
        AddButton.IsEnabled = accounts.Count < Cap;

        if (accounts.Count == 0)
        {
            Empty.Text = "Henüz hesabın yok. Yukarıdan bir tane ekle.";
            return;
        }
        Empty.IsVisible = false;
        foreach (var a in accounts) AccountList.Children.Add(BuildRow(a));
    }

    private Control BuildRow(Account a)
    {
        bool selected = a.Id == _settings.SelectedAccountId;

        var grid = new Grid { ColumnDefinitions = new ColumnDefinitions("*,Auto") };

        var left = new StackPanel { Spacing = 2, VerticalAlignment = VerticalAlignment.Center };
        var nameLine = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        nameLine.Children.Add(new TextBlock { Text = a.Username, FontWeight = FontWeight.SemiBold, FontSize = 15 });
        if (selected)
            nameLine.Children.Add(new TextBlock { Text = "• seçili", Classes = { "muted" }, VerticalAlignment = VerticalAlignment.Center });
        if (a.IsBanned)
            nameLine.Children.Add(new TextBlock { Text = "• BANLI", Foreground = Res("DangerBrush"), VerticalAlignment = VerticalAlignment.Center });
        left.Children.Add(nameLine);
        left.Children.Add(new TextBlock
        {
            Text = string.IsNullOrEmpty(a.SkinHash) ? "skin: varsayılan" : "skin: özel ✓",
            Classes = { "muted" },
            FontSize = 11
        });
        Grid.SetColumn(left, 0);
        grid.Children.Add(left);

        var btns = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 8 };
        var selBtn = new Button { Classes = { "ghost" }, Content = selected ? "Seçili" : "Seç" };
        selBtn.Click += (_, _) => AccountChosen?.Invoke(a);
        var skinBtn = new Button { Classes = { "ghost" }, Content = "Skin Yükle" };
        skinBtn.Click += async (_, _) => await UploadSkinAsync(a);
        // Hesap silme kaldırıldı: kullanıcılar 5 isim haklarını silemez; yalnız
        // yöneticiler web panelinden siler (backend de DELETE /api/accounts'u 403'ler).
        btns.Children.Add(selBtn);
        btns.Children.Add(skinBtn);
        Grid.SetColumn(btns, 1);
        grid.Children.Add(btns);

        return new Border
        {
            Classes = { "card" },
            Child = grid,
            BorderBrush = selected ? Res("AccentBrush") : Res("BorderBrush")
        };
    }

    private async void OnAdd(object? sender, RoutedEventArgs e)
    {
        var name = (NewName.Text ?? "").Trim();
        if (!Regex.IsMatch(name, "^[A-Za-z0-9_]{3,16}$"))
        {
            AddStatus.Text = "Geçersiz ad (3-16 karakter; harf, rakam, _).";
            return;
        }
        AddButton.IsEnabled = false;
        AddStatus.Text = "Ekleniyor...";
        var (ok, err) = await _auth.CreateAccountAsync(name);
        AddStatus.Text = ok ? "Hesap eklendi ✓" : ("Hata: " + err);
        if (ok) NewName.Text = "";
        await RefreshAsync();
    }

    private async Task UploadSkinAsync(Account a)
    {
        var top = TopLevel.GetTopLevel(this);
        if (top is null) return;
        var files = await top.StorageProvider.OpenFilePickerAsync(new FilePickerOpenOptions
        {
            Title = "Skin PNG seç (64x64 veya 64x32)",
            AllowMultiple = false,
            FileTypeFilter = new[] { new FilePickerFileType("PNG") { Patterns = new[] { "*.png" } } }
        });
        if (files.Count == 0) return;
        var path = files[0].TryGetLocalPath();
        if (string.IsNullOrEmpty(path))
        {
            AddStatus.Text = "Dosya yolu okunamadı.";
            return;
        }
        string model = SkinModel.SelectedIndex == 1 ? "slim" : "default";
        AddStatus.Text = "Skin yükleniyor...";
        var (ok, msg) = await _auth.UploadSkinAsync(a.Id, path, model);
        AddStatus.Text = msg;
        await RefreshAsync();
    }

    private IBrush? Res(string key) => this.FindResource(key) as IBrush;
}
