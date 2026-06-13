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
                return new ServerConfig { ServerIp = "oyna.athenastudios.com.tr", AutoConnect = true, MinecraftVersion = "1.12.2", LoaderType = "Forge", LoaderVersion = "14.23.5.2860", MaxRamMb = 4096, MinRamMb = 2048 };
            }
        }

        public async Task<List<OptionalMod>> FetchOptionalModsAsync()
        {
            try
            {
                var json = await _httpClient.GetStringAsync($"{_baseUrl}/api/mods.json");
                using var doc = JsonDocument.Parse(json);
                var result = new List<OptionalMod>();

                if (doc.RootElement.ValueKind == JsonValueKind.Object)
                {
                    JsonElement optionalEl = default;
                    bool found = false;
                    foreach (var prop in doc.RootElement.EnumerateObject())
                    {
                        if (string.Equals(prop.Name, "Optional", StringComparison.OrdinalIgnoreCase))
                        {
                            optionalEl = prop.Value;
                            found = true;
                            break;
                        }
                    }

                    if (found && optionalEl.ValueKind == JsonValueKind.Array)
                    {
                        var opts = new JsonSerializerOptions { PropertyNameCaseInsensitive = true };
                        foreach (var item in optionalEl.EnumerateArray())
                        {
                            try
                            {
                                var mod = JsonSerializer.Deserialize<OptionalMod>(item.GetRawText(), opts);
                                if (mod != null && !string.IsNullOrWhiteSpace(mod.Id))
                                    result.Add(mod);
                            }
                            catch { }
                        }
                    }
                }

                return result;
            }
            catch
            {
                return new List<OptionalMod>();
            }
        }

        public async Task SyncPackageAsync(IEnumerable<OptionalMod> selectedOptional = null)
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

            // Selected optional mods are treated as additional download targets and
            // must be added to the cleanup whitelist so they are not removed.
            var selectedList = (selectedOptional ?? Enumerable.Empty<OptionalMod>())
                .Where(m => m != null && !string.IsNullOrWhiteSpace(m.Path))
                .ToList();

            // [YENİ]: İndirmeden önce gereksiz dosyaları temizle (mods vb.)
            // Unselected optional mods under mods/ get removed here (mods/ is not ignored).
            CleanUpExtraFiles(indexFiles, selectedList);

            // Combine required + selected-optional into a single download set.
            var downloadTargets = new List<PackageFile>(indexFiles);
            foreach (var mod in selectedList)
            {
                downloadTargets.Add(new PackageFile { Path = mod.Path, Hash = mod.Hash, Size = mod.Size });
            }

            int totalFiles = downloadTargets.Count;
            int counter = 0;

            var options = new ParallelOptions { MaxDegreeOfParallelism = 24 };

            await Parallel.ForEachAsync(downloadTargets, options, async (file, ct) =>
            {
                string localPath = Path.Combine(_targetDir, file.Path.Replace('/', Path.DirectorySeparatorChar));

                if (NeedsDownload(localPath, file.Hash))
                {
                    StatusChanged?.Invoke(this, $"İndiriliyor: {Path.GetFileName(file.Path)}...");
                    if (File.Exists(localPath))
                    {
                        try { File.Delete(localPath); } catch { } // Eskisini sil
                    }

                    // Yol parçalarını URL-encode et (boşluk/özel karakter içeren dosya adları için)
                    string encodedPath = string.Join("/", file.Path.Split('/').Select(Uri.EscapeDataString));
                    string fileUrl = $"{_baseUrl}/files/{encodedPath}";

                    await DownloadFileAsync(fileUrl, localPath);

                    // [ANTI-CHEAT]: İndirme biter bitmez SHA256 doğrula; bozuk/değiştirilmiş
                    // (MITM ya da hile) dosyayı oyun başlamadan yakala. Bir kez yeniden dene.
                    if (!string.IsNullOrWhiteSpace(file.Hash) && NeedsDownload(localPath, file.Hash))
                    {
                        try { if (File.Exists(localPath)) File.Delete(localPath); } catch { }
                        await DownloadFileAsync(fileUrl, localPath);
                        if (NeedsDownload(localPath, file.Hash))
                            throw new Exception($"Dosya bütünlüğü doğrulanamadı (SHA256 uyuşmuyor): {file.Path}");
                    }
                }

                int currentCount = Interlocked.Increment(ref counter);
                ProgressChanged?.Invoke(this, (int)((currentCount / (float)totalFiles) * 100));
            });

            StatusChanged?.Invoke(this, "Paket güncel.");
        }

        private void CleanUpExtraFiles(List<PackageFile> indexFiles, List<OptionalMod> selectedOptional)
        {
            if (!Directory.Exists(_targetDir)) return;

            var indexSet = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
            foreach (var file in indexFiles)
            {
                indexSet.Add(file.Path.Replace('/', Path.DirectorySeparatorChar));
            }
            // Whitelist selected optional mods so they survive cleanup.
            if (selectedOptional != null)
            {
                foreach (var mod in selectedOptional)
                {
                    if (!string.IsNullOrWhiteSpace(mod?.Path))
                        indexSet.Add(mod.Path.Replace('/', Path.DirectorySeparatorChar));
                }
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
            // Beklenen hash yoksa (örn. opsiyonel mod kataloğunda Hash boşsa) dosyanın
            // varlığını yeterli say; aksi halde her açılışta sonsuz yeniden indirme olur.
            if (string.IsNullOrWhiteSpace(expectedHash)) return false;

            using (var sha256 = SHA256.Create())
            using (var stream = File.OpenRead(localPath))
            {
                var hash = sha256.ComputeHash(stream);
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

            using var response = await _httpClient.GetAsync(url, HttpCompletionOption.ResponseHeadersRead);
            response.EnsureSuccessStatusCode();

            using var fs = new FileStream(localPath, FileMode.Create, FileAccess.Write, FileShare.None, 8192, true);
            using var remoteStream = await response.Content.ReadAsStreamAsync();
            await remoteStream.CopyToAsync(fs);
        }
    }

    public class PackageFile
    {
        public string Path { get; set; }
        public string Hash { get; set; }
        public long Size { get; set; }
    }

    public class OptionalMod
    {
        public string Id { get; set; }
        public string Name { get; set; }
        public string Description { get; set; }
        public string Path { get; set; }
        public string Hash { get; set; }
        public long Size { get; set; }
        public bool Default { get; set; }
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
        public string JavaPath { get; set; }
    }
}