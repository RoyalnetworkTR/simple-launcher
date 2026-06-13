using System.Threading.Tasks;
using Avalonia.Controls;
using Avalonia.Layout;
using Avalonia.Media;
using OfflineMinecraftLauncher.Services;

namespace OfflineMinecraftLauncher.Views;

public partial class NewsView : UserControl
{
    private readonly LauncherCore _core;
    private bool _loaded;

    public NewsView(LauncherCore core)
    {
        _core = core;
        InitializeComponent();
    }

    public async Task RefreshAsync()
    {
        if (_loaded) return;
        _loaded = true;
        var entries = await _core.FetchChangelogAsync();
        List.Children.Clear();
        if (entries.Count == 0)
        {
            Empty.Text = "Henüz sürüm notu yok.";
            return;
        }
        Empty.IsVisible = false;
        foreach (var c in entries)
        {
            var card = new Border { Classes = { "card" } };
            var sp = new StackPanel { Spacing = 6 };
            var header = new StackPanel { Orientation = Orientation.Horizontal, Spacing = 10 };
            header.Children.Add(new TextBlock
            {
                Text = "v" + c.Version,
                FontWeight = FontWeight.Bold,
                Foreground = (IBrush?)this.FindResource("AccentBrush")
            });
            header.Children.Add(new TextBlock { Text = c.Timestamp, Classes = { "muted" } });
            sp.Children.Add(header);
            sp.Children.Add(new TextBlock { Text = c.Note, TextWrapping = TextWrapping.Wrap });
            card.Child = sp;
            List.Children.Add(card);
        }
    }
}
