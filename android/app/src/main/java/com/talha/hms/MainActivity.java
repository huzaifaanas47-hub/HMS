package com.talha.hms;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.text.InputFilter;
import android.text.InputType;
import android.view.Gravity;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.PermissionRequest;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebStorage;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import java.text.DateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class MainActivity extends Activity {
    private static final int REQ_FIRST_RUN = 100, REQ_MEDIA = 101, REQ_GEO = 102, REQ_FILE = 200;
    private static final long CHECK_EVERY_MS = 10 * 60 * 1000L;

    private Policy policy;
    private SharedPreferences sp;
    private WebView web;
    private LinearLayout overlay;
    private TextView titleView, textView, msgView, errorView;
    private EditText codeInput;
    private Button joinBtn, retryBtn;

    private final Handler ui = new Handler(Looper.getMainLooper());
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final Runnable ticker = new Runnable() {
        @Override public void run() { refresh(); ui.postDelayed(this, CHECK_EVERY_MS); }
    };

    private String loadedBase; // the site currently shown
    private String allowedOrigin;
    private Policy.State lastState = new Policy.State();

    private ValueCallback<Uri[]> fileCallback;
    private PermissionRequest pendingMedia;
    private GeolocationPermissions.Callback pendingGeoCb;
    private String pendingGeoOrigin;

    // ------------------------------------------------------------------ lifecycle

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        policy = new Policy(this);
        sp = getSharedPreferences("hms", MODE_PRIVATE);
        buildUi();
        askAllPermissionsOnce();
        apply(policy.evaluate(), null);
    }

    @Override protected void onResume() { super.onResume(); ui.post(ticker); }
    @Override protected void onPause() { super.onPause(); ui.removeCallbacks(ticker); }
    @Override protected void onDestroy() { super.onDestroy(); io.shutdownNow(); }

    @Override
    public void onBackPressed() {
        if (web.getVisibility() == View.VISIBLE && web.canGoBack()) web.goBack();
        else super.onBackPressed();
    }

    // ------------------------------------------------------------------ policy

    private void refresh() {
        if (!policy.active) { apply(policy.evaluate(), null); return; }
        io.execute(() -> {
            final String err = policy.check();
            final Policy.State s = policy.evaluate();
            runOnUiThread(() -> apply(s, err));
        });
    }

    private void apply(Policy.State s, String err) {
        lastState = s;
        if ("ok".equals(s.state)) {
            overlay.setVisibility(View.GONE);
            web.setVisibility(View.VISIBLE);
            String base = (s.baseUrl != null && !s.baseUrl.isEmpty()) ? s.baseUrl : BuildConfig.DEFAULT_URL;
            if (base == null || base.isEmpty()) { showOverlay("No site address", "Your administrator has not set the site address yet.", null, false, false); return; }
            if (!base.equals(loadedBase)) {
                loadedBase = base;
                allowedOrigin = originOf(base);
                web.loadUrl(base);
            }
            return;
        }
        loadedBase = null;
        allowedOrigin = null;
        web.setVisibility(View.GONE);
        web.loadUrl("about:blank");
        String date = s.expiresAt > 0 ? DateFormat.getDateTimeInstance().format(new Date(s.expiresAt)) : "";
        switch (s.state) {
            case "enroll":
                showOverlay("Enter your access code", "Your administrator gave you a code like ABCD-EFGH. You only need to enter it once.", null, true, false);
                break;
            case "expired":
                showOverlay("Access has expired", s.expiresAt > 0 ? "Your access to HMS ended on " + date + "." : "Your access to HMS has ended.", s.message, false, true);
                break;
            case "blocked":
                showOverlay("Access has been disabled", "The administrator has disabled access for this device.", s.message, false, true);
                break;
            case "stale":
                showOverlay("Verification required", "HMS has not been able to reach the administration server for too long. Connect to the internet and check again.", null, false, true);
                break;
            default: // unverified: first start, no answer from the server yet
                if (err != null) showOverlay("Cannot reach the server", "Check your internet connection and try again.", null, false, true);
                else showOverlay("Connecting...", "Checking with the HMS server.", null, false, false);
        }
    }

    private void showOverlay(String title, String text, String msg, boolean askCode, boolean showRetry) {
        titleView.setText(title);
        textView.setText(text);
        msgView.setText(msg == null ? "" : msg);
        errorView.setText("");
        codeInput.setVisibility(askCode ? View.VISIBLE : View.GONE);
        joinBtn.setVisibility(askCode ? View.VISIBLE : View.GONE);
        retryBtn.setVisibility(showRetry ? View.VISIBLE : View.GONE);
        overlay.setVisibility(View.VISIBLE);
    }

    private void submitCode() {
        final String code = codeInput.getText().toString().trim();
        if (code.isEmpty()) { errorView.setText("Enter the code your administrator gave you."); return; }
        joinBtn.setEnabled(false);
        joinBtn.setText("Checking...");
        errorView.setText("");
        io.execute(() -> {
            final String err = policy.enroll(code);
            final Policy.State s = policy.evaluate();
            runOnUiThread(() -> {
                joinBtn.setEnabled(true);
                joinBtn.setText("Join");
                if (err == null) { codeInput.setText(""); apply(s, null); }
                else errorView.setText(err);
            });
        });
    }

    // ------------------------------------------------------------------ UI

    private int dp(int v) { return (int) (v * getResources().getDisplayMetrics().density); }

    private TextView label(int sizeSp, int color, int bottomDp) {
        TextView t = new TextView(this);
        t.setTextSize(sizeSp);
        t.setTextColor(color);
        t.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
        lp.bottomMargin = dp(bottomDp);
        t.setLayoutParams(lp);
        return t;
    }

    private void buildUi() {
        FrameLayout root = new FrameLayout(this);
        web = new WebView(this);
        root.addView(web, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        setupWebView();

        overlay = new LinearLayout(this);
        overlay.setOrientation(LinearLayout.VERTICAL);
        overlay.setGravity(Gravity.CENTER);
        overlay.setBackgroundColor(Color.parseColor("#1E2430"));
        overlay.setPadding(dp(24), dp(24), dp(24), dp(24));
        titleView = label(22, Color.WHITE, 10);
        textView = label(15, Color.parseColor("#B7C0D0"), 8);
        msgView = label(15, Color.parseColor("#FFCF6B"), 8);
        codeInput = new EditText(this);
        codeInput.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_CAP_CHARACTERS | InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS);
        codeInput.setFilters(new InputFilter[]{new InputFilter.LengthFilter(12)});
        codeInput.setHint("XXXX-XXXX");
        codeInput.setHintTextColor(Color.parseColor("#6B7589"));
        codeInput.setTextColor(Color.WHITE);
        codeInput.setTextSize(24);
        codeInput.setGravity(Gravity.CENTER);
        codeInput.setSingleLine(true);
        errorView = label(14, Color.parseColor("#FF9B9B"), 8);
        joinBtn = new Button(this);
        joinBtn.setText("Join");
        joinBtn.setOnClickListener(v -> submitCode());
        retryBtn = new Button(this);
        retryBtn.setText("Check again");
        retryBtn.setOnClickListener(v -> refresh());
        overlay.addView(titleView);
        overlay.addView(textView);
        overlay.addView(msgView);
        overlay.addView(codeInput, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        overlay.addView(errorView);
        overlay.addView(joinBtn);
        overlay.addView(retryBtn);
        root.addView(overlay, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        setContentView(root);
    }

    private void setupWebView() {
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        s.setGeolocationEnabled(true);
        CookieManager.getInstance().setAcceptCookie(true);
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, true);

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest r) {
                String url = r.getUrl().toString();
                if (isAllowed(url)) return false; // the HMS site stays inside the app
                try { startActivity(new Intent(Intent.ACTION_VIEW, r.getUrl())); } catch (ActivityNotFoundException ignored) { }
                return true;
            }
        });

        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onPermissionRequest(final PermissionRequest request) {
                runOnUiThread(() -> handleMediaRequest(request));
            }

            @Override
            public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback cb) {
                if (!isAllowed(origin)) { cb.invoke(origin, false, false); return; }
                if (Build.VERSION.SDK_INT >= 23 && checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) {
                    pendingGeoCb = cb;
                    pendingGeoOrigin = origin;
                    requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION}, REQ_GEO);
                } else {
                    cb.invoke(origin, true, false);
                }
            }

            @Override
            public boolean onShowFileChooser(WebView v, ValueCallback<Uri[]> cb, FileChooserParams params) {
                if (fileCallback != null) fileCallback.onReceiveValue(null);
                fileCallback = cb;
                try {
                    startActivityForResult(params.createIntent(), REQ_FILE);
                } catch (Exception e) {
                    fileCallback = null;
                    return false;
                }
                return true;
            }
        });

        web.setDownloadListener((url, ua, cd, mime, len) -> {
            try {
                DownloadManager.Request r = new DownloadManager.Request(Uri.parse(url));
                r.setMimeType(mime);
                String cookie = CookieManager.getInstance().getCookie(url);
                if (cookie != null) r.addRequestHeader("Cookie", cookie);
                r.addRequestHeader("User-Agent", ua);
                r.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                r.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, URLUtil.guessFileName(url, cd, mime));
                ((DownloadManager) getSystemService(DOWNLOAD_SERVICE)).enqueue(r);
                Toast.makeText(this, "Downloading...", Toast.LENGTH_SHORT).show();
            } catch (Exception e) {
                Toast.makeText(this, "The download could not start.", Toast.LENGTH_LONG).show();
            }
        });
    }

    // ------------------------------------------------------------------ site origin + permissions

    private static String originOf(String url) {
        try {
            Uri u = Uri.parse(url);
            if (u.getScheme() == null || u.getHost() == null) return null;
            return u.getScheme().toLowerCase() + "://" + u.getHost().toLowerCase() + (u.getPort() == -1 ? "" : ":" + u.getPort());
        } catch (Exception e) {
            return null;
        }
    }

    private boolean isAllowed(String url) {
        String o = originOf(url);
        return o != null && allowedOrigin != null && o.equals(allowedOrigin);
    }

    private boolean granted(String perm) {
        return Build.VERSION.SDK_INT < 23 || checkSelfPermission(perm) == PackageManager.PERMISSION_GRANTED;
    }

    /** Camera and microphone for the site: the website asks, we make sure Android has allowed the app. */
    private void handleMediaRequest(PermissionRequest request) {
        if (!isAllowed(request.getOrigin().toString())) { request.deny(); return; }
        List<String> missing = new ArrayList<>();
        for (String r : request.getResources()) {
            if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r) && !granted(Manifest.permission.CAMERA)) missing.add(Manifest.permission.CAMERA);
            if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(r) && !granted(Manifest.permission.RECORD_AUDIO)) missing.add(Manifest.permission.RECORD_AUDIO);
        }
        if (missing.isEmpty()) { grantAvailable(request); return; }
        if (pendingMedia != null) pendingMedia.deny();
        pendingMedia = request;
        requestPermissions(missing.toArray(new String[0]), REQ_MEDIA);
    }

    private void grantAvailable(PermissionRequest request) {
        List<String> ok = new ArrayList<>();
        for (String r : request.getResources()) {
            if (PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r) && granted(Manifest.permission.CAMERA)) ok.add(r);
            if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(r) && granted(Manifest.permission.RECORD_AUDIO)) ok.add(r);
        }
        if (ok.isEmpty()) request.deny();
        else request.grant(ok.toArray(new String[0]));
    }

    /** Android lets an app ask for its permissions the first time it opens; do it all at once so the site never has to wait. */
    private void askAllPermissionsOnce() {
        if (Build.VERSION.SDK_INT < 23 || sp.getBoolean("permsAsked", false)) return;
        List<String> need = new ArrayList<>();
        String[] all = {Manifest.permission.CAMERA, Manifest.permission.RECORD_AUDIO, Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION};
        for (String p : all) if (!granted(p)) need.add(p);
        if (Build.VERSION.SDK_INT >= 33 && !granted("android.permission.POST_NOTIFICATIONS")) need.add("android.permission.POST_NOTIFICATIONS");
        sp.edit().putBoolean("permsAsked", true).apply();
        if (!need.isEmpty()) requestPermissions(need.toArray(new String[0]), REQ_FIRST_RUN);
    }

    @Override
    public void onRequestPermissionsResult(int code, String[] perms, int[] results) {
        super.onRequestPermissionsResult(code, perms, results);
        if (code == REQ_MEDIA && pendingMedia != null) {
            grantAvailable(pendingMedia);
            pendingMedia = null;
        } else if (code == REQ_GEO && pendingGeoCb != null) {
            boolean ok = granted(Manifest.permission.ACCESS_FINE_LOCATION) || granted(Manifest.permission.ACCESS_COARSE_LOCATION);
            pendingGeoCb.invoke(pendingGeoOrigin, ok, false);
            pendingGeoCb = null;
            pendingGeoOrigin = null;
        }
    }

    @Override
    protected void onActivityResult(int req, int res, Intent data) {
        if (req == REQ_FILE && fileCallback != null) {
            fileCallback.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(res, data));
            fileCallback = null;
            return;
        }
        super.onActivityResult(req, res, data);
    }

    // ------------------------------------------------------------------ menu

    @Override
    public boolean onCreateOptionsMenu(Menu m) {
        m.add(Menu.NONE, 1, 1, "Reload");
        m.add(Menu.NONE, 2, 2, "Log out");
        m.add(Menu.NONE, 3, 3, "About");
        return true;
    }

    @Override
    public boolean onOptionsItemSelected(MenuItem item) {
        switch (item.getItemId()) {
            case 1:
                if (web.getVisibility() == View.VISIBLE) web.reload(); else refresh();
                return true;
            case 2:
                new AlertDialog.Builder(this).setTitle("Log out")
                        .setMessage("This signs you out of the HMS site on this phone.")
                        .setNegativeButton("Cancel", null)
                        .setPositiveButton("Log out", (d, w) -> {
                            CookieManager.getInstance().removeAllCookies(null);
                            CookieManager.getInstance().flush();
                            WebStorage.getInstance().deleteAllData();
                            web.clearCache(true);
                            if (loadedBase != null) web.loadUrl(loadedBase);
                        }).show();
                return true;
            case 3:
                String until = lastState.expiresAt > 0 ? DateFormat.getDateTimeInstance().format(new Date(lastState.expiresAt)) : "no end date";
                String err = policy.getLastError();
                new AlertDialog.Builder(this).setTitle("About HMS")
                        .setMessage("Version " + BuildConfig.VERSION_NAME + "\nSite: " + (loadedBase == null ? "-" : loadedBase)
                                + "\nAccess until: " + until + "\nDevice ID: " + policy.installId
                                + (err != null ? "\nLast server check failed: " + err : ""))
                        .setPositiveButton("OK", null).show();
                return true;
            default:
                return super.onOptionsItemSelected(item);
        }
    }
}
