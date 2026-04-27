package com.believesportsacademy.portalapp

import android.content.Context
import android.net.Uri
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.webkit.WebView
import java.io.File
import java.security.MessageDigest

internal object PortalOfflineCache {
    private const val PREFS_NAME = "portal_offline_cache"
    private const val LAST_SYNCED_URL_KEY = "last_synced_url"
    private const val ARCHIVE_DIRECTORY = "portal_archives"
    private const val ARCHIVE_EXTENSION = ".mht"

    fun isOnline(context: Context): Boolean {
        val connectivityManager = context.getSystemService(ConnectivityManager::class.java) ?: return false
        val activeNetwork = connectivityManager.activeNetwork ?: return false
        val capabilities = connectivityManager.getNetworkCapabilities(activeNetwork) ?: return false
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    fun lastSyncedUrl(context: Context): String? {
        return prefs(context).getString(LAST_SYNCED_URL_KEY, null)?.takeIf(::isPortalUrl)
    }

    fun rememberSyncedUrl(context: Context, url: String?) {
        val portalUrl = url?.takeIf(::isPortalUrl) ?: return
        prefs(context).edit().putString(LAST_SYNCED_URL_KEY, portalUrl).apply()
    }

    fun saveCurrentPage(webView: WebView, url: String?) {
        val portalUrl = url?.takeIf(::isPortalUrl) ?: return
        val appContext = webView.context.applicationContext
        val archiveFile = archiveFile(appContext, portalUrl)
        archiveFile.parentFile?.mkdirs()
        webView.saveWebArchive(archiveFile.absolutePath, false) { savedPath ->
            if (!savedPath.isNullOrBlank()) {
                rememberSyncedUrl(appContext, portalUrl)
            }
        }
    }

    fun loadCachedPage(webView: WebView, url: String?): Boolean {
        val portalUrl = url?.takeIf(::isPortalUrl) ?: return false
        val archiveUrl = cachedArchiveUrl(webView.context.applicationContext, portalUrl) ?: return false
        webView.post {
            webView.loadUrl(archiveUrl)
        }
        return true
    }

    fun hasCachedPage(context: Context, url: String?): Boolean {
        val portalUrl = url?.takeIf(::isPortalUrl) ?: return false
        return archiveFile(context.applicationContext, portalUrl).isFile
    }

    fun isOfflineArchiveUrl(url: String?): Boolean {
        val currentUrl = url ?: return false
        return currentUrl.startsWith("file://") && currentUrl.contains("/$ARCHIVE_DIRECTORY/")
    }

    private fun cachedArchiveUrl(context: Context, url: String): String? {
        val file = archiveFile(context.applicationContext, url)
        return file.takeIf(File::isFile)?.let(Uri::fromFile)?.toString()
    }

    private fun archiveFile(context: Context, url: String): File {
        return File(archiveDirectory(context), "${sha256(url)}$ARCHIVE_EXTENSION")
    }

    private fun archiveDirectory(context: Context): File {
        return File(context.filesDir, ARCHIVE_DIRECTORY)
    }

    private fun prefs(context: Context) = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    private fun isPortalUrl(url: String): Boolean {
        return url.startsWith("http://") || url.startsWith("https://")
    }

    private fun sha256(value: String): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(value.toByteArray())
        return buildString(digest.size * 2) {
            digest.forEach { byte ->
                append("%02x".format(byte))
            }
        }
    }
}
