using System;
using Avalonia.Controls;
using Avalonia.Interactivity;
using Avalonia.Threading;
using OfflineMinecraftLauncher.Services;

namespace OfflineMinecraftLauncher.Views;

public partial class LoginView : UserControl
{
    private readonly AuthService _auth;
    public event Action? LoggedIn;

    public LoginView(AuthService auth)
    {
        _auth = auth;
        InitializeComponent();
    }

    private async void OnLogin(object? sender, RoutedEventArgs e)
    {
        LoginButton.IsEnabled = false;
        Spinner.IsVisible = true;
        StatusText.Text = "";
        try
        {
            var ok = await _auth.LoginAsync(s => Dispatcher.UIThread.Post(() => StatusText.Text = s));
            if (ok) { LoggedIn?.Invoke(); return; }
            StatusText.Text = "Giriş başarısız veya iptal edildi. Tekrar deneyin.";
        }
        catch (Exception ex)
        {
            StatusText.Text = "Hata: " + ex.Message;
        }
        finally
        {
            Spinner.IsVisible = false;
            LoginButton.IsEnabled = true;
        }
    }
}
