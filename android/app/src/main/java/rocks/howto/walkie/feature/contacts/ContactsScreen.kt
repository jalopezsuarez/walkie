package rocks.howto.walkie.feature.contacts

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.ui.res.painterResource
import rocks.howto.walkie.R
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
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import rocks.howto.walkie.core.designsystem.Avatar
import rocks.howto.walkie.core.designsystem.WalkieColors
import rocks.howto.walkie.core.designsystem.walkieBackground
import rocks.howto.walkie.core.network.Contact
import rocks.howto.walkie.nav.screenViewModel


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
            Box(
                modifier = Modifier
                    .padding(end = 10.dp)
                    .size(44.dp)
                    .clip(CircleShape)
                    .background(WalkieColors.Glass),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    painter = painterResource(R.drawable.ic_brand),
                    contentDescription = null,
                    tint = WalkieColors.Brand,
                    modifier = Modifier.size(30.dp),
                )
            }
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
                Column(
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(14.dp),
                ) {
                    if (!vm.loading) {
                        Icon(
                            painter = painterResource(R.drawable.ic_brand),
                            contentDescription = null,
                            tint = WalkieColors.Brand,
                            modifier = Modifier.size(72.dp),
                        )
                    }
                    Text(
                        if (vm.loading) "Cargando…" else "Aún no tienes contactos.\nPulsa Invitar para vincular.",
                        color = WalkieColors.TextMuted,
                        textAlign = TextAlign.Center,
                    )
                }
            }
        } else {
            LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(horizontal = 10.dp, vertical = 8.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(vm.contacts, key = { it.linkId }) { c ->
                    ContactRow(c) { onOpen(c) }
                }
            }
        }
    }
}

@Composable
private fun ContactRow(c: Contact, onClick: () -> Unit) {
    val pending = c.unread > 0
    Surface(
        onClick = onClick,
        shape = RoundedCornerShape(16.dp),
        color = WalkieColors.Surface,
        shadowElevation = 2.dp,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            modifier = Modifier.padding(horizontal = 12.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Avatar(c.displayName, size = 46.dp)
            Column(modifier = Modifier.weight(1f).padding(start = 14.dp)) {
                Text(
                    c.displayName,
                    fontSize = 17.sp,
                    fontWeight = if (pending) FontWeight.Bold else FontWeight.Medium,
                    color = WalkieColors.TextPrimary,
                )
                Text(
                    if (pending) "Mensajes pendientes" else "Toca para hablar",
                    fontSize = 13.sp,
                    color = WalkieColors.TextMuted,
                )
            }
            if (pending) {
                Box(
                    modifier = Modifier.size(24.dp).clip(CircleShape).background(WalkieColors.Accent),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(c.unread.coerceAtMost(99).toString(), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}
