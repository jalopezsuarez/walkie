package rocks.howto.walkie.feature.contacts

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import rocks.howto.walkie.core.designsystem.Avatar
import rocks.howto.walkie.core.designsystem.WalkieColors
import rocks.howto.walkie.core.designsystem.walkieBackground
import rocks.howto.walkie.core.network.Contact
import rocks.howto.walkie.nav.screenViewModel

private val UnreadGreen = Color(0xFF35B36B)

@Composable
fun ContactsScreen(
    onOpen: (Contact) -> Unit,
    onInvite: () -> Unit,
    onSettings: () -> Unit,
) {
    val vm = screenViewModel { ContactsViewModel(it) }

    Column(modifier = Modifier.fillMaxSize().background(walkieBackground())) {
        // Top bar: [Walkie] ... [Invitar] [settings]
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("Walkie", fontSize = 22.sp, fontWeight = FontWeight.Bold, color = WalkieColors.TextPrimary)
            Spacer(Modifier.weight(1f))
            Surface(
                onClick = onInvite,
                shape = RoundedCornerShape(20.dp),
                color = WalkieColors.Accent,
            ) {
                Text("Invitar", color = Color.White, modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp))
            }
            IconButton(onClick = onSettings) {
                Icon(Icons.Filled.MoreVert, contentDescription = "Ajustes", tint = WalkieColors.TextPrimary)
            }
        }

        if (vm.contacts.isEmpty()) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Text(
                    if (vm.loading) "Cargando…" else "Aún no tienes contactos.\nPulsa Invitar para vincular.",
                    color = WalkieColors.TextMuted,
                )
            }
        } else {
            LazyColumn(Modifier.fillMaxSize()) {
                items(vm.contacts, key = { it.linkId }) { c ->
                    ContactRow(c) { onOpen(c) }
                }
            }
        }
    }
}

@Composable
private fun ContactRow(c: Contact, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Avatar(c.displayName, size = 46.dp)
        Text(
            c.displayName,
            modifier = Modifier.weight(1f).padding(start = 14.dp),
            fontSize = 17.sp,
            fontWeight = FontWeight.Medium,
            color = WalkieColors.TextPrimary,
        )
        if (c.unread > 0) {
            Box(
                modifier = Modifier.size(24.dp).clip(CircleShape).background(UnreadGreen),
                contentAlignment = Alignment.Center,
            ) {
                Text(c.unread.coerceAtMost(99).toString(), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}
