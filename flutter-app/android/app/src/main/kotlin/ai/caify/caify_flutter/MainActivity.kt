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
                        try {
                            // 우선순위: currentFocus → findFocus → 계층 탐색
                            val targetView: View? =
                                window.currentFocus
                                    ?: window.decorView.findFocus()
                                    ?: findWebView(window.decorView.rootView)

                            if (targetView == null) {
                                // 진단: 최상위 자식 뷰 목록
                                val root = window.decorView.rootView
                                val diag = if (root is ViewGroup) {
                                    (0 until root.childCount).joinToString(",") {
                                        root.getChildAt(it).javaClass.simpleName
                                    }
                                } else root.javaClass.simpleName
                                result.error("NO_VIEW", "No view found. rootChildren=[$diag]", null)
                                return@setMethodCallHandler
                            }

                            targetView.requestFocus()
                            val now = SystemClock.uptimeMillis()
                            val down = KeyEvent(now, now, KeyEvent.ACTION_DOWN,
                                KeyEvent.KEYCODE_V, 0, KeyEvent.META_CTRL_ON)
                            val up = KeyEvent(now, now, KeyEvent.ACTION_UP,
                                KeyEvent.KEYCODE_V, 0, KeyEvent.META_CTRL_ON)
                            val r1 = targetView.dispatchKeyEvent(down)
                            val r2 = targetView.dispatchKeyEvent(up)
                            result.success("ok:${targetView.javaClass.simpleName}:$r1/$r2")
                        } catch (e: Exception) {
                            result.error("ERR", e.message, null)
                        }
                    }
                    else -> result.notImplemented()
                }
            }
    }

    // WebView 계층 탐색 (클래스명 포함 방식 — flutter_inappwebview 서브클래스 대응)
    private fun findWebView(view: View): View? {
        if (view is WebView) return view
        if (view.javaClass.name.contains("WebView", ignoreCase = true)) return view
        if (view is ViewGroup) {
            for (i in 0 until view.childCount) {
                val found = findWebView(view.getChildAt(i))
                if (found != null) return found
            }
        }
        return null
    }
}
