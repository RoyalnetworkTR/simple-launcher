using System.Collections.Generic;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;

namespace OfflineMinecraftLauncher.Pages;

public partial class NewsPage : UserControl
{
    private readonly LauncherCore _core;
    private bool _loaded;

    public NewsPage(LauncherCore core)
    {
        _core = core;
        InitializeComponent();
    }

    public async void Reload()
    {
        if (_loaded) return;
        _loaded = true; // tekrar girişi (hızlı sekme değişimi) senkron olarak engelle
        List<ChangelogEntry> entries;
        try { entries = await _core.FetchChangelogAsync(); }
        catch { entries = new List<ChangelogEntry>(); }

        NewsList.Children.Clear();
        EmptyLabel.Visibility = entries.Count == 0 ? Visibility.Visible : Visibility.Collapsed;

        foreach (var entry in entries)
            NewsList.Children.Add(BuildCard(entry));
    }

    private Border BuildCard(ChangelogEntry entry)
    {
        var card = new Border
        {
            Style = (Style)FindResource("Card"),
            Margin = new Thickness(0, 0, 0, 10)
        };
        var sp = new StackPanel();

        var header = new StackPanel { Orientation = Orientation.Horizontal };
        var badge = new Border
        {
            Background = (Brush)FindResource("AccentBrush"),
            CornerRadius = new CornerRadius(8),
            Padding = new Thickness(10, 3, 10, 3)
        };
        badge.Child = new TextBlock { Text = "v" + entry.Version, Foreground = Brushes.White, FontWeight = FontWeights.SemiBold, FontSize = 12 };
        header.Children.Add(badge);
        header.Children.Add(new TextBlock
        {
            Text = entry.Timestamp,
            Foreground = (Brush)FindResource("TextMutedBrush"),
            FontSize = 11,
            VerticalAlignment = VerticalAlignment.Center,
            Margin = new Thickness(10, 0, 0, 0)
        });
        sp.Children.Add(header);

        sp.Children.Add(new TextBlock
        {
            Text = entry.Note,
            TextWrapping = TextWrapping.Wrap,
            Margin = new Thickness(0, 10, 0, 0)
        });

        card.Child = sp;
        return card;
    }
}
