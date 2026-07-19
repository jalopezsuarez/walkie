package rocks.howto.walkie.feature.settings

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import rocks.howto.walkie.core.designsystem.WalkieColors
import rocks.howto.walkie.core.designsystem.walkieBackground
import rocks.howto.walkie.nav.screenViewModel

@Composable
fun SettingsScreen(onBack: () -> Unit, onSignedOut: () -> Unit) {
    val vm = screenViewModel { SettingsViewModel(it) }

    Column(modifier = Modifier.fillMaxSize().background(walkieBackground())) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(onClick = onBack) {
                Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Atrás", tint = WalkieColors.TextPrimary)
            }
            Text("Ajustes", fontSize = 20.sp, fontWeight = FontWeight.SemiBold, color = WalkieColors.TextPrimary)
        }

        Column(Modifier.padding(20.dp)) {
            Text("Pseudónimo", color = WalkieColors.TextMuted, fontSize = 13.sp)
            OutlinedTextField(
                value = vm.name,
                onValueChange = { vm.name = it },
                singleLine = true,
                modifier = Modifier.fillMaxWidth().padding(top = 6.dp),
            )
            Button(
                onClick = vm::save,
                enabled = !vm.saving,
                modifier = Modifier.padding(top = 12.dp),
            ) { Text(if (vm.saving) "Guardando…" else "Guardar") }

            vm.toast?.let {
                Text(it, color = WalkieColors.Accent, modifier = Modifier.padding(top = 8.dp))
            }

            Spacer(Modifier.size(28.dp))
            Surface(color = WalkieColors.Surface, shape = RoundedCornerShape(12.dp)) {
                Text(
                    "Los mensajes se borran solos: audios a la hora, textos a las 24 h. " +
                        "Las notificaciones se muestran si el sistema las permite.",
                    color = WalkieColors.TextMuted,
                    fontSize = 13.sp,
                    modifier = Modifier.padding(14.dp),
                )
            }

            Spacer(Modifier.size(28.dp))
            OutlinedButton(
                onClick = { vm.logout(onSignedOut) },
                border = BorderStroke(2.dp, WalkieColors.Danger),
                colors = ButtonDefaults.outlinedButtonColors(contentColor = WalkieColors.Danger),
                modifier = Modifier.fillMaxWidth(),
            ) { Text("Cerrar sesión") }
        }
    }
}
