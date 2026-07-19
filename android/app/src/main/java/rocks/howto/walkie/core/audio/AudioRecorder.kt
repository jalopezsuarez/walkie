package rocks.howto.walkie.core.audio

import android.content.Context
import android.media.MediaRecorder
import android.os.Build
import android.os.SystemClock
import java.io.File

/**
 * Records short voice notes as AAC in an MP4/M4A container. AAC plays reliably
 * on every Android MediaPlayer (OGG/Opus playback is flaky on many OEMs) and in
 * all browsers, so the web client plays these notes without transcoding.
 */
class AudioRecorder(private val context: Context) {

    val mime: String = "audio/mp4"

    private var recorder: MediaRecorder? = null
    private var file: File? = null
    private var startedAt: Long = 0L

    data class Recording(val file: File, val durationMs: Long)

    @Suppress("DEPRECATION")
    fun start(): Boolean {
        releaseQuietly()
        val out = File(context.cacheDir, "rec_${System.currentTimeMillis()}.m4a")
        val r = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) MediaRecorder(context) else MediaRecorder()
        return runCatching {
            r.setAudioSource(MediaRecorder.AudioSource.MIC)
            r.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4)
            r.setAudioEncoder(MediaRecorder.AudioEncoder.AAC)
            r.setAudioSamplingRate(44_100)
            r.setAudioEncodingBitRate(64_000)
            r.setOutputFile(out.absolutePath)
            r.prepare()
            r.start()
            recorder = r
            file = out
            startedAt = SystemClock.elapsedRealtime()
            true
        }.getOrElse {
            runCatching { r.release() }
            out.delete()
            false
        }
    }

    /** Finalize recording; returns null if it was too short or failed. */
    fun stop(): Recording? {
        val r = recorder ?: return null
        recorder = null
        val duration = SystemClock.elapsedRealtime() - startedAt
        runCatching { r.stop() }
        runCatching { r.release() }
        val f = file
        file = null
        return if (f != null && f.length() > 0 && duration >= 300) {
            Recording(f, duration)
        } else {
            f?.delete()
            null
        }
    }

    fun cancel() {
        val f = file
        releaseQuietly()
        f?.delete()
    }

    private fun releaseQuietly() {
        recorder?.let { runCatching { it.stop() }; runCatching { it.release() } }
        recorder = null
        file = null
    }
}
