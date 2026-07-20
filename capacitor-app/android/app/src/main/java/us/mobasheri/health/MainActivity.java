package us.mobasheri.health;

import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.PackageInfo;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.view.View;
import android.webkit.JavascriptInterface;
import android.webkit.WebView;

import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;

import com.getcapacitor.BridgeActivity;

import java.io.File;

public class MainActivity extends BridgeActivity {

    // The update APK is ALWAYS fetched from our own site. The web layer can ask
    // for an update, but it can never choose what gets downloaded or installed.
    private static final String UPDATE_URL = "https://health.mobasheri.us/thrive.apk";
    private static final String APK_NAME = "thrive-update.apk";

    private long downloadId = -1;
    private BroadcastReceiver downloadDone;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // Android 15+ forces edge-to-edge, so the WebView draws under the
        // status bar and the navigation bar. Pad the content view by the
        // system-bar insets to keep the app neatly between the two, and paint
        // those insets with a theme-aware background (matches light/dark).
        final View content = findViewById(android.R.id.content);
        content.setBackgroundColor(ContextCompat.getColor(this, R.color.app_bg));
        ViewCompat.setOnApplyWindowInsetsListener(content, (v, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars());
            v.setPadding(bars.left, bars.top, bars.right, bars.bottom);
            return insets;
        });

        // Lets the web app read the installed version and trigger a self-update.
        WebView webView = getBridge() != null ? getBridge().getWebView() : null;
        if (webView != null) webView.addJavascriptInterface(new ThriveNative(), "ThriveNative");
    }

    // The web app is a single page — its "pages" are just JS state, with no
    // browser history — so the default Back behaviour finishes the activity and
    // closes the whole app. Instead, ask the web app to handle Back (close an
    // open sheet, or step back one page). Only when it has nothing left to do
    // (already at Home) do we send the app to the background — never kill it.
    @Override
    public void onBackPressed() {
        WebView webView = getBridge() != null ? getBridge().getWebView() : null;
        if (webView == null) { moveTaskToBack(true); return; }
        webView.evaluateJavascript(
            "(function(){try{return !!(window.__thriveBack&&window.__thriveBack());}catch(e){return false;}})()",
            value -> {
                if (!"true".equals(value)) moveTaskToBack(true);
            });
    }

    @Override
    public void onDestroy() {
        if (downloadDone != null) {
            try { unregisterReceiver(downloadDone); } catch (Exception ignored) {}
            downloadDone = null;
        }
        super.onDestroy();
    }

    /** Bridge exposed to the web app as window.ThriveNative. */
    public class ThriveNative {
        @JavascriptInterface
        public int versionCode() {
            try {
                PackageInfo pi = getPackageManager().getPackageInfo(getPackageName(), 0);
                return Build.VERSION.SDK_INT >= 28 ? (int) pi.getLongVersionCode() : pi.versionCode;
            } catch (Exception e) { return 0; }
        }

        @JavascriptInterface
        public String versionName() {
            try { return getPackageManager().getPackageInfo(getPackageName(), 0).versionName; }
            catch (Exception e) { return ""; }
        }

        /** Download the newest APK and hand it to Android's package installer. */
        @JavascriptInterface
        public void installUpdate() {
            runOnUiThread(MainActivity.this::startUpdateDownload);
        }
    }

    private void startUpdateDownload() {
        try {
            File dir = getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS);
            File previous = new File(dir, APK_NAME);
            if (previous.exists()) previous.delete();

            // Cache-buster: Cloudflare fronts the site and will happily serve a
            // stale APK otherwise.
            DownloadManager.Request req = new DownloadManager.Request(
                Uri.parse(UPDATE_URL + "?t=" + System.currentTimeMillis()));
            req.setTitle("Thrive update");
            req.setDescription("Downloading the latest version…");
            req.setMimeType("application/vnd.android.package-archive");
            req.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            req.setDestinationInExternalFilesDir(this, Environment.DIRECTORY_DOWNLOADS, APK_NAME);

            registerDownloadReceiver();
            DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            downloadId = dm.enqueue(req);
            toastJs("Downloading update…");
        } catch (Exception e) {
            toastJs("Update download failed: " + e.getMessage());
        }
    }

    private void registerDownloadReceiver() {
        if (downloadDone != null) return;
        downloadDone = new BroadcastReceiver() {
            @Override
            public void onReceive(Context context, Intent intent) {
                long id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1);
                if (id == downloadId) launchInstaller();
            }
        };
        IntentFilter filter = new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE);
        // The DownloadManager broadcast comes from the system, so it's exported.
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(downloadDone, filter, Context.RECEIVER_EXPORTED);
        else registerReceiver(downloadDone, filter);
    }

    private void launchInstaller() {
        try {
            File apk = new File(getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS), APK_NAME);
            if (!apk.exists() || apk.length() < 1024) { toastJs("Update download was incomplete"); return; }
            Uri uri = FileProvider.getUriForFile(this, getPackageName() + ".fileprovider", apk);
            Intent intent = new Intent(Intent.ACTION_VIEW);
            intent.setDataAndType(uri, "application/vnd.android.package-archive");
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(intent);
        } catch (Exception e) {
            toastJs("Couldn't open the installer: " + e.getMessage());
        }
    }

    /** Surface a message through the web app's own toast. */
    private void toastJs(String msg) {
        WebView webView = getBridge() != null ? getBridge().getWebView() : null;
        if (webView == null) return;
        final String safe = msg == null ? "" : msg.replace("\\", "\\\\").replace("'", "\\'");
        webView.post(() -> webView.evaluateJavascript(
            "window.toast && window.toast('" + safe + "')", null));
    }
}
