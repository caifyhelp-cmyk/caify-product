package ai.caify.caify_flutter

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.SystemClock
import android.provider.Settings
import android.view.KeyEvent
import android.view.View
import android.view.ViewGroup
import android.webkit.WebView
import androidx.core.content.FileProvider
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File

class MainActivity : FlutterActivity() {
    private val CHANNEL = "caify/install"

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "canInstall" -> {
                        val can = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                            packageManager.canRequestPackageInstalls()
                        } else true
                        result.success(can)
                    }
                    "openInstallSettings" -> {
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                            val intent = Intent(
                                Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                                Uri.parse("package:$packageName")
                            )
                            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                            startActivity(intent)
                        }
                        result.success(null)
                    }
                    "setClipboardImage" -> {
                        try {
                            val path = call.argument<String>("path")
                                ?: return@setMethodCallHandler result.error("NO_PATH", "path required", null)
                            val file = File(path)
                            if (!file.exists()) return@setMethodCallHandler result.error("NO_FILE", "file not found: $path", null)

                            val uri = FileProvider.getUriForFile(
                                this,
                                "$packageName.flutter_inappwebview.fileprovider",
                                file
                            )
                            val clip = ClipData.newUri(contentResolver, "image", uri)
                            val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                            clipboard.setPrimaryClip(clip)
                            result.success("ok")
                        } catch (e: Exception) {
                            result.error("ERR", e.message, null)
                        }
                    }
                    "dispatchPaste" -> {
                        // 뷰 계층에서 WebView 찾기 → 실제 Ctrl+V KeyEvent 전송
                        // → isTrusted=true 붙여넣기 이벤트 → SE3 이미지 Naver CDN 업로드
                        try {
                            val webView = findWebView(window.decorView.rootView)
                                ?: return@setMethodCallHandler result.error("NO_WEBVIEW", "WebView not found", null)
                            webView.requestFocus()
                            val now = SystemClock.uptimeMillis()
                            val down = KeyEvent(now, now, KeyEvent.ACTION_DOWN,
                                KeyEvent.KEYCODE_V, 0, KeyEvent.META_CTRL_ON)
                            val up = KeyEvent(now, now, KeyEvent.ACTION_UP,
                                KeyEvent.KEYCODE_V, 0, KeyEvent.META_CTRL_ON)
                            webView.dispatchKeyEvent(down)
                            webView.dispatchKeyEvent(up)
                            result.success("ok")
                        } catch (e: Exception) {
                            result.error("ERR", e.message, null)
                        }
                    }
                    else -> result.notImplemented()
                }
            }
    }

    private fun findWebView(view: View): WebView? {
        if (view is WebView) return view
        if (view is ViewGroup) {
            for (i in 0 until view.childCount) {
                val found = findWebView(view.getChildAt(i))
                if (found != null) return found
            }
        }
        return null
    }
}
