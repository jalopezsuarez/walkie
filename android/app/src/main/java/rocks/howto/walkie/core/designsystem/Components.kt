package rocks.howto.walkie.core.designsystem

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Done
import androidx.compose.material.icons.filled.DoneAll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.rememberVectorPainter
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.Image
import androidx.compose.ui.graphics.ColorFilter

/** Vivid diagonal gradient (violet → cyan) used across full-screen surfaces. */
fun walkieBackground(): Brush = Brush.linearGradient(
    colors = listOf(WalkieColors.GradientTop, WalkieColors.GradientMid, WalkieColors.GradientBottom),
    start = Offset.Zero,
    end = Offset.Infinite,
)

@Composable
fun Avatar(name: String, size: Dp = 40.dp) {
    val initial = name.trim().firstOrNull()?.uppercaseChar()?.toString() ?: "?"
    Box(
        modifier = Modifier
            .size(size)
            .clip(CircleShape)
            .background(WalkieColors.Accent),
        contentAlignment = Alignment.Center,
    ) {
        Text(initial, color = Color.White, fontWeight = FontWeight.SemiBold, fontSize = (size.value * 0.42f).sp)
    }
}

/** Delivery / read ticks: one check (sent/delivered) or two (read). */
@Composable
fun Checks(delivered: Boolean, read: Boolean, modifier: Modifier = Modifier) {
    val painter = rememberVectorPainter(if (read) Icons.Filled.DoneAll else Icons.Filled.Done)
    val tint = when {
        read -> WalkieColors.CheckRead
        delivered -> WalkieColors.TextMuted
        else -> WalkieColors.TextMuted.copy(alpha = 0.5f)
    }
    Row(modifier.padding(end = 2.dp), verticalAlignment = Alignment.CenterVertically) {
        Image(painter, contentDescription = null, colorFilter = ColorFilter.tint(tint), modifier = Modifier.size(14.dp))
    }
}
