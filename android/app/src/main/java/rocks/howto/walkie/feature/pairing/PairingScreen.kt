package rocks.howto.walkie.feature.pairing

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import rocks.howto.walkie.core.designsystem.WalkieColors
import rocks.howto.walkie.core.designsystem.walkieBackground
import rocks.howto.walkie.nav.screenViewModel

@Composable
fun PairingScreen(onBack: () -> Unit, onPaired: () -> Unit) {
    val vm = screenViewModel { PairingViewModel(it) }

    val scanLauncher = rememberLauncherForActivityResult(ScanContract()) { result ->
        result.contents?.let { raw ->
            val token = extractPairToken(raw) ?: raw
            vm.claim(token, onPaired)
        }
    }

    Column(modifier = Modifier.fillMaxSize().background(walkieBackground())) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            IconButton(onClick = onBack) {
                Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Atrás", tint = WalkieColors.TextPrimary)
            }
            Text("Invitar", fontSize = 20.sp, fontWeight = FontWeight.SemiBold, color = WalkieColors.TextPrimary)
        }

        Column(
            modifier = Modifier.fillMaxSize().padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            Text(
                "Muestra este QR a la otra persona",
                color = WalkieColors.TextPrimary,
                fontWeight = FontWeight.Medium,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.size(20.dp))

            val qr = vm.qr
            Surface(shape = RoundedCornerShape(16.dp), color = androidx.compose.ui.graphics.Color.White) {
                if (qr != null) {
                    val bmp = remember(qr.pairUrl) { qrBitmap(qr.pairUrl) }
                    Image(
                        bitmap = bmp.asImageBitmap(),
                        contentDescription = "Código QR de emparejamiento",
                        modifier = Modifier.padding(16.dp).size(260.dp),
                    )
                } else {
                    Column(Modifier.padding(60.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        if (vm.loading) CircularProgressIndicator()
                        else Text(vm.error ?: "—", color = WalkieColors.Danger)
                    }
                }
            }

            Spacer(Modifier.size(28.dp))
            Text("— o —", color = WalkieColors.TextMuted)
            Spacer(Modifier.size(12.dp))
            Button(onClick = {
                scanLauncher.launch(
                    ScanOptions()
                        .setBeepEnabled(false)
                        .setOrientationLocked(false)
                        .setPrompt("Escanea el QR de Walkie"),
                )
            }) { Text("Escanear un QR") }

            vm.error?.let {
                Text(it, color = WalkieColors.Danger, modifier = Modifier.padding(top = 16.dp))
            }
        }
    }
}
