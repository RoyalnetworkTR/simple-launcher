package com.athenastudios.launcher.core

import com.athenastudios.launcher.data.BackendClient
import com.athenastudios.launcher.data.OptionalMod
import com.athenastudios.launcher.data.PackageFile
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.Request
import java.io.File
import java.security.MessageDigest

/**
 * Athena modpack senkronizasyonu (masaüstü PackageManager mantığının mobil eşi):
 * zorunlu dosyaları (index.json) + seçili opsiyonel modları indirir, SHA-256 ile
 * doğrular; mods/ klasörünü beyaz liste ile zorlar (anti-cheat: izinsiz jar silinir).
 *
 * gameDir = hedef .minecraft dizini (MojoLauncher'ın oyun dizinini göstermeli).
 */
class ModpackInstaller(
    private val backend: BackendClient,
    private val gameDir: File,
) {
    fun interface Progress {
        fun update(status: String, current: Int, total: Int)
    }

    suspend fun sync(selectedOptional: List<OptionalMod>, progress: Progress) = withContext(Dispatchers.IO) {
        progress.update("Güncellemeler kontrol ediliyor…", 0, 1)
        val index = backend.fetchIndex()
        if (index.isEmpty()) {
            progress.update("Pakette dosya bulunamadı.", 1, 1)
            return@withContext
        }

        val targets = ArrayList<PackageFile>(index)
        for (m in selectedOptional) {
            if (m.path.isNotBlank()) targets.add(PackageFile(m.path, m.hash, m.size))
        }

        // mods/ beyaz listesi (anti-cheat): yalnız bu yollar kalmalı
        val expected = targets.map { it.path }.toHashSet()
        cleanupMods(expected)

        val total = targets.size
        targets.forEachIndexed { i, f ->
            val dest = File(gameDir, f.path)
            if (needsDownload(dest, f.hash)) {
                progress.update("İndiriliyor: ${dest.name}", i, total)
                download(backend.fileUrl(f.path), dest)
                // indirme sonrası doğrula, bir kez yeniden dene
                if (f.hash.isNotBlank() && needsDownload(dest, f.hash)) {
                    dest.delete()
                    download(backend.fileUrl(f.path), dest)
                    if (needsDownload(dest, f.hash)) {
                        throw RuntimeException("Dosya doğrulanamadı (SHA-256): ${f.path}")
                    }
                }
            }
            progress.update("Hazırlanıyor…", i + 1, total)
        }
        progress.update("Paket güncel.", total, total)
    }

    private fun cleanupMods(expected: Set<String>) {
        val modsDir = File(gameDir, "mods")
        if (!modsDir.isDirectory) return
        modsDir.listFiles()?.forEach { f ->
            if (f.isFile && !expected.contains("mods/" + f.name)) {
                f.delete()
            }
        }
    }

    private fun needsDownload(file: File, expectedHash: String): Boolean {
        if (!file.exists()) return true
        if (expectedHash.isBlank()) return false
        return sha256(file) != expectedHash.lowercase()
    }

    private fun sha256(file: File): String {
        val md = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { ins ->
            val buf = ByteArray(8192)
            var n = ins.read(buf)
            while (n > 0) {
                md.update(buf, 0, n)
                n = ins.read(buf)
            }
        }
        return md.digest().joinToString("") { "%02x".format(it) }
    }

    private fun download(url: String, dest: File) {
        dest.parentFile?.mkdirs()
        backend.httpClient().newCall(Request.Builder().url(url).build()).execute().use { r ->
            if (!r.isSuccessful) throw RuntimeException("HTTP ${r.code}: $url")
            dest.outputStream().use { out ->
                r.body?.byteStream()?.copyTo(out) ?: throw RuntimeException("Boş yanıt: $url")
            }
        }
    }
}
