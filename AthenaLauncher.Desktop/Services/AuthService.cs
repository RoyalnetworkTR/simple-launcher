using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Sockets;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

namespace OfflineMinecraftLauncher.Services;

/// <summary>
/// Discord-based session auth + account/skin/join API client. The Discord secret
/// stays on the backend; the launcher only ever holds our signed JWT (encrypted at
/// rest) plus each account's server-issued secret (used to mint join tokens).
/// </summary>
public class AuthService
{
    private static string Base => AppConfig.BackendUrl.TrimEnd('/');
    private readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(20) };
    private AuthData _auth;

    public AuthService()
    {
        var json = SecretStore.Load();
        _auth = json != null ? (JsonSerializer.Deserialize<AuthData>(json) ?? new AuthData()) : new AuthData();
    }

    public bool HasValidSession =>
        !string.IsNullOrEmpty(_auth.Jwt) && _auth.JwtExp > DateTimeOffset.UtcNow.ToUnixTimeSeconds() + 60;

    private void Persist() => SecretStore.Save(JsonSerializer.Serialize(_auth));

    public void Logout()
    {
        _auth = new AuthData();
        SecretStore.Clear();
    }

    // ----------------------------------------------------------------- Login
    public async Task<bool> LoginAsync(Action<string>? status = null)
    {
        int port = GetFreePort();
        string nonce = Guid.NewGuid().ToString("N");

        using var listener = new HttpListener();
        listener.Prefixes.Add($"http://127.0.0.1:{port}/");
        try { listener.Start(); }
        catch { return false; }

        string startUrl = $"{Base}/api/auth/discord/start?platform=desktop&port={port}&nonce={Uri.EscapeDataString(nonce)}";
        status?.Invoke("Tarayıcı açılıyor, Discord ile giriş yapın...");
        OpenBrowser(startUrl);

        var ctxTask = listener.GetContextAsync();
        var done = await Task.WhenAny(ctxTask, Task.Delay(TimeSpan.FromMinutes(5)));
        if (done != ctxTask)
        {
            listener.Stop();
            return false;
        }

        HttpListenerContext ctx = await ctxTask;
        string? token = ctx.Request.QueryString["token"];
        string? state = ctx.Request.QueryString["state"];

        var html = Encoding.UTF8.GetBytes(
            "<!doctype html><meta charset='utf-8'><body style=\"font-family:system-ui,sans-serif;background:#0E1116;color:#E6EDF3;text-align:center;padding-top:64px\">"
            + "<h2 style='color:#2F81F7'>Giriş başarılı &#10003;</h2><p>Launcher'a dönebilirsiniz; bu sekmeyi kapatın.</p></body>");
        try
        {
            ctx.Response.ContentType = "text/html; charset=utf-8";
            await ctx.Response.OutputStream.WriteAsync(html);
            ctx.Response.Close();
        }
        catch { /* ignore */ }
        listener.Stop();

        if (string.IsNullOrEmpty(token) || state != nonce) return false;

        _auth.Jwt = token;
        _auth.JwtExp = DecodeJwtExp(token);
        Persist();
        return true;
    }

    // ------------------------------------------------------------- Accounts
    public async Task<List<Account>> GetAccountsAsync()
    {
        var list = new List<Account>();
        var resp = await Send(HttpMethod.Get, "/api/accounts");
        if (resp == null || !resp.IsSuccessStatusCode) return list;
        using var doc = JsonDocument.Parse(await resp.Content.ReadAsStringAsync());
        if (doc.RootElement.TryGetProperty("accounts", out var arr) && arr.ValueKind == JsonValueKind.Array)
        {
            foreach (var a in arr.EnumerateArray())
            {
                var acc = new Account
                {
                    Id = a.GetProperty("id").GetInt32(),
                    Username = a.GetProperty("username").GetString() ?? "",
                    Uuid = a.GetProperty("uuid").GetString() ?? "",
                    IsBanned = a.TryGetProperty("is_banned", out var b) && b.ValueKind == JsonValueKind.True,
                };
                if (a.TryGetProperty("skin", out var sk) && sk.ValueKind == JsonValueKind.Object)
                {
                    acc.SkinHash = sk.TryGetProperty("hash", out var h) ? h.GetString() : null;
                    acc.SkinModel = sk.TryGetProperty("model", out var m) ? m.GetString() : null;
                }
                list.Add(acc);
            }
        }
        return list;
    }

    /// <summary>Returns (true, null) on success, (false, errorMessage) otherwise.</summary>
    public async Task<(bool ok, string? error)> CreateAccountAsync(string username)
    {
        var resp = await Send(HttpMethod.Post, "/api/accounts", JsonBody(new { username }));
        if (resp == null) return (false, "Bağlantı hatası");
        var body = await resp.Content.ReadAsStringAsync();
        if (resp.StatusCode == HttpStatusCode.Created)
        {
            using var doc = JsonDocument.Parse(body);
            int id = doc.RootElement.GetProperty("id").GetInt32();
            string secret = doc.RootElement.GetProperty("secret").GetString() ?? "";
            _auth.AccountSecrets[id] = secret;
            Persist();
            return (true, null);
        }
        return (false, ParseError(body, resp.StatusCode));
    }

    public async Task DeleteAccountAsync(int id)
    {
        await Send(HttpMethod.Delete, $"/api/accounts/{id}");
        if (_auth.AccountSecrets.Remove(id)) Persist();
    }

    // ------------------------------------------------------------- Skins
    public async Task<(bool ok, string msg)> UploadSkinAsync(int accountId, string filePath, string model)
    {
        try
        {
            using var form = new MultipartFormDataContent();
            form.Add(new StringContent(accountId.ToString()), "account_id");
            form.Add(new StringContent(model == "slim" ? "slim" : "default"), "model");
            var bytes = await File.ReadAllBytesAsync(filePath);
            var file = new ByteArrayContent(bytes);
            file.Headers.ContentType = new MediaTypeHeaderValue("image/png");
            form.Add(file, "skin", "skin.png");

            using var req = new HttpRequestMessage(HttpMethod.Post, Base + "/api/skin/upload") { Content = form };
            if (_auth.Jwt != null) req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _auth.Jwt);
            var resp = await _http.SendAsync(req);
            var body = await resp.Content.ReadAsStringAsync();
            return resp.IsSuccessStatusCode ? (true, "Skin yüklendi.") : (false, ParseError(body, resp.StatusCode));
        }
        catch (Exception e) { return (false, e.Message); }
    }

    // ------------------------------------------------------------- Join
    /// <summary>Mints a single-use join token and writes config/athena_session.json into the game dir.</summary>
    public async Task<bool> PrepareJoinAsync(int accountId, string gameDir)
    {
        var resp = await Send(HttpMethod.Post, "/api/join/prepare", JsonBody(new { account_id = accountId }));
        if (resp == null || !resp.IsSuccessStatusCode) return false;
        var body = await resp.Content.ReadAsStringAsync();
        try
        {
            using var doc = JsonDocument.Parse(body);
            var root = doc.RootElement;
            var sessionDir = Path.Combine(gameDir, "config");
            Directory.CreateDirectory(sessionDir);
            var payload = JsonSerializer.Serialize(new
            {
                join_token = root.GetProperty("join_token").GetString(),
                username = root.GetProperty("username").GetString(),
                uuid = root.GetProperty("uuid").GetString(),
                expires_at = root.TryGetProperty("expires_at", out var e) && e.TryGetInt64(out var ev) ? ev : 0L,
            });
            await File.WriteAllTextAsync(Path.Combine(sessionDir, "athena_session.json"), payload);
            return true;
        }
        catch { return false; }
    }

    // ------------------------------------------------------------- Helpers
    private async Task<HttpResponseMessage?> Send(HttpMethod method, string path, HttpContent? content = null)
    {
        try
        {
            using var req = new HttpRequestMessage(method, Base + path) { Content = content };
            if (_auth.Jwt != null) req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _auth.Jwt);
            return await _http.SendAsync(req);
        }
        catch { return null; }
    }

    private static StringContent JsonBody(object o) =>
        new(JsonSerializer.Serialize(o), Encoding.UTF8, "application/json");

    private static string ParseError(string body, HttpStatusCode code)
    {
        try
        {
            using var doc = JsonDocument.Parse(body);
            if (doc.RootElement.TryGetProperty("message", out var m) && m.ValueKind == JsonValueKind.String)
                return m.GetString() ?? code.ToString();
            if (doc.RootElement.TryGetProperty("error", out var e) && e.ValueKind == JsonValueKind.String)
                return TranslateError(e.GetString() ?? "");
        }
        catch { }
        return code.ToString();
    }

    private static string TranslateError(string code) => code switch
    {
        "cap_reached" => "Hesap limitine ulaşıldı.",
        "username_taken" => "Bu kullanıcı adı zaten alınmış.",
        "invalid_username" => "Geçersiz kullanıcı adı (3-16, harf/rakam/_).",
        "rate_limited" => "Çok fazla deneme, biraz bekleyin.",
        "banned" => "Hesabınız yasaklı.",
        _ => code,
    };

    private static long DecodeJwtExp(string jwt)
    {
        try
        {
            var parts = jwt.Split('.');
            if (parts.Length != 3) return 0;
            var payload = parts[1].Replace('-', '+').Replace('_', '/');
            switch (payload.Length % 4) { case 2: payload += "=="; break; case 3: payload += "="; break; }
            using var doc = JsonDocument.Parse(Encoding.UTF8.GetString(Convert.FromBase64String(payload)));
            return doc.RootElement.TryGetProperty("exp", out var e) && e.TryGetInt64(out var v) ? v : 0;
        }
        catch { return 0; }
    }

    private static int GetFreePort()
    {
        var l = new TcpListener(IPAddress.Loopback, 0);
        l.Start();
        int port = ((IPEndPoint)l.LocalEndpoint).Port;
        l.Stop();
        return port;
    }

    private static void OpenBrowser(string url)
    {
        try { Process.Start(new ProcessStartInfo { FileName = url, UseShellExecute = true }); }
        catch
        {
            try
            {
                if (OperatingSystem.IsLinux()) Process.Start("xdg-open", url);
                else if (OperatingSystem.IsWindows()) Process.Start(new ProcessStartInfo("cmd", $"/c start \"\" \"{url}\"") { CreateNoWindow = true });
            }
            catch { /* user can copy the URL from logs */ }
        }
    }
}
