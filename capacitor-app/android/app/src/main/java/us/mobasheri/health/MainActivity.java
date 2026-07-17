package us.mobasheri.health;

import android.os.Bundle;
import android.view.View;
import android.webkit.WebView;

import androidx.core.content.ContextCompat;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
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
}
