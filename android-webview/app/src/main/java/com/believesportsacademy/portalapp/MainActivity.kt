package com.believesportsacademy.portalapp

import android.Manifest
import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.webkit.CookieManager
import android.webkit.JavascriptInterface
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import com.believesportsacademy.portalapp.databinding.ActivityMainBinding

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (!granted && Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            Toast.makeText(this, R.string.notification_permission_denied, Toast.LENGTH_SHORT).show()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        PortalBackgroundNotifications.ensureNotificationChannel(this)
        PortalBackgroundNotifications.schedule(this)
        requestNotificationPermissionIfNeeded()
        configureWebView(savedInstanceState)
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (binding.portalWebView.canGoBack()) {
                    binding.portalWebView.goBack()
                } else {
                    finish()
                }
            }
        })
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun configureWebView(savedInstanceState: Bundle?) {
        binding.portalWebView.apply {
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.databaseEnabled = true
            settings.loadsImagesAutomatically = true
            settings.javaScriptCanOpenWindowsAutomatically = true
            settings.useWideViewPort = true
            settings.loadWithOverviewMode = true
            settings.mediaPlaybackRequiresUserGesture = false
            addJavascriptInterface(PortalJavascriptBridge(), JAVASCRIPT_BRIDGE_NAME)
            webViewClient = PortalWebViewClient()
        }

        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(binding.portalWebView, true)

        if (savedInstanceState == null) {
            binding.portalWebView.loadUrl(BuildConfig.PORTAL_URL)
        } else {
            binding.portalWebView.restoreState(savedInstanceState)
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        binding.portalWebView.saveState(outState)
    }

    override fun onDestroy() {
        binding.portalWebView.removeJavascriptInterface(JAVASCRIPT_BRIDGE_NAME)
        super.onDestroy()
    }

    override fun onStop() {
        PortalBackgroundNotifications.flushCookies()
        super.onStop()
    }

    private fun requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
            return
        }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED) {
            return
        }
        notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    private fun openExternalLink(uri: Uri) {
        val intent = Intent(Intent.ACTION_VIEW, uri)
        try {
            startActivity(intent)
        } catch (_: ActivityNotFoundException) {
            Toast.makeText(this, R.string.external_browser_error, Toast.LENGTH_SHORT).show()
        }
    }

    private fun showPortalNotification(title: String?, message: String?) {
        PortalBackgroundNotifications.showPortalNotification(this, title, message)
    }

    private inner class PortalWebViewClient : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
            val uri = request?.url ?: return false
            val scheme = uri.scheme?.lowercase().orEmpty()
            if (scheme == "http" || scheme == "https") {
                return false
            }
            openExternalLink(uri)
            return true
        }

        override fun onPageStarted(view: WebView?, url: String?, favicon: android.graphics.Bitmap?) {
            super.onPageStarted(view, url, favicon)
            binding.loadingIndicator.show()
        }

        override fun onPageFinished(view: WebView?, url: String?) {
            super.onPageFinished(view, url)
            binding.loadingIndicator.hide()
            PortalBackgroundNotifications.flushCookies()
            PortalBackgroundNotifications.schedule(this@MainActivity)
        }

        override fun onReceivedError(
            view: WebView?,
            request: WebResourceRequest?,
            error: WebResourceError?
        ) {
            super.onReceivedError(view, request, error)
            if (request?.isForMainFrame == true) {
                binding.loadingIndicator.hide()
                Toast.makeText(this@MainActivity, R.string.portal_load_error, Toast.LENGTH_SHORT).show()
            }
        }
    }

    private inner class PortalJavascriptBridge {
        @JavascriptInterface
        fun showNotification(title: String?, message: String?) {
            runOnUiThread {
                showPortalNotification(title, message)
            }
        }

        @JavascriptInterface
        fun syncPortalState(sessionKey: String?, latestNotificationId: String?) {
            PortalBackgroundNotifications.syncPortalState(
                this@MainActivity.applicationContext,
                sessionKey,
                latestNotificationId
            )
        }

        @JavascriptInterface
        fun clearPortalState() {
            PortalBackgroundNotifications.clearPortalState(this@MainActivity.applicationContext)
        }

        @JavascriptInterface
        fun reloadPortal() {
            runOnUiThread {
                binding.portalWebView.reload()
            }
        }
    }

    companion object {
        private const val JAVASCRIPT_BRIDGE_NAME = "AndroidBridge"
    }
}
