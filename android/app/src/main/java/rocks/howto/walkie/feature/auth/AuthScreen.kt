package rocks.howto.walkie.feature.auth

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.painterResource
import rocks.howto.walkie.R
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import rocks.howto.walkie.core.designsystem.WalkieColors
import rocks.howto.walkie.core.designsystem.walkieBackground
import rocks.howto.walkie.nav.screenViewModel

@Composable
fun AuthScreen(onAuthed: () -> Unit) {
    val vm = screenViewModel { AuthViewModel(it) }

    Column(
        modifier = Modifier.fillMaxSize().background(walkieBackground()).padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Box(
            modifier = Modifier
                .padding(bottom = 14.dp)
                .size(72.dp)
                .clip(RoundedCornerShape(20.dp))
                .background(WalkieColors.Brand),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                painter = painterResource(R.drawable.ic_brand),
                contentDescription = null,
                tint = Color.White,
                modifier = Modifier.size(42.dp),
            )
        }
        Text("Walkie", fontSize = 34.sp, fontWeight = FontWeight.Bold, color = WalkieColors.TextPrimary)
        Text(
            "Notas de voz y texto, entre dos.",
            color = WalkieColors.TextMuted,
            modifier = Modifier.padding(top = 6.dp, bottom = 32.dp),
        )

        val fieldMod = Modifier.widthIn(max = 360.dp).width(320.dp)

        if (vm.step == AuthViewModel.Step.EMAIL) {
            OutlinedTextField(
                value = vm.email,
                onValueChange = vm::onEmail,
                singleLine = true,
                label = { Text("Email") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                modifier = fieldMod,
            )
            Button(
                onClick = vm::requestCode,
                enabled = !vm.loading,
                modifier = fieldMod.padding(top = 16.dp),
            ) { Text(if (vm.loading) "Enviando…" else "Enviar código") }
        } else {
            Text("Código enviado a ${vm.email}", color = WalkieColors.TextMuted, modifier = Modifier.padding(bottom = 12.dp))
            OutlinedTextField(
                value = vm.code,
                onValueChange = vm::onCode,
                singleLine = true,
                label = { Text("Código") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                modifier = fieldMod,
            )
            Button(
                onClick = { vm.verify(onAuthed) },
                enabled = !vm.loading,
                modifier = fieldMod.padding(top = 16.dp),
            ) { Text(if (vm.loading) "Verificando…" else "Entrar") }
            TextButton(onClick = vm::back) { Text("Cambiar email") }
        }

        if (vm.loading) CircularProgressIndicator(modifier = Modifier.padding(top = 20.dp))
        vm.error?.let {
            Text(
                it,
                color = WalkieColors.Danger,
                textAlign = TextAlign.Center,
                modifier = Modifier.padding(top = 16.dp).widthIn(max = 360.dp),
            )
        }
    }
}
