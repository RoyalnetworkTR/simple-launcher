using System;
using System.Diagnostics;
using System.IO;
using System.Windows;
using System.Windows.Controls;

namespace OfflineMinecraftLauncher.Pages;

public partial class SettingsPage : UserControl
{
    private readonly LauncherCore _core;

    public SettingsPage(LauncherCore core)
    {
        _core = core;
        InitializeComponent();

        int max = Properties.Settings.Default.MaxRamMb; if (max < 1024) max = 4096;
        int min = Properties.Settings.Default.MinRamMb; if (min < 512) min = 2048;
        MaxRamSlider.Value = Math.Min(max, 32768);
        MinRamSlider.Value = Math.Min(min, 16384);
        JavaBox.Text = Properties.Settings.Default.JavaPath ?? "";
        JvmBox.Text = Properties.Settings.Default.JvmArguments ?? "";
        RamHint.Text = "İpucu: Maksimum RAM'i sistem belleğinin yarısı civarında tutun.";
    }

    private void MaxRamSlider_ValueChanged(object sender, RoutedPropertyChangedEventArgs<double> e)
    {
        if (MaxRamLabel != null) MaxRamLabel.Text = $"{(int)e.NewValue} MB";
    }

    private void MinRamSlider_ValueChanged(object sender, RoutedPropertyChangedEventArgs<double> e)
    {
        if (MinRamLabel != null) MinRamLabel.Text = $"{(int)e.NewValue} MB";
    }

    private void Browse_Click(object sender, RoutedEventArgs e)
    {
        var ofd = new Microsoft.Win32.OpenFileDialog
        {
            Filter = "Java (javaw.exe;java.exe)|javaw.exe;java.exe|Tüm dosyalar|*.*",
            Title = "Java yürütülebilir dosyasını seçin"
        };
        if (ofd.ShowDialog() == true) JavaBox.Text = ofd.FileName;
    }

    private void PerfPreset_Click(object sender, RoutedEventArgs e)
    {
        JvmBox.Text = "-XX:+UseG1GC -XX:+UnlockExperimentalVMOptions -XX:G1NewSizePercent=20 -XX:MaxGCPauseMillis=50 -XX:+DisableExplicitGC";
    }

    private void ClearJvm_Click(object sender, RoutedEventArgs e) => JvmBox.Text = "";

    private void OpenFolder_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            Directory.CreateDirectory(_core.PackageDir);
            Process.Start(new ProcessStartInfo { FileName = _core.PackageDir, UseShellExecute = true });
        }
        catch (Exception ex) { MessageBox.Show(ex.Message); }
    }

    private void ClearCache_Click(object sender, RoutedEventArgs e)
    {
        if (MessageBox.Show("Önbellek (assets, libraries) silinsin mi? Bir sonraki açılışta yeniden indirilir.",
                "Önbelleği Temizle", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes)
            return;
        try
        {
            foreach (var sub in new[] { "assets", "libraries" })
            {
                var p = Path.Combine(_core.PackageDir, sub);
                if (Directory.Exists(p)) Directory.Delete(p, true);
            }
            SavedHint.Text = "Önbellek temizlendi.";
        }
        catch (Exception ex) { MessageBox.Show(ex.Message); }
    }

    private void Save_Click(object sender, RoutedEventArgs e)
    {
        int max = (int)MaxRamSlider.Value;
        int min = (int)MinRamSlider.Value;
        if (min > max) min = max;
        Properties.Settings.Default.MaxRamMb = max;
        Properties.Settings.Default.MinRamMb = min;
        Properties.Settings.Default.JavaPath = JavaBox.Text;
        Properties.Settings.Default.JvmArguments = JvmBox.Text;
        Properties.Settings.Default.Save();
        SavedHint.Text = "✓ Ayarlar kaydedildi.";
    }
}
