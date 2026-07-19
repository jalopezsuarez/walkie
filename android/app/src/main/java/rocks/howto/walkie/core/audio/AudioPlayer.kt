package rocks.howto.walkie.core.audio

import android.media.MediaPlayer
import android.util.Base64
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.io.File

/**
 * Plays one voice note at a time. Exposes the currently playing message id and
 * playback progress so Compose bubbles can react. Incoming audio arrives as
 * base64 and is cached to a file on first play (cache-first, gap-free replay).
 */
class AudioPlayer(private val cacheDir: File) {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private var player: MediaPlayer? = null
    private var ticker: Job? = null
    private val files = HashMap<Long, File>()

    private val _playing = MutableStateFlow<Long?>(null)
    val playing: StateFlow<Long?> = _playing.asStateFlow()

    private val _progress = MutableStateFlow(0f)
    val progress: StateFlow<Float> = _progress.asStateFlow()

    /** Toggle playback for [id]; tapping a playing note pauses/stops it. */
    fun toggle(id: Long, base64: String?, filePath: String? = null) {
        if (_playing.value == id) { stop(); return }
        stop()
        val src = filePath?.let { File(it) } ?: base64?.let { cachedFile(id, it) } ?: return
        val mp = MediaPlayer()
        runCatching {
            mp.setDataSource(src.absolutePath)
            mp.setOnCompletionListener { stop() }
            mp.setOnPreparedListener {
                it.start()
                _playing.value = id
                startTicker(it)
            }
            mp.prepareAsync()
            player = mp
        }.onFailure { mp.release() }
    }

    private fun cachedFile(id: Long, base64: String): File? = files.getOrPut(id) {
        val f = File(cacheDir, "play_$id.audio")
        runCatching { f.writeBytes(Base64.decode(base64, Base64.DEFAULT)) }.getOrElse { return null }
        f
    }.let { if (it.exists()) it else null }

    private fun startTicker(mp: MediaPlayer) {
        ticker?.cancel()
        ticker = scope.launch {
            while (true) {
                val d = mp.duration.takeIf { it > 0 } ?: 1
                _progress.value = (mp.currentPosition.toFloat() / d).coerceIn(0f, 1f)
                delay(60)
            }
        }
    }

    fun stop() {
        ticker?.cancel(); ticker = null
        player?.let { runCatching { it.stop() }; runCatching { it.release() } }
        player = null
        _playing.value = null
        _progress.value = 0f
    }

    fun release() {
        stop()
        files.values.forEach { it.delete() }
        files.clear()
    }
}
