using System;
using System.IO;
using System.Windows;
using System.Windows.Threading;

namespace OfflineMinecraftLauncher;

public partial class App : Application
{
    protected override void OnStartup(StartupEventArgs e)
    {
        // Global hata yakalama — çökmeleri günlüğe yaz, kullanıcıya nazikçe bildir.
        DispatcherUnhandledException += OnDispatcherUnhandledException;
        AppDomain.CurrentDomain.UnhandledException += (s, args) =>
        {
            if (args.ExceptionObject is Exception ex) LogCrash(ex);
        };
        base.OnStartup(e);
    }

    private void OnDispatcherUnhandledException(object sender, DispatcherUnhandledExceptionEventArgs e)
    {
        LogCrash(e.Exception);
        MessageBox.Show("Beklenmeyen bir hata oluştu:\n\n" + e.Exception.Message,
            "Athena Studios Launcher", MessageBoxButton.OK, MessageBoxImage.Error);
        e.Handled = true;
    }

    internal static void LogCrash(Exception ex)
    {
        try
        {
            var dir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
                AppConfig.AppDataFolder, "logs");
            Directory.CreateDirectory(dir);
            var file = Path.Combine(dir, "launcher-errors.log");
            File.AppendAllText(file, $"[{DateTime.Now:u}] {ex}\n\n");
        }
        catch { }
    }
}
