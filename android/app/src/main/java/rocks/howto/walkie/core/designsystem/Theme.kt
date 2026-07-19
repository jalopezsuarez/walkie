package rocks.howto.walkie.core.designsystem

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.unit.sp

/** Minimalist light palette mirroring the web app (pastel violet → blue). */
object WalkieColors {
    val Bg = Color(0xFFE9EEF2)
    val GradientTop = Color(0xFFE7E1FB)
    val GradientBottom = Color(0xFFDDE9F6)
    val Accent = Color(0xFF7B6FD0)
    val AccentSoft = Color(0xFFE2DCFB)
    val Surface = Color(0xFFFFFFFF)
    val BubbleMine = Color(0xFFD9D2F6)
    val BubbleTheirs = Color(0xFFFFFFFF)
    val TextPrimary = Color(0xFF1B1B22)
    val TextMuted = Color(0xFF8A8F98)
    val Danger = Color(0xFFE5484D)
    val CheckRead = Color(0xFF4C86D6)
}

private val LightScheme = lightColorScheme(
    primary = WalkieColors.Accent,
    onPrimary = Color.White,
    secondary = WalkieColors.AccentSoft,
    background = WalkieColors.Bg,
    onBackground = WalkieColors.TextPrimary,
    surface = WalkieColors.Surface,
    onSurface = WalkieColors.TextPrimary,
    error = WalkieColors.Danger,
)

private val WalkieType = Typography(
    bodyLarge = TextStyle(fontSize = 16.sp, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontSize = 15.sp, lineHeight = 20.sp),
    titleLarge = TextStyle(fontSize = 20.sp, lineHeight = 26.sp),
    labelLarge = TextStyle(fontSize = 15.sp, lineHeight = 20.sp),
)

@Composable
fun WalkieTheme(content: @Composable () -> Unit) {
    // The design is intentionally light-only to match the web app.
    MaterialTheme(colorScheme = LightScheme, typography = WalkieType, content = content)
}
