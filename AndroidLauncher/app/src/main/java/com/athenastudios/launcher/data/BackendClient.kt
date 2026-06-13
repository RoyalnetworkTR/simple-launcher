package com.athenastudios.launcher.data

import com.athenastudios.launcher.AppConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.net.URLEncoder
import java.util.concurrent.TimeUnit

/**
 * Athena backend istemcisi (masaüstü launcher ile aynı uçlar).
 * Hiçbir çağrı exception fırlatmaz; hata durumunda makul varsayılan döner.
 */
class BackendClient(private val baseUrl: String = AppConfig.BASE_URL) {

    private val http: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .build()

    private val json = Json { ignoreUnknownKeys = true; isLenient = true }

    private suspend fun getString(path: String): String? = withContext(Dispatchers.IO) {
        try {
            http.newCall(Request.Builder().url(baseUrl + path).build()).execute().use { r ->
                if (r.isSuccessful) r.body?.string() else null
            }
        } catch (e: Exception) {
            null
        }
    }

    suspend fun fetchConfig(): ServerConfig =
        getString("/api/server_config.json")?.let { runCatching { json.decodeFromString<ServerConfig>(it) }.getOrNull() }
            ?: ServerConfig()

    suspend fun fetchVersion(): VersionInfo =
        getString("/api/version.json")?.let { runCatching { json.decodeFromString<VersionInfo>(it) }.getOrNull() }
            ?: VersionInfo()

    suspend fun fetchChangelog(): List<ChangelogEntry> =
        getString("/api/changelog.json")?.let { runCatching { json.decodeFromString<List<ChangelogEntry>>(it) }.getOrNull() }
            ?: emptyList()

    suspend fun fetchOptionalMods(): List<OptionalMod> =
        getString("/api/mods.json")?.let { runCatching { json.decodeFromString<ModsCatalog>(it).optional }.getOrNull() }
            ?: emptyList()

    suspend fun fetchIndex(): List<PackageFile> =
        getString("/api/index.json")?.let { runCatching { json.decodeFromString<List<PackageFile>>(it) }.getOrNull() }
            ?: emptyList()

    suspend fun ping(ip: String): PingResult =
        getString("/api/ping?ip=" + enc(ip))?.let { runCatching { json.decodeFromString<PingResult>(it) }.getOrNull() }
            ?: PingResult()

    suspend fun checkBan(hwid: String): BanResult =
        getString("/api/check_ban?hwid=" + enc(hwid))?.let { runCatching { json.decodeFromString<BanResult>(it) }.getOrNull() }
            ?: BanResult()

    suspend fun sendMetric(query: String) = withContext(Dispatchers.IO) {
        try {
            http.newCall(
                Request.Builder().url("$baseUrl/api/metric/play?$query")
                    .post(ByteArray(0).toRequestBody()).build()
            ).execute().close()
        } catch (e: Exception) { /* metrik hatası oyunu engellemez */ }
    }

    /** /files/<path> indirme URL'i (her segment encode edilir). */
    fun fileUrl(path: String): String {
        val encoded = path.split("/").joinToString("/") { enc(it) }
        return "$baseUrl/files/$encoded"
    }

    fun httpClient(): OkHttpClient = http

    private fun enc(s: String): String = URLEncoder.encode(s, "UTF-8").replace("+", "%20")
}
