package rocks.howto.walkie.core.di

import android.content.Context
import rocks.howto.walkie.BuildConfig
import rocks.howto.walkie.core.audio.AudioPlayer
import rocks.howto.walkie.core.audio.AudioRecorder
import rocks.howto.walkie.core.data.SessionStore
import rocks.howto.walkie.core.network.WalkieApi
import rocks.howto.walkie.core.network.buildOkHttp
import rocks.howto.walkie.messaging.DeviceRegistrar

/**
 * Manual dependency container — a single, explicit graph created once in
 * [rocks.howto.walkie.WalkieApp]. Small apps don't need an annotation-processor
 * DI framework; this keeps wiring obvious and build times fast.
 */
class AppContainer(context: Context) {

    private val appContext = context.applicationContext

    val session = SessionStore(appContext).also { it.prime() }

    private val http = buildOkHttp { session.cachedAccess }

    val api = WalkieApi(
        baseUrl = BuildConfig.API_BASE,
        client = http,
        session = session,
    )

    val audioRecorder = AudioRecorder(appContext)
    val audioPlayer = AudioPlayer(appContext.cacheDir)
    val deviceRegistrar = DeviceRegistrar(api, session)
}
