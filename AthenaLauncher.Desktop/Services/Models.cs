using System.Collections.Generic;

namespace OfflineMinecraftLauncher.Services;

/// <summary>A Minecraft account registered under the logged-in Discord user.</summary>
public class Account
{
    public int Id { get; set; }
    public string Username { get; set; } = "";
    public string Uuid { get; set; } = "";
    public bool IsBanned { get; set; }
    public string? SkinHash { get; set; }
    public string? SkinModel { get; set; }
}

/// <summary>Encrypted-at-rest auth blob (JWT + per-account Yggdrasil-style secrets).</summary>
public class AuthData
{
    public string? Jwt { get; set; }
    public long JwtExp { get; set; }
    public Dictionary<int, string> AccountSecrets { get; set; } = new();
}
