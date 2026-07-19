package rocks.howto.walkie.feature.conversation

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Mic
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TextField
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import rocks.howto.walkie.core.designsystem.Avatar
import rocks.howto.walkie.core.designsystem.Checks
import rocks.howto.walkie.core.designsystem.WalkieColors
import rocks.howto.walkie.core.designsystem.walkieBackground
import rocks.howto.walkie.core.network.Message
import rocks.howto.walkie.nav.screenViewModel

@Composable
fun ConversationScreen(linkId: Long, initialName: String, onBack: () -> Unit) {
    val vm = screenViewModel { ConversationViewModel(it, linkId, initialName) }
    val listState = rememberLazyListState()
    val snackbar = remember { SnackbarHostState() }
    val playingId by vm.player.playing.collectAsState()
    val progress by vm.player.progress.collectAsState()

    LaunchedEffect(vm.messages.size) {
        if (vm.messages.isNotEmpty()) listState.animateScrollToItem(vm.messages.lastIndex)
    }
    LaunchedEffect(vm.toast) {
        vm.toast?.let { snackbar.showSnackbar(it); vm.toast = null }
    }

    var confirmDelete by remember { mutableStateOf<Message?>(null) }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            Row(
                modifier = Modifier.fillMaxWidth().background(WalkieColors.AccentSoft).padding(horizontal = 8.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Atrás", tint = WalkieColors.TextPrimary)
                }
                Avatar(vm.title.ifBlank { "?" }, size = 34.dp)
                Text(
                    vm.title,
                    modifier = Modifier.padding(start = 10.dp),
                    fontSize = 17.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = WalkieColors.TextPrimary,
                )
            }
        },
        bottomBar = {
            Composer(
                input = vm.input,
                onInput = { vm.input = it },
                onSend = vm::sendText,
                recording = vm.recording,
                onRecordStart = vm::startRecording,
                onRecordStop = vm::stopAndSend,
                onRecordCancel = vm::cancelRecording,
            )
        },
    ) { pad ->
        LazyColumn(
            state = listState,
            modifier = Modifier.fillMaxSize().background(walkieBackground()).padding(pad).padding(horizontal = 10.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            item { Spacer(Modifier.size(6.dp)) }
            items(vm.messages, key = { it.id }) { m ->
                Bubble(
                    m = m,
                    playing = playingId == m.id,
                    progress = if (playingId == m.id) progress else 0f,
                    onTapAudio = { vm.togglePlay(m) },
                    onLongPress = { if (m.mine) confirmDelete = m },
                )
            }
        }
    }

    confirmDelete?.let { msg ->
        AlertDialog(
            onDismissRequest = { confirmDelete = null },
            title = { Text("¿Eliminar este mensaje?") },
            text = { Text("Se borrará para los dos y no dejará rastro.") },
            confirmButton = {
                TextButton(onClick = { vm.delete(msg); confirmDelete = null }) {
                    Text("Eliminar", color = WalkieColors.Danger)
                }
            },
            dismissButton = { TextButton(onClick = { confirmDelete = null }) { Text("Cancelar") } },
        )
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun Bubble(
    m: Message,
    playing: Boolean,
    progress: Float,
    onTapAudio: () -> Unit,
    onLongPress: () -> Unit,
) {
    val mine = m.mine
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start,
    ) {
        Surface(
            color = if (mine) WalkieColors.BubbleMine else WalkieColors.BubbleTheirs,
            shape = RoundedCornerShape(18.dp),
            shadowElevation = 1.dp,
            modifier = Modifier
                .widthIn(max = 300.dp)
                .combinedClickable(onClick = { if (m.type == "audio") onTapAudio() }, onLongClick = onLongPress),
        ) {
            Column(Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
                if (m.type == "audio") {
                    AudioContent(playing, progress, m.durationMs ?: 0L)
                } else {
                    Text(m.text.orEmpty(), color = WalkieColors.TextPrimary, fontSize = 16.sp)
                }
                Row(
                    modifier = Modifier.padding(top = 3.dp).align(Alignment.End),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    if (mine) Checks(m.delivered, m.read)
                    Text(timeOf(m.createdAt), color = WalkieColors.TextMuted, fontSize = 11.sp)
                }
            }
        }
    }
}

@Composable
private fun AudioContent(playing: Boolean, progress: Float, durationMs: Long) {
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.width(210.dp)) {
        Box(
            modifier = Modifier.size(38.dp).clip(CircleShape).background(WalkieColors.Accent),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                if (playing) Icons.Filled.Pause else Icons.Filled.PlayArrow,
                contentDescription = if (playing) "Pausar" else "Reproducir",
                tint = Color.White,
            )
        }
        Column(Modifier.padding(start = 10.dp).weight(1f)) {
            LinearProgressIndicator(
                progress = { if (playing) progress else 0f },
                modifier = Modifier.fillMaxWidth(),
                color = WalkieColors.Accent,
                trackColor = WalkieColors.AccentSoft,
            )
            Text(formatDur(durationMs), color = WalkieColors.TextMuted, fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp))
        }
    }
}

@Composable
private fun Composer(
    input: String,
    onInput: (String) -> Unit,
    onSend: () -> Unit,
    recording: Boolean,
    onRecordStart: () -> Unit,
    onRecordStop: () -> Unit,
    onRecordCancel: () -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth().background(WalkieColors.Surface).padding(8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        TextField(
            value = input,
            onValueChange = onInput,
            modifier = Modifier.weight(1f),
            placeholder = { Text(if (recording) "Grabando…" else "Mensaje…") },
            maxLines = 4,
            shape = RoundedCornerShape(22.dp),
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Send),
            colors = TextFieldDefaults.colors(
                focusedIndicatorColor = Color.Transparent,
                unfocusedIndicatorColor = Color.Transparent,
                focusedContainerColor = WalkieColors.Bg,
                unfocusedContainerColor = WalkieColors.Bg,
            ),
        )
        Spacer(Modifier.width(8.dp))
        val sendMode = input.isNotBlank()
        Box(
            modifier = Modifier
                .size(48.dp)
                .clip(CircleShape)
                .background(if (recording) WalkieColors.Danger else WalkieColors.Accent)
                .then(
                    if (sendMode) Modifier else Modifier.pointerInput(Unit) {
                        detectTapGestures(onPress = {
                            onRecordStart()
                            val released = tryAwaitRelease()
                            if (released) onRecordStop() else onRecordCancel()
                        })
                    }
                ),
            contentAlignment = Alignment.Center,
        ) {
            if (sendMode) {
                IconButton(onClick = onSend) {
                    Icon(Icons.AutoMirrored.Filled.Send, contentDescription = "Enviar", tint = Color.White)
                }
            } else {
                Icon(Icons.Filled.Mic, contentDescription = "Mantén pulsado para hablar", tint = Color.White)
            }
        }
    }
}

private fun timeOf(iso: String): String {
    // ISO-8601 "…THH:MM…" — show HH:MM without pulling a date library.
    val t = iso.substringAfter('T', "")
    return if (t.length >= 5) t.substring(0, 5) else ""
}

private fun formatDur(ms: Long): String {
    val s = (ms / 1000).toInt()
    return "%d:%02d".format(s / 60, s % 60)
}
