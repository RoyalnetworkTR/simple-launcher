using System;
using System.Collections.Generic;
using System.Linq;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;

namespace OfflineMinecraftLauncher.Pages;

public partial class ModsPage : UserControl
{
    private readonly LauncherCore _core;
    private List<OptionalMod> _all = new();
    private readonly HashSet<string> _enabled = new(StringComparer.OrdinalIgnoreCase);
    private bool _loaded;
    private bool _loading;

    public ModsPage(LauncherCore core)
    {
        _core = core;
        InitializeComponent();
        LoadEnabled();
    }

    private void LoadEnabled()
    {
        _enabled.Clear();
        try
        {
            var saved = Properties.Settings.Default.EnabledOptionalMods;
            if (!string.IsNullOrWhiteSpace(saved))
            {
                var arr = JsonSerializer.Deserialize<List<string>>(saved);
                if (arr != null) foreach (var id in arr) if (!string.IsNullOrWhiteSpace(id)) _enabled.Add(id);
            }
        }
        catch { }
    }

    public async void Reload()
    {
        if (_loaded) { Render(); return; }
        if (_loading) return;        // eşzamanlı tekrar girişi engelle
        _loading = true;
        try
        {
            _all = await _core.FetchOptionalModsAsync() ?? new List<OptionalMod>();
            // İlk açılışta hiç seçim kaydı yoksa "default" modları işaretle
            if (string.IsNullOrWhiteSpace(Properties.Settings.Default.EnabledOptionalMods))
            {
                foreach (var m in _all) if (m.Default) _enabled.Add(m.Id);
                SaveEnabled();
            }
            _loaded = true;
        }
        catch { _all = new List<OptionalMod>(); }
        finally { _loading = false; }
        Render();
    }

    private void SearchBox_TextChanged(object sender, TextChangedEventArgs e) => Render();

    private void Render()
    {
        ModsList.Children.Clear();
        string q = (SearchBox.Text ?? "").Trim().ToLowerInvariant();
        var items = _all.Where(m => string.IsNullOrEmpty(q)
            || (m.Name ?? "").ToLowerInvariant().Contains(q)
            || (m.Id ?? "").ToLowerInvariant().Contains(q)).ToList();

        EmptyLabel.Text = _all.Count == 0 ? "Şu anda kullanılabilir opsiyonel mod yok." : "Aramanızla eşleşen mod yok.";
        EmptyLabel.Visibility = items.Count == 0 ? Visibility.Visible : Visibility.Collapsed;

        foreach (var mod in items)
            ModsList.Children.Add(BuildRow(mod));

        UpdateSummary();
    }

    private Border BuildRow(OptionalMod mod)
    {
        var card = new Border
        {
            Style = (Style)FindResource("Card"),
            Padding = new Thickness(16, 12, 16, 12),
            Margin = new Thickness(0, 0, 0, 8)
        };
        var grid = new Grid();
        grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
        grid.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });

        var info = new StackPanel();
        info.Children.Add(new TextBlock { Text = string.IsNullOrWhiteSpace(mod.Name) ? mod.Id : mod.Name, FontSize = 15, FontWeight = FontWeights.SemiBold });
        if (!string.IsNullOrWhiteSpace(mod.Description))
            info.Children.Add(new TextBlock { Text = mod.Description, FontSize = 12, Foreground = (Brush)FindResource("TextMutedBrush"), TextWrapping = TextWrapping.Wrap, Margin = new Thickness(0, 3, 0, 0) });
        info.Children.Add(new TextBlock { Text = SizeText(mod.Size), FontSize = 11, Foreground = (Brush)FindResource("TextMutedBrush"), Margin = new Thickness(0, 4, 0, 0) });
        Grid.SetColumn(info, 0);

        var toggle = new CheckBox
        {
            Style = (Style)FindResource("BrandToggle"),
            VerticalAlignment = VerticalAlignment.Center,
            IsChecked = _enabled.Contains(mod.Id)
        };
        toggle.Click += (s, e) =>
        {
            if (toggle.IsChecked == true) _enabled.Add(mod.Id); else _enabled.Remove(mod.Id);
            SaveEnabled();
            UpdateSummary();
        };
        Grid.SetColumn(toggle, 1);

        grid.Children.Add(info);
        grid.Children.Add(toggle);
        card.Child = grid;
        return card;
    }

    private void UpdateSummary()
    {
        long bytes = _all.Where(m => _enabled.Contains(m.Id)).Sum(m => m.Size);
        int n = _all.Count(m => _enabled.Contains(m.Id));
        SummaryText.Text = $"{n} mod seçili  ·  ~{SizeText(bytes)} indirilecek";
    }

    private static string SizeText(long bytes)
    {
        if (bytes <= 0) return "0 MB";
        double mb = bytes / 1048576.0;
        return mb >= 1 ? $"{mb:0.0} MB" : $"{bytes / 1024.0:0} KB";
    }

    private void SaveEnabled()
    {
        try
        {
            Properties.Settings.Default.EnabledOptionalMods = JsonSerializer.Serialize(_enabled.ToList());
            Properties.Settings.Default.Save();
        }
        catch { }
    }
}
