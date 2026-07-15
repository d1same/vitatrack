package us.mobasheri.health;

import android.os.Bundle;
import android.view.View;

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
}
