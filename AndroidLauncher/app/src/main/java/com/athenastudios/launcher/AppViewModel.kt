package com.athenastudios.launcher

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.athenastudios.launcher.core.GameLauncher
import com.athenastudios.launcher.core.Hwid
import com.athenastudios.launcher.core.ModpackInstaller
import com.athenastudios.launcher.data.BackendClient
import com.athenastudios.launcher.data.OptionalMod
import com.athenastudios.launcher.data.ServerConfig
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.io.File
import java.net.URLEncoder
import java.util.UUID

data class UiState(
    val username: String = "Oyuncu",
    val config: ServerConfig = ServerConfig(),
    val online: Boolean = false,
    val players: String = "—",
    val latency: String = "—",
    val version: Int = 0,
    val note: String = "Yükleniyor…",
    val mods: List<OptionalMod> = emptyList(),
    val enabled: Set<String> = emptySet(),
    val busy: Boolean = false,
    val status: String = "Hazır",
    val progress: Float = 0f,
    val banned: Boolean = false,
    val banReason: String = "",
)

class AppViewModel(app: Application) : AndroidViewModel(app) {

    private val backend = BackendClient()
    private val gameDir: File = File(
        getApplication<Application>().getExternalFilesDir(null) ?: getApplication<Application>().filesDir,
        ".minecraft"
    )
    private val installer = ModpackInstaller(backend, gameDir)

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()

    init {
        boot()
    }

    fun setUsername(value: String) {
        _state.value = _state.value.copy(username = value)
    }

    fun toggleMod(id: String, on: Boolean) {
        val set = _state.value.enabled.toMutableSet()
        if (on) set.add(id) else set.remove(id)
        _state.value = _state.value.copy(enabled = set)
    }

    private fun boot() = viewModelScope.launch {
        val hwid = Hwid.get(getApplication())
        val ban = backend.checkBan(hwid)
        if (ban.banned) {
            _state.value = _state.value.copy(banned = true, banReason = ban.reason ?: "Yasaklı")
            return@launch
        }
        val cfg = backend.fetchConfig()
        val ver = backend.fetchVersion()
        val mods = backend.fetchOptionalMods()
        val defaults = mods.filter { it.default }.map { it.id }.toSet()
        _state.value = _state.value.copy(
            config = cfg,
            version = ver.version,
            note = ver.note.ifBlank { "Sürüm notu yok." },
            mods = mods,
            enabled = defaults,
        )
        pingLoop()
    }

    private fun pingLoop() = viewModelScope.launch {
        while (true) {
            val p = backend.ping(_state.value.config.serverIp)
            _state.value = if (p.status == "online") {
                _state.value.copy(online = true, players = "${p.playersOnline}/${p.playersMax}", latency = "${p.latency}ms")
            } else {
                _state.value.copy(online = false, players = "—", latency = "—")
            }
            delay(5000)
        }
    }

    fun play() = viewModelScope.launch {
        if (_state.value.busy) return@launch
        _state.value = _state.value.copy(busy = true, status = "Hazırlanıyor…", progress = 0f)
        try {
            val selected = _state.value.mods.filter { _state.value.enabled.contains(it.id) }
            installer.sync(selected) { st, cur, total ->
                _state.value = _state.value.copy(
                    status = st,
                    progress = if (total > 0) cur.toFloat() / total else 0f
                )
            }
            val cfg = _state.value.config
            val hwid = Hwid.get(getApplication())
            val q = buildString {
                append("uuid=").append(UUID.randomUUID())
                append("&username=").append(enc(_state.value.username))
                append("&os=Android")
                append("&mc_version=").append(enc(cfg.minecraftVersion))
                append("&loader_type=").append(enc(cfg.loaderType))
                append("&loader_version=").append(enc(cfg.loaderVersion))
                append("&launcher_version=").append(AppConfig.LAUNCHER_VERSION).append("-mobile")
                append("&hwid=").append(enc(hwid))
            }
            backend.sendMetric(q)

            _state.value = _state.value.copy(status = "Motor başlatılıyor…")
            val ok = GameLauncher.handoffToMojo(getApplication())
            _state.value = _state.value.copy(
                status = if (ok) "MojoLauncher açıldı." else "MojoLauncher kurulu değil — indirme sayfası açıldı."
            )
        } catch (e: Exception) {
            _state.value = _state.value.copy(status = "Hata: ${e.message}")
        } finally {
            _state.value = _state.value.copy(busy = false)
        }
    }

    private fun enc(s: String): String = URLEncoder.encode(s, "UTF-8")
}
