using System;
using System.IO;
using System.Text.Json;

namespace OfflineMinecraftLauncher.Services;

/// <summary>
/// Cross-platform JSON settings (replaces the Windows-only Properties.Settings).
/// Stored at %APPDATA%/.AthenaStudios/settings.json (or the XDG equivalent on Linux).
/// </summary>
public class LocalSettings
{
    public int MaxRamMb { get; set; } = 4096;
    public int MinRamMb { get; set; } = 2048;
    public string JavaPath { get; set; } = "";
    public string JvmArguments { get; set; } = "";
    public string EnabledOptionalMods { get; set; } = "";
    public int SelectedAccountId { get; set; } = -1;
    /// <summary>When true (default), the launcher auto-joins the server on launch
    /// if the backend's AutoConnect is also on. The user can turn this off here.</summary>
    public bool AutoConnectOnLaunch { get; set; } = true;

    private static string Dir =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), AppConfig.AppDataFolder);

    private static string FilePath => Path.Combine(Dir, "settings.json");

    public static LocalSettings Load()
    {
        try
        {
            if (File.Exists(FilePath))
            {
                var s = JsonSerializer.Deserialize<LocalSettings>(File.ReadAllText(FilePath));
                if (s != null) return s;
            }
        }
        catch { /* fall through to defaults */ }
        return new LocalSettings();
    }

    public void Save()
    {
        try
        {
            Directory.CreateDirectory(Dir);
            File.WriteAllText(FilePath, JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
        }
        catch { /* non-fatal */ }
    }
}
