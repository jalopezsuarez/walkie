package rocks.howto.walkie.messaging

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import rocks.howto.walkie.MainActivity
import rocks.howto.walkie.R

object Notifications {

    fun ensureChannel(context: Context) {
        val nm = context.getSystemService(NotificationManager::class.java) ?: return
        val id = context.getString(R.string.notif_channel_messages)
        if (nm.getNotificationChannel(id) == null) {
            nm.createNotificationChannel(
                NotificationChannel(id, context.getString(R.string.notif_channel_messages_name), NotificationManager.IMPORTANCE_HIGH),
            )
        }
    }

    fun show(context: Context, title: String, body: String, linkId: Long?) {
        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            linkId?.let { putExtra(MainActivity.EXTRA_LINK_ID, it) }
        }
        val pending = PendingIntent.getActivity(
            context, linkId?.toInt() ?: 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notif = NotificationCompat.Builder(context, context.getString(R.string.notif_channel_messages))
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(body)
            .setAutoCancel(true)
            .setContentIntent(pending)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()

        // POST_NOTIFICATIONS may be denied on 33+; notify() is a silent no-op then.
        runCatching {
            NotificationManagerCompat.from(context).notify((linkId ?: 0L).toInt(), notif)
        }
    }
}
