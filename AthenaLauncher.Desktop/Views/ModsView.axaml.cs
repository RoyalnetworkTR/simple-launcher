using System.Collections.Generic;
using System.Linq;
using System.Text.Json;
using System.Threading.Tasks;
using Avalonia;
using Avalonia.Controls;
using Avalonia.Media;
using OfflineMinecraftLauncher.Services;

namespace OfflineMinecraftLauncher.Views;

public partial class ModsView : UserControl
{
    private readonly LauncherCore _core;
    private readonly LocalSettings _settings;
    private bool _loaded;
    private HashSet<string> _enabled = new();

    public ModsView(LauncherCore core, LocalSettings settings)
    {
        _core = core;
        _settings = settings;
        InitializeComponent();
    }

    public async Task RefreshAsync()
    {
        if (_loaded) return;
        _loaded = true;
        _enabled = LoadEnabled();
        bool firstRun = string.IsNullOrWhiteSpace(_settings.EnabledOptionalMods);

        var mods = await _core.FetchOptionalModsAsync();
        List.Children.Clear();
        if (mods.Count == 0)
        {
            Empty.Text = "İsteğe bağlı mod yok.";
            return;
        }
        Empty.IsVisible = false;

        foreach (var m in mods)
        {
            bool on = _enabled.Contains(m.Id) || (firstRun && m.Default);
            if (on) _enabled.Add(m.Id);

            var cb = new CheckBox { IsChecked = on, Content = BuildContent(m) };
            string id = m.Id;
            cb.IsCheckedChanged += (_, _) =>
            {
                if (cb.IsChecked == true) _enabled.Add(id); else _enabled.Remove(id);
                Persist();
            };
            List.Children.Add(new Border { Classes = { "card" }, Child = cb });
        }
        Persist();
    }

    private static Control BuildContent(OptionalMod m)
    {
        var sp = new StackPanel { Spacing = 4, Margin = new Thickness(6, 0, 0, 0) };
        sp.Children.Add(new TextBlock
        {
            Text = string.IsNullOrWhiteSpace(m.Name) ? m.Id : m.Name,
            FontWeight = FontWeight.SemiBold
        });
        if (!string.IsNullOrWhiteSpace(m.Description))
            sp.Children.Add(new TextBlock { Text = m.Description, Classes = { "muted" }, TextWrapping = TextWrapping.Wrap });
        sp.Children.Add(new TextBlock { Text = FormatSize(m.Size), Classes = { "muted" }, FontSize = 11 });
        return sp;
    }

    private static string FormatSize(long bytes)
    {
        if (bytes >= 1024 * 1024) return $"{bytes / 1024.0 / 1024.0:0.0} MB";
        if (bytes >= 1024) return $"{bytes / 1024.0:0} KB";
        return $"{bytes} B";
    }

    private HashSet<string> LoadEnabled()
    {
        try
        {
            if (!string.IsNullOrWhiteSpace(_settings.EnabledOptionalMods))
            {
                var arr = JsonSerializer.Deserialize<List<string>>(_settings.EnabledOptionalMods);
                if (arr != null) return new HashSet<string>(arr);
            }
        }
        catch { }
        return new HashSet<string>();
    }

    private void Persist()
    {
        _settings.EnabledOptionalMods = JsonSerializer.Serialize(_enabled.ToList());
        _settings.Save();
    }
}
