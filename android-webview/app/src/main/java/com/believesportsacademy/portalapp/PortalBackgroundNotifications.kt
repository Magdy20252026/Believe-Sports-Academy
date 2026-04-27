package com.believesportsacademy.portalapp

import android.Manifest
import android.app.AlarmManager
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.webkit.CookieManager
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread
import kotlin.random.Random

internal object PortalBackgroundNotifications {
    const val NOTIFICATION_CHANNEL_ID = "portal_updates"

    private const val PREFS_NAME = "portal_background_notifications"
    private const val ACTIVE_SESSION_KEY = "active_session_key"
    private const val POLL_INTERVAL_MS = 15 * 60 * 1000L
    private const val REQUEST_CODE_POLL = 4102

    fun schedule(context: Context) {
        val alarmManager = context.getSystemService(AlarmManager::class.java) ?: return
        alarmManager.setInexactRepeating(
            AlarmManager.RTC_WAKEUP,
            System.currentTimeMillis() + POLL_INTERVAL_MS,
            POLL_INTERVAL_MS,
            buildPollPendingIntent(context)
        )
    }

    fun hasActivePortalSession(context: Context): Boolean {
        return !prefs(context).getString(ACTIVE_SESSION_KEY, "").isNullOrBlank()
    }

    fun syncPortalState(context: Context, sessionKey: String?, latestNotificationId: String?) {
        val normalizedSessionKey = sessionKey?.trim().orEmpty()
        if (normalizedSessionKey.isEmpty()) {
            return
        }

        prefs(context).edit().putString(ACTIVE_SESSION_KEY, normalizedSessionKey).apply()
        latestNotificationId?.trim()?.toIntOrNull()?.takeIf { it > 0 && canPostNotifications(context) }?.let { latestId ->
            val lastDelivered = prefs(context).getInt(deliveredKey(normalizedSessionKey), 0)
            if (latestId > lastDelivered) {
                prefs(context).edit().putInt(deliveredKey(normalizedSessionKey), latestId).apply()
            }
        }
        schedule(context)
    }

    fun clearPortalState(context: Context) {
        prefs(context).edit().remove(ACTIVE_SESSION_KEY).apply()
    }

    fun flushCookies() {
        try {
            CookieManager.getInstance().flush()
        } catch (_: Throwable) {
        }
    }

    fun ensureNotificationChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return
        }

        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        val channel = NotificationChannel(
            NOTIFICATION_CHANNEL_ID,
            context.getString(R.string.notification_channel_name),
            NotificationManager.IMPORTANCE_DEFAULT
        ).apply {
            description = context.getString(R.string.notification_channel_description)
        }
        manager.createNotificationChannel(channel)
    }

    fun showPortalNotification(context: Context, title: String?, message: String?): Boolean {
        if (!canPostNotifications(context)) {
            return false
        }

        val safeTitle = title?.trim().orEmpty().ifEmpty { context.getString(R.string.notification_default_title) }
        val safeMessage = message?.trim().orEmpty()
        if (safeMessage.isEmpty()) {
            return false
        }

        ensureNotificationChannel(context)

        val launchIntent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_NEW_TASK
        }
        val pendingIntent = PendingIntent.getActivity(
            context,
            Random.nextInt(),
            launchIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(context, NOTIFICATION_CHANNEL_ID)
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setContentTitle(safeTitle)
            .setContentText(safeMessage)
            .setStyle(NotificationCompat.BigTextStyle().bigText(safeMessage))
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .build()

        NotificationManagerCompat.from(context).notify(Random.nextInt(), notification)
        return true
    }

    fun pollOnce(context: Context) {
        val payload = fetchPayload(context) ?: return
        if (payload.sessionKey.isBlank()) {
            return
        }

        prefs(context).edit().putString(ACTIVE_SESSION_KEY, payload.sessionKey).apply()

        val latestNotification = payload.notification ?: return
        val lastDeliveredId = prefs(context).getInt(deliveredKey(payload.sessionKey), 0)
        if (latestNotification.id <= lastDeliveredId) {
            return
        }

        if (showPortalNotification(context, latestNotification.title, latestNotification.message)) {
            prefs(context).edit().putInt(deliveredKey(payload.sessionKey), latestNotification.id).apply()
        }
    }

    private fun fetchPayload(context: Context): PortalNotificationPayload? {
        val connection = try {
            URL(BuildConfig.NOTIFICATION_FEED_URL).openConnection() as HttpURLConnection
        } catch (_: Throwable) {
            return null
        }

        return try {
            val cookieValue = try {
                CookieManager.getInstance().getCookie(BuildConfig.NOTIFICATION_FEED_URL).orEmpty()
            } catch (_: Throwable) {
                ""
            }

            connection.requestMethod = "GET"
            connection.connectTimeout = 15000
            connection.readTimeout = 15000
            connection.instanceFollowRedirects = false
            connection.setRequestProperty("Accept", "application/json")
            if (cookieValue.isNotBlank()) {
                connection.setRequestProperty("Cookie", cookieValue)
            }

            when (connection.responseCode) {
                HttpURLConnection.HTTP_OK -> {
                    val body = connection.inputStream.bufferedReader().use { it.readText() }
                    val json = JSONObject(body)
                    val sessionKey = json.optString("session_key")
                    val notificationObject = json.optJSONObject("notification")
                    PortalNotificationPayload(
                        sessionKey = sessionKey,
                        notification = if (notificationObject != null) {
                            PortalNotificationItem(
                                id = notificationObject.optInt("id", 0),
                                title = notificationObject.optString("title"),
                                message = notificationObject.optString("message")
                            )
                        } else {
                            null
                        }
                    )
                }

                HttpURLConnection.HTTP_UNAUTHORIZED -> {
                    clearPortalState(context)
                    null
                }

                else -> null
            }
        } catch (_: Throwable) {
            null
        } finally {
            connection.disconnect()
        }
    }

    private fun buildPollPendingIntent(context: Context): PendingIntent {
        val intent = Intent(context, PortalNotificationPollReceiver::class.java)
        return PendingIntent.getBroadcast(
            context,
            REQUEST_CODE_POLL,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }

    private fun canPostNotifications(context: Context): Boolean {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED
    }

    private fun deliveredKey(sessionKey: String): String = "delivered_$sessionKey"

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
}

internal data class PortalNotificationPayload(
    val sessionKey: String,
    val notification: PortalNotificationItem?
)

internal data class PortalNotificationItem(
    val id: Int,
    val title: String,
    val message: String
)

class PortalNotificationPollReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        val pendingResult = goAsync()
        thread(name = "portal-notification-poll") {
            try {
                PortalBackgroundNotifications.pollOnce(context.applicationContext)
            } finally {
                pendingResult.finish()
            }
        }
    }
}

class PortalBootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        when (intent?.action) {
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_MY_PACKAGE_REPLACED -> {
                if (PortalBackgroundNotifications.hasActivePortalSession(context.applicationContext)) {
                    PortalBackgroundNotifications.schedule(context.applicationContext)
                }
            }
        }
    }
}
