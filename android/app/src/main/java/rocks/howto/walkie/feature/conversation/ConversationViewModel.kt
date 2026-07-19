package rocks.howto.walkie.feature.conversation

import android.util.Base64
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import rocks.howto.walkie.core.audio.AudioPlayer
import rocks.howto.walkie.core.di.AppContainer
import rocks.howto.walkie.core.network.ApiException
import rocks.howto.walkie.core.network.Message

class ConversationViewModel(
    private val container: AppContainer,
    val linkId: Long,
    initialName: String,
) : ViewModel() {

    val player: AudioPlayer = container.audioPlayer
    private val recorder = container.audioRecorder

    var title by mutableStateOf(initialName)
        private set
    var messages by mutableStateOf<List<Message>>(emptyList())
        private set
    var input by mutableStateOf("")
    var recording by mutableStateOf(false)
        private set
    var toast by mutableStateOf<String?>(null)

    private val ids = HashSet<Long>()
    private var lastId = 0L
    private val readReported = HashSet<Long>()

    init {
        loadInitial()
        longPoll()
        statusPoll()
    }

    private fun loadInitial() = viewModelScope.launch {
        try {
            val r = container.api.messages(linkId, 0, wait = false)
            if (r.contact.displayName.isNotBlank()) title = r.contact.displayName
            merge(r.messages)
        } catch (_: Exception) {
        }
    }

    private fun longPoll() = viewModelScope.launch {
        while (isActive) {
            try {
                val r = container.api.messages(linkId, lastId, wait = true)
                if (r.contact.displayName.isNotBlank()) title = r.contact.displayName
                merge(r.messages)
            } catch (e: ApiException) {
                if (e.status == 404) { toast = "Conversación eliminada"; return@launch }
                delay(2_000)
            } catch (_: Exception) {
                delay(1_500) // timeout / abort — just re-issue
            }
        }
    }

    private fun statusPoll() = viewModelScope.launch {
        while (isActive) {
            delay(3_000)
            try {
                val map = container.api.statuses(linkId).associateBy { it.id }
                if (map.isNotEmpty()) {
                    messages = messages.map { m ->
                        map[m.id]?.let { m.copy(delivered = it.delivered, read = it.read) } ?: m
                    }
                }
            } catch (_: Exception) {
            }
        }
    }

    private fun merge(incoming: List<Message>) {
        if (incoming.isEmpty()) return
        val byId = incoming.associateBy { it.id }
        val fresh = incoming.filter { ids.add(it.id) }
        val base = if (fresh.isNotEmpty()) (messages + fresh).sortedBy { it.id } else messages
        // Single state write: append new + refresh delivered/read on known ones.
        messages = base.map { m -> byId[m.id]?.let { m.copy(delivered = it.delivered, read = it.read) } ?: m }
        if (fresh.isNotEmpty()) lastId = messages.last().id
        markIncomingRead(incoming)
    }

    /** Screen is open → treat incoming messages as read. */
    private fun markIncomingRead(list: List<Message>) {
        val toRead = list.filter { !it.mine && !it.read && readReported.add(it.id) }.map { it.id }
        if (toRead.isEmpty()) return
        viewModelScope.launch { runCatching { container.api.markRead(linkId, toRead) } }
    }

    fun sendText() {
        val text = input.trim()
        if (text.isEmpty()) return
        input = ""
        viewModelScope.launch {
            try {
                container.api.sendText(linkId, text)
                refresh()
            } catch (e: Exception) {
                toast = "No se pudo enviar"
                input = text
            }
        }
    }

    fun startRecording() {
        if (recording) return
        recording = recorder.start()
        if (!recording) toast = "No se pudo grabar"
    }

    fun stopAndSend() {
        if (!recording) return
        recording = false
        val rec = recorder.stop() ?: return
        viewModelScope.launch {
            try {
                val b64 = withContext(Dispatchers.IO) {
                    Base64.encodeToString(rec.file.readBytes(), Base64.NO_WRAP)
                }
                container.api.sendAudio(linkId, b64, recorder.mime, rec.durationMs)
                rec.file.delete()
                refresh()
            } catch (e: Exception) {
                toast = "No se pudo enviar el audio"
            }
        }
    }

    fun cancelRecording() {
        recording = false
        recorder.cancel()
    }

    fun togglePlay(m: Message) {
        if (m.type != "audio") return
        player.toggle(m.id, m.audio, m.mime)
    }

    fun delete(m: Message) {
        viewModelScope.launch {
            try {
                container.api.deleteMessage(linkId, m.id)
                messages = messages.filterNot { it.id == m.id }
            } catch (e: Exception) {
                toast = "No se pudo eliminar"
            }
        }
    }

    private suspend fun refresh() {
        runCatching {
            val r = container.api.messages(linkId, lastId, wait = false)
            merge(r.messages)
        }
    }

    override fun onCleared() {
        player.stop()
        recorder.cancel()
    }
}
