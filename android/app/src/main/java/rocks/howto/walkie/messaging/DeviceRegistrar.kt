package rocks.howto.walkie.messaging

import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import rocks.howto.walkie.core.data.SessionStore
import rocks.howto.walkie.core.network.WalkieApi

/**
 * Registers this device's FCM token with the backend so the server can push
 * message notifications. No-ops while signed out. Requires the server-side
 * `POST /devices` endpoint (see README → "Backend TODO for FCM").
 */
class DeviceRegistrar(
    private val api: WalkieApi,
    private val session: SessionStore,
) {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    /** Fetch the current token and register it (call after sign-in / on start). */
    fun syncToken() {
        if (session.cachedToken == null) return
        // Guard: without google-services.json Firebase isn't initialized and
        // getInstance() throws — the app must still run in that dev state.
        runCatching {
            FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
                scope.launch { api.registerDevice(token) }
            }
        }
    }

    fun onNewToken(token: String) {
        if (session.cachedToken == null) return
        scope.launch { api.registerDevice(token) }
    }
}
