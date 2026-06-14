using System;
using Avalonia.Controls;
using Avalonia.Interactivity;
using Avalonia.Platform.Storage;
using OfflineMinecraftLauncher.Services;

namespace OfflineMinecraftLauncher.Views;

public partial class SettingsView : UserControl
{
    private readonly LocalSettings _settings;
    private const string Preset =
        "-XX:+UseG1GC -XX:+UnlockExperimentalVMOptions -XX:G1NewSizePercent=20 -XX:MaxGCPauseMillis=50 -XX:+DisableExplicitGC";

    public SettingsView(LocalSettings settings)
    {
        _settings = settings;
        InitializeComponent();

        MaxSlider.Value = _settings.MaxRamMb;
        MinSlider.Value = _settings.MinRamMb;
        JavaBox.Text = _settings.JavaPath;
        JvmBox.Text = _settings.JvmArguments;
        AutoConnectBox.IsChecked = _settings.AutoConnectOnLaunch;
        UpdateLabels();

        MaxSlider.PropertyChanged += (_, e) => { if (e.Property == Slider.ValueProperty) UpdateLabels(); };
        MinSlider.PropertyChanged += (_, e) => { if (e.Property == Slider.ValueProperty) UpdateLabels(); };
    }

    private void UpdateLabels()
    {
        MaxLabel.Text = $"Maksimum: {(int)MaxSlider.Value} MB";
        MinLabel.Text = $"Minimum: {(int)MinSlider.Value} MB";
    }

    private async void OnBrowseJava(object? sender, RoutedEventArgs e)
    {
        var top = TopLevel.GetTopLevel(this);
        if (top is null) return;
        var files = await top.StorageProvider.OpenFilePickerAsync(new FilePickerOpenOptions
        {
            Title = "Java çalıştırılabilir dosyasını seç",
            AllowMultiple = false
        });
        if (files.Count > 0)
        {
            var p = files[0].TryGetLocalPath();
            if (!string.IsNullOrEmpty(p)) JavaBox.Text = p;
        }
    }

    private void OnPreset(object? sender, RoutedEventArgs e) => JvmBox.Text = Preset;

    private void OnSave(object? sender, RoutedEventArgs e)
    {
        int max = (int)MaxSlider.Value;
        int min = (int)MinSlider.Value;
        if (min > max) min = max;
        _settings.MaxRamMb = max;
        _settings.MinRamMb = min;
        _settings.JavaPath = JavaBox.Text ?? "";
        _settings.JvmArguments = JvmBox.Text ?? "";
        _settings.AutoConnectOnLaunch = AutoConnectBox.IsChecked ?? true;
        _settings.Save();
        SaveStatus.Text = "Kaydedildi ✓";
    }
}
