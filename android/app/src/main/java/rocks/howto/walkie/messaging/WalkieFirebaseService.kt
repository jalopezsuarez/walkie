package rocks.howto.walkie.messaging

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import rocks.howto.walkie.WalkieApp

/**
 * Receives official Google (FCM) pushes. Expects data-message keys
 * `title`, `body`, and optionally `link_id`, but also falls back to the
 * notification payload.
 */
class WalkieFirebaseService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        (application as? WalkieApp)?.container?.deviceRegistrar?.onNewToken(token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val data = message.data
        val title = data["title"] ?: message.notification?.title ?: "Walkie"
        val body = data["body"] ?: message.notification?.body ?: "Nuevo mensaje"
        val linkId = data["link_id"]?.toLongOrNull()
        Notifications.show(applicationContext, title, body, linkId)
    }
}
