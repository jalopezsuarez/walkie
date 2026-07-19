package rocks.howto.walkie

import android.app.Application
import rocks.howto.walkie.core.di.AppContainer
import rocks.howto.walkie.messaging.Notifications

/** Application entry — owns the single dependency graph and the notif channel. */
class WalkieApp : Application() {

    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
        Notifications.ensureChannel(this)
        // Register this device for push if already signed in.
        container.deviceRegistrar.syncToken()
    }
}
