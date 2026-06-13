package com.athenastudios.launcher.ui

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Extension
import androidx.compose.material.icons.filled.Home
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.athenastudios.launcher.AppViewModel
import com.athenastudios.launcher.R
import com.athenastudios.launcher.UiState
import com.athenastudios.launcher.ui.theme.Accent
import com.athenastudios.launcher.ui.theme.AccentGlow
import com.athenastudios.launcher.ui.theme.Bg
import com.athenastudios.launcher.ui.theme.Danger
import com.athenastudios.launcher.ui.theme.Success
import com.athenastudios.launcher.ui.theme.Surface1
import com.athenastudios.launcher.ui.theme.Surface2
import com.athenastudios.launcher.ui.theme.TextMuted

@Composable
fun AppRoot(state: UiState, vm: AppViewModel) {
    if (state.banned) {
        BanScreen(state.banReason)
        return
    }
    var tab by remember { mutableIntStateOf(0) }
    Scaffold(
        containerColor = Bg,
        bottomBar = {
            NavigationBar(containerColor = Surface1) {
                NavigationBarItem(
                    selected = tab == 0,
                    onClick = { tab = 0 },
                    icon = { Icon(Icons.Filled.Home, contentDescription = null) },
                    label = { Text("Ana Sayfa") }
                )
                NavigationBarItem(
                    selected = tab == 1,
                    onClick = { tab = 1 },
                    icon = { Icon(Icons.Filled.Extension, contentDescription = null) },
                    label = { Text("Modlar") }
                )
            }
        }
    ) { pad ->
        Box(Modifier.padding(pad).fillMaxSize()) {
            when (tab) {
                0 -> HomeContent(state, vm)
                else -> ModsContent(state, vm)
            }
        }
    }
}

@Composable
private fun HomeContent(state: UiState, vm: AppViewModel) {
    Column(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(20.dp)
    ) {
        // Header
        Row(verticalAlignment = Alignment.CenterVertically) {
            Image(painterResource(R.drawable.ic_launcher_foreground), null, Modifier.size(52.dp))
            Spacer(Modifier.size(8.dp))
            Column {
                Text("ATHENA STUDIOS", fontWeight = FontWeight.Bold, fontSize = 18.sp)
                Text("1.12.2 Forge • Mobil", color = TextMuted, fontSize = 12.sp)
            }
        }

        Spacer(Modifier.height(18.dp))

        Card(colors = CardDefaults.cardColors(containerColor = Surface1)) {
            Column(Modifier.padding(16.dp)) {
                Text("KULLANICI ADI", color = TextMuted, fontSize = 11.sp)
                Spacer(Modifier.height(6.dp))
                OutlinedTextField(
                    value = state.username,
                    onValueChange = vm::setUsername,
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )
            }
        }

        Spacer(Modifier.height(12.dp))

        // Server status card
        Card(colors = CardDefaults.cardColors(containerColor = Surface1)) {
            Column(Modifier.padding(16.dp)) {
                Text("SUNUCU DURUMU", color = TextMuted, fontSize = 11.sp)
                Spacer(Modifier.height(8.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Dot(if (state.online) Success else Danger)
                    Spacer(Modifier.size(8.dp))
                    Text(
                        if (state.online) "Çevrimiçi" else "Çevrimdışı",
                        fontWeight = FontWeight.SemiBold,
                        fontSize = 16.sp
                    )
                }
                Spacer(Modifier.height(6.dp))
                Text(state.config.serverIp, color = TextMuted, fontSize = 12.sp)
                Spacer(Modifier.height(4.dp))
                Text("Oyuncular: ${state.players}   •   Gecikme: ${state.latency}", color = AccentGlow, fontSize = 13.sp)
            }
        }

        Spacer(Modifier.height(12.dp))

        // Version note
        Card(colors = CardDefaults.cardColors(containerColor = Surface1)) {
            Column(Modifier.padding(16.dp)) {
                Text("SON GÜNCELLEME  •  v${state.version}", color = TextMuted, fontSize = 11.sp)
                Spacer(Modifier.height(8.dp))
                Text(state.note, fontSize = 14.sp)
            }
        }

        Spacer(Modifier.height(20.dp))

        if (state.busy) {
            Text(state.status, color = TextMuted, fontSize = 12.sp)
            Spacer(Modifier.height(6.dp))
            LinearProgressIndicator(
                progress = { state.progress },
                modifier = Modifier.fillMaxWidth(),
                color = Accent,
                trackColor = Surface2
            )
            Spacer(Modifier.height(12.dp))
        } else {
            Text(state.status, color = TextMuted, fontSize = 12.sp)
            Spacer(Modifier.height(12.dp))
        }

        Button(
            onClick = { vm.play() },
            enabled = !state.busy,
            modifier = Modifier
                .fillMaxWidth()
                .height(56.dp)
        ) {
            Text(if (state.busy) "YÜKLENİYOR…" else "▶  OYNA", fontSize = 18.sp, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun ModsContent(state: UiState, vm: AppViewModel) {
    Column(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(20.dp)
    ) {
        Text("İsteğe Bağlı Modlar", fontWeight = FontWeight.Bold, fontSize = 20.sp)
        Spacer(Modifier.height(4.dp))
        Text(
            "Zorunlu modlar (anti-cheat) her zaman yüklenir. Burada yalnızca opsiyonel modları aç/kapat.",
            color = TextMuted, fontSize = 13.sp
        )
        Spacer(Modifier.height(14.dp))

        if (state.mods.isEmpty()) {
            Text("Şu anda kullanılabilir opsiyonel mod yok.", color = TextMuted)
        } else {
            state.mods.forEach { mod ->
                Card(
                    colors = CardDefaults.cardColors(containerColor = Surface1),
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(bottom = 10.dp)
                ) {
                    Row(
                        Modifier.padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(mod.name.ifBlank { mod.id }, fontWeight = FontWeight.SemiBold, fontSize = 15.sp)
                            if (mod.description.isNotBlank()) {
                                Spacer(Modifier.height(3.dp))
                                Text(mod.description, color = TextMuted, fontSize = 12.sp)
                            }
                        }
                        Switch(
                            checked = state.enabled.contains(mod.id),
                            onCheckedChange = { vm.toggleMod(mod.id, it) }
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun Dot(color: Color) {
    Box(
        Modifier
            .size(12.dp)
            .clip(CircleShape)
            .background(color)
    )
}

@Composable
private fun BanScreen(reason: String) {
    Box(Modifier.fillMaxSize().background(Bg), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.padding(32.dp)) {
            Text("⛔", fontSize = 48.sp)
            Spacer(Modifier.height(10.dp))
            Text("Erişim Engellendi", color = Danger, fontWeight = FontWeight.Bold, fontSize = 22.sp)
            Spacer(Modifier.height(10.dp))
            Text("Sebep: $reason", color = Color.White, fontSize = 14.sp)
        }
    }
}
