package rocks.howto.walkie.core.designsystem

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.unit.sp

/** Light palette mirroring the web app: vivid violet→cyan gradient, white
 *  bubbles, petrol-teal interactive accent, violet reserved for the brand. */
object WalkieColors {
    val Bg = Color(0xFFE9EEF2)
    // Vivid diagonal gradient: magenta-violet (top-left) → sky-cyan (bottom-right)
    val GradientTop = Color(0xFFCFA2E6)
    val GradientMid = Color(0xFF9F9BEA)
    val GradientBottom = Color(0xFF77CFE4)
    val Brand = Color(0xFF7B6FD0)          // Walkie logo / identity only
    val Accent = Color(0xFF3F8BA1)         // petrol teal — all interactive accents
    val AccentSoft = Color(0xFFDCEAF0)     // soft teal tint
    val Surface = Color(0xFFFFFFFF)
    val Glass = Color(0xB3FFFFFF)          // frosted white bars/headers
    val BubbleMine = Color(0xFFFFFFFF)     // solid white
    val BubbleTheirs = Color(0xADFFFFFF)   // frosted white
    val TextPrimary = Color(0xFF1B1B22)
    val TextMuted = Color(0xFF6D7B84)
    val Danger = Color(0xFFE5484D)
    val CheckRead = Color(0xFF3F8BA1)      // teal
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
