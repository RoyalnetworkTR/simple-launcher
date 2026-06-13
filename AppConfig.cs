using System;

namespace OfflineMinecraftLauncher
{
    /// <summary>
    /// Central application configuration constants for the Athena Studios Launcher.
    /// </summary>
    public static class AppConfig
    {
        // Backend taban adresi (paket, ban, ping, metrik, sürüm uçları).
        // Varsayılan canlı domain; ATHENA_BACKEND_URL ortam değişkeniyle (örn. yerel test)
        // geçici olarak değiştirilebilir.
        public static string BackendUrl { get; } =
            Environment.GetEnvironmentVariable("ATHENA_BACKEND_URL") is string u && u.Length > 0
                ? u.TrimEnd('/')
                : "https://oyna.athenastudios.com.tr";

        // AppData folder name where game/package files are stored.
        public const string AppDataFolder = ".AthenaStudios";

        // User-facing product name.
        public const string ProductName = "Athena Studios Launcher";

        // Topluluk bağlantıları (gerçek adreslerinizle değiştirin).
        public const string DiscordUrl = "https://discord.gg/athenastudios";
        public const string WebsiteUrl = "https://athenastudios.com.tr";
    }
}
