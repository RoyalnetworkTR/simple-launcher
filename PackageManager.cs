#nullable disable
using System;
using System.Collections.Generic;
using System.IO;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text.Json;
using System.Threading.Tasks;

namespace OfflineMinecraftLauncher
{
    public class PackageManager
    {
        private readonly string _baseUrl;
        private readonly string _targetDir;
        private readonly HttpClient _httpClient;

        // Kullanıcının silinmesini İSTEMEDİĞİ istisna dosya ve klasörler
        private static readonly HashSet<string> IgnoredPaths = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            "options.txt", "server.dat", "servers.dat", "config", "saves", "assets", "client", 
            "crash-reports", "defaultconfigs", "downloads", "dynamic-data-pack-cache", 
            "dynamic-resource-pack-cache", "essential", "kubejs", "libraries", "local", "logs", 
            "midi_files", "moonlight-global-datapacks", "resourcepacks", "resources", "runtime", 
            "schematics", "screenshots", "shaderpacks",
            // Otomatik eklenen stabilite istisnaları
            "versions", "launcher_profiles.json", "usercache.json", "realms_persistence.json",
            "servers.dat_old", "options.txt.bak", "optionscache.txt", "options_background.txt", "options_shaders.txt"
        };

        public event EventHandler<string> StatusChanged;
        public event EventHandler<int> ProgressChanged;

        public PackageManager(string baseUrl, string targetDir)
        {
            _baseUrl = baseUrl.TrimEnd('/');
            _targetDir = targetDir;
            _httpClient = new HttpClient();
        }

        public async Task<ServerConfig> FetchConfigAsync()
        {
            try
            {
                var response = await _httpClient.GetStringAsync($"{_baseUrl}/api/server_config.json");        
                return JsonSerializer.Deserialize<ServerConfig>(response, new JsonSerializerOptions { PropertyNameCaseInsensitive = true });
            }
            catch
            {
                return new ServerConfig { ServerIp = "oyna.royalnetwork.xyz", AutoConnect = true, MinecraftVersion = "1.20.1", LoaderType = "Vanilla", LoaderVersion = "", MaxRamMb = 4096, MinRamMb = 1024 };
            }
        }

        public async Task SyncPackageAsync()
        {
            StatusChanged?.Invoke(this, "Checking for updates...");

            List<PackageFile> indexFiles;
            try
            {
                var indexJson = await _httpClient.GetStringAsync($"{_baseUrl}/api/index.json");
                indexFiles = JsonSerializer.Deserialize<List<PackageFile>>(indexJson, new JsonSerializerOptions { PropertyNameCaseInsensitive = true });
            }
            catch (Exception ex)
            {
                StatusChanged?.Invoke(this, "Failed to check updates: " + ex.Message);
                return;
            }

            if (indexFiles == null || indexFiles.Count == 0)
            {
                StatusChanged?.Invoke(this, "No files found in package index.");
                return;
            }

            // [YENİ]: İndirmeden önce gereksiz dosyaları temizle (mods vb.)
            CleanUpExtraFiles(indexFiles);

            int totalFiles = indexFiles.Count;
            int counter = 0;

            foreach (var file in indexFiles)
            {
                counter++;
                string localPath = Path.Combine(_targetDir, file.Path.Replace('/', Path.DirectorySeparatorChar));

                if (NeedsDownload(localPath, file.Hash))
                {
                    StatusChanged?.Invoke(this, $"Downloading {Path.GetFileName(file.Path)}...");
                    if (File.Exists(localPath)) 
                    {
                        try { File.Delete(localPath); } catch { } // Eskisini sil
                    }
                    await DownloadFileAsync($"{_baseUrl}/files/{file.Path}", localPath);
                }

                ProgressChanged?.Invoke(this, (int)((counter / (float)totalFiles) * 100));
            }

            StatusChanged?.Invoke(this, "Package is up to date.");
        }

        private void CleanUpExtraFiles(List<PackageFile> indexFiles)
        {
            if (!Directory.Exists(_targetDir)) return;

            var indexSet = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            foreach (var file in indexFiles)
            {
                indexSet.Add(file.Path.Replace('/', Path.DirectorySeparatorChar));
            }

            var di = new DirectoryInfo(_targetDir);
            var allFiles = di.GetFiles("*", SearchOption.AllDirectories);

            foreach (var file in allFiles)
            {
                string relPath = file.FullName.Substring(di.FullName.Length).TrimStart(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
                int firstSlash = relPath.IndexOfAny(new[] { Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar });
                string topLevel = firstSlash > -1 ? relPath.Substring(0, firstSlash) : relPath;

                if (IgnoredPaths.Contains(topLevel))
                {
                    continue;
                }

                if (!indexSet.Contains(relPath))
                {
                    try
                    {
                        StatusChanged?.Invoke(this, $"Gereksiz dosya siliniyor: {Path.GetFileName(relPath)}");
                        file.Delete();
                    }
                    catch { }
                }
            }
        }

        private bool NeedsDownload(string localPath, string expectedHash)
        {
            if (!File.Exists(localPath)) return true;

            using (var md5 = MD5.Create())
            using (var stream = File.OpenRead(localPath))
            {
                var hash = md5.ComputeHash(stream);
                var hashString = BitConverter.ToString(hash).Replace("-", "").ToLowerInvariant();
                return !hashString.Equals(expectedHash, StringComparison.OrdinalIgnoreCase);
            }
        }

        private async Task DownloadFileAsync(string url, string localPath)
        {
            var directory = Path.GetDirectoryName(localPath);
            if (!Directory.Exists(directory))
            {
                Directory.CreateDirectory(directory);
            }

            var response = await _httpClient.GetAsync(url);
            response.EnsureSuccessStatusCode();

            using (var fs = new FileStream(localPath, FileMode.Create, FileAccess.Write, FileShare.None))
            {
                await response.Content.CopyToAsync(fs);
            }
        }
    }

    public class PackageFile
    {
        public string Path { get; set; }
        public string Hash { get; set; }
        public long Size { get; set; }
    }

    public class ServerConfig
    {
        public string ServerIp { get; set; }
        public bool AutoConnect { get; set; }
        public string MinecraftVersion { get; set; }
        public string LoaderType { get; set; }
        public string LoaderVersion { get; set; }
        public int MaxRamMb { get; set; }
        public int MinRamMb { get; set; }
    }
}