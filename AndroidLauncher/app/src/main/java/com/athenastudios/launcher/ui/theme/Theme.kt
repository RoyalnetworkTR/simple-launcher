package com.athenastudios.launcher.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

val Bg = Color(0xFF0E1116)
val Surface1 = Color(0xFF161B22)
val Surface2 = Color(0xFF1C2230)
val Border = Color(0xFF2A3140)
val Accent = Color(0xFF2F81F7)
val AccentGlow = Color(0xFF58A6FF)
val TextMain = Color(0xFFE6EDF3)
val TextMuted = Color(0xFF8B98A9)
val Success = Color(0xFF3FB950)
val Danger = Color(0xFFF85149)

private val AthenaColors = darkColorScheme(
    primary = Accent,
    onPrimary = Color.White,
    secondary = AccentGlow,
    background = Bg,
    onBackground = TextMain,
    surface = Surface1,
    onSurface = TextMain,
    surfaceVariant = Surface2,
    onSurfaceVariant = TextMuted,
    error = Danger,
    outline = Border,
)

@Composable
fun AthenaTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = AthenaColors, content = content)
}
