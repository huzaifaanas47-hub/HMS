package com.talha.hms;

import android.Manifest;
import android.app.Activity;
import android.app.Dialog;
import android.content.ContentValues;
import android.os.Message;
import android.print.PrintAttributes;
import android.print.PrintManager;
import android.provider.MediaStore;
import android.util.Base64;
import android.view.KeyEvent;
import android.webkit.DownloadListener;
import android.webkit.JavascriptInterface;
import android.webkit.RenderProcessGoneDetail;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.OutputStream;
import java.util.HashMap;
import java.util.Map;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.ColorDrawable;
import android.graphics.drawable.GradientDrawable;
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
import android.view.Window;
import android.view.WindowManager;
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
import android.webkit.WebResourceError;
import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.IntentFilter;
import android.database.Cursor;
import android.provider.Settings;
import androidx.core.content.FileProvider;
import java.io.File;
import java.io.FileInputStream;
import java.security.MessageDigest;
import android.webkit.WebViewClient;
import android.app.KeyguardManager;
import android.widget.Button;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.HorizontalScrollView;
import android.widget.LinearLayout;
import android.widget.PopupMenu;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import androidx.webkit.ProfileStore;
import androidx.webkit.WebViewCompat;
import androidx.webkit.WebViewFeature;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.net.URI;
import java.text.DateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * HMS on Android. Like the desktop app it shows several "roles" (tabs); every role has its own WebView profile,
 * so each one keeps its own login, cookies and storage and several people/roles can be signed in at once.
 */
public class MainActivity extends Activity {
    private static final int REQ_UNLOCK = 300;
    private static final int REQ_FIRST_RUN = 100, REQ_MEDIA = 101, REQ_GEO = 102, REQ_FILE = 200;
    private static final long CHECK_EVERY_MS = 10 * 60 * 1000L;
    private static final int MAX_TABS = 20;

    private static final class Tab {
        String id, label, url; // url: a path such as "/" or "/login", or a full https:// address
        WebView web;
        TextView button;
        String profile() { return "role_" + id; }
    }

    private Policy policy;
    private Credentials credentials;
    private String autofillJs;
    private SharedPreferences sp;
    private boolean multiProfile;

    private final List<Tab> tabs = new ArrayList<>();
    private String activeId;
    private LinearLayout column, topBar;
    private HorizontalScrollView tabScroll;
    private LinearLayout tabBar;
    private FrameLayout holder; // the WebViews
    private LinearLayout overlay;
    private TextView titleView, textView, msgView, errorView;
    private EditText codeInput;
    private Button joinBtn, retryBtn;

    private final Handler ui = new Handler(Looper.getMainLooper());
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final Runnable ticker = new Runnable() {
        @Override public void run() { refresh(); ui.postDelayed(this, CHECK_EVERY_MS); }
    };

    private String loadedBase;
    private final Set<String> allowedOrigins = new HashSet<>();
    private Policy.State lastState = new Policy.State();

    private ValueCallback<Uri[]> fileCallback;
    private final Map<WebView, Dialog> popups = new HashMap<>(); // windows opened by the site (window.open / target=_blank)
    private String bridgeJs;
    private long lastRenderRestart;
    private PermissionRequest pendingMedia;
    private GeolocationPermissions.Callback pendingGeoCb;
    private String pendingGeoOrigin;

    // ------------------------------------------------------------------ lifecycle

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        policy = new Policy(this);
        credentials = new Credentials(this);
        sp = getSharedPreferences("hms", MODE_PRIVATE);
        final Thread.UncaughtExceptionHandler prevHandler = Thread.getDefaultUncaughtExceptionHandler();
        Thread.setDefaultUncaughtExceptionHandler((th, ex) -> { // noted for the admin panel, then the normal crash handling continues
            try {
                StackTraceElement[] st = ex.getStackTrace();
                recordError("Crash: " + ex.getClass().getSimpleName() + ": " + ex.getMessage() + (st.length > 0 ? " at " + st[0] : ""));
            } catch (Throwable ignored) { }
            if (prevHandler != null) prevHandler.uncaughtException(th, ex);
        });
        multiProfile = WebViewFeature.isFeatureSupported(WebViewFeature.MULTI_PROFILE);
        setUpEdgeToEdge();
        applyAppLockFlags();
        loadTabs();
        buildUi();
        rebuildTabBar();
        askAllPermissionsOnce();
        apply(policy.evaluate(), null);
        handleJoinIntent(getIntent());
        if (!multiProfile && !sp.getBoolean("warnedProfiles", false)) {
            sp.edit().putBoolean("warnedProfiles", true).apply();
            new AlertDialog.Builder(this).setTitle("Update Android System WebView")
                    .setMessage("This phone's WebView is too old to keep roles separate, so only one role works for now. Update \"Android System WebView\" in Google Play to use several roles at once.")
                    .setPositiveButton("OK", null).show();
        }
    }

    @Override protected void onResume() {
        super.onResume();
        ui.post(ticker);
        enforceAppLock();
        if (installAfterPermission && Build.VERSION.SDK_INT >= 26 && getPackageManager().canRequestPackageInstalls() && readyApk != null) { installAfterPermission = false; installApk(readyApk); }
        else if (lastState != null && lastState.updateFile != null) startUpdateDownload(lastState); // finished while the app was closed? offer it now
    }
    @Override protected void onStop() { super.onStop(); if (!authenticating) { leftAt = System.currentTimeMillis(); lockCanceled = false; } }
    @Override protected void onNewIntent(Intent in) { super.onNewIntent(in); setIntent(in); handleJoinIntent(in); }
    @Override protected void onPause() { super.onPause(); ui.removeCallbacks(ticker); }
    @Override protected void onDestroy() {
        try { if (dlReceiver != null) unregisterReceiver(dlReceiver); } catch (Exception ignored) { }
        super.onDestroy();
        io.shutdownNow();
    }

    @Override
    public void onBackPressed() {
        Tab t = active();
        if (t != null && t.web != null && t.web.getVisibility() == View.VISIBLE && t.web.canGoBack()) t.web.goBack();
        else super.onBackPressed();
    }

    // ------------------------------------------------------------------ roles (tabs)

    private void loadTabs() {
        tabs.clear();
        try {
            JSONArray a = new JSONArray(sp.getString("tabs", "[]"));
            for (int i = 0; i < a.length(); i++) {
                JSONObject o = a.getJSONObject(i);
                Tab t = new Tab();
                t.id = o.getString("id");
                t.label = o.getString("label");
                t.url = o.optString("url", "/");
                tabs.add(t);
            }
        } catch (Exception ignored) { }
        if (tabs.isEmpty()) {
            Tab t = new Tab(); // one role by default, like the desktop app; add more with the + button
            t.id = "role1";
            t.label = "Main";
            t.url = "/";
            tabs.add(t);
            saveTabs();
        }
        activeId = tabs.get(0).id;
    }

    private void saveTabs() {
        try {
            JSONArray a = new JSONArray();
            for (Tab t : tabs) a.put(new JSONObject().put("id", t.id).put("label", t.label).put("url", t.url));
            sp.edit().putString("tabs", a.toString()).apply();
        } catch (Exception ignored) { }
    }

    private Tab active() {
        for (Tab t : tabs) if (t.id.equals(activeId)) return t;
        return null;
    }

    // "/" = the site address exactly as entered (keeps a sub-folder and index.php);
    // "/page.php" = page.php inside the site address's folder.
    private String tabUrl(Tab t, String base) {
        String u = t.url == null ? "" : t.url.trim();
        if (u.startsWith("http://") || u.startsWith("https://")) return u;
        try {
            URI b = new URI(base);
            if (u.isEmpty() || u.equals("/")) return b.toString();
            String path = b.getRawPath() == null ? "" : b.getRawPath();
            String dir = path.substring(0, path.lastIndexOf('/') + 1);
            if (dir.isEmpty()) dir = "/";
            String rel = u.startsWith("/") ? u.substring(1) : u;
            return b.getScheme() + "://" + b.getRawAuthority() + dir + rel;
        } catch (Exception e) { return base; }
    }

    private void computeAllowed(String base) {
        allowedOrigins.clear();
        String o = originOf(base);
        if (o != null) allowedOrigins.add(o);
        for (Tab t : tabs) {
            if (t.url.startsWith("http")) { String x = originOf(t.url); if (x != null) allowedOrigins.add(x); }
        }
    }

    private void rebuildTabBar() {
        tabBar.removeAllViews();
        for (int i = 0; i < tabs.size(); i++) {
            if (!multiProfile && i > 0) break; // old WebView: one role only
            final Tab t = tabs.get(i);
            TextView v = new TextView(this);
            v.setText(t.label);
            v.setTextSize(14);
            v.setGravity(Gravity.CENTER);
            v.setPadding(dp(18), 0, dp(18), 0);
            v.setSingleLine(true);
            v.setMinWidth(dp(88));
            v.setOnClickListener(x -> selectTab(t.id));
            v.setOnLongClickListener(x -> { roleOptions(t); return true; });
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(30));
            lp.setMargins(dp(3), dp(6), dp(3), dp(6));
            tabBar.addView(v, lp);
            t.button = v;
        }
        if (multiProfile && tabs.size() < MAX_TABS) {
            TextView plus = new TextView(this);
            plus.setText("+");
            plus.setTextSize(20);
            plus.setGravity(Gravity.CENTER);
            plus.setTextColor(Color.parseColor("#9AA6BF"));
            plus.setOnClickListener(x -> roleDialog(null));
            LinearLayout.LayoutParams plp = new LinearLayout.LayoutParams(dp(40), dp(30));
            plp.setMargins(dp(3), dp(6), dp(3), dp(6));
            tabBar.addView(plus, plp);
        }
        styleTabs();
    }

    private void styleTabs() {
        for (Tab t : tabs) {
            if (t.button == null) continue;
            boolean on = t.id.equals(activeId);
            GradientDrawable bg = new GradientDrawable();
            bg.setCornerRadius(dp(16));
            bg.setColor(on ? Color.parseColor("#3B82F6") : Color.parseColor("#1A2133"));
            t.button.setBackground(bg);
            t.button.setTextColor(on ? Color.WHITE : Color.parseColor("#9AA6BF"));
            t.button.setTypeface(null, on ? Typeface.BOLD : Typeface.NORMAL);
        }
    }

    private void selectTab(String id) {
        activeId = id;
        styleTabs();
        if (!"ok".equals(lastState.state) || loadedBase == null) return;
        for (Tab t : tabs) {
            boolean on = t.id.equals(id);
            if (on && t.web == null) { t.web = createWebView(t); t.web.loadUrl(tabUrl(t, loadedBase)); }
            if (t.web != null) t.web.setVisibility(on ? View.VISIBLE : View.GONE);
        }
        Tab a = active();
        if (a != null && a.button != null) tabScroll.post(() -> tabScroll.smoothScrollTo(Math.max(0, a.button.getLeft() - dp(40)), 0));
    }

    private void roleOptions(final Tab t) {
        final boolean hasLogin = credentials.has(t.id);
        final List<String> items = new ArrayList<>();
        items.add("Rename, change page or saved login");
        items.add("Log out this role");
        if (hasLogin) items.add("Forget saved login");
        items.add("Remove this role");
        new AlertDialog.Builder(this).setTitle(t.label)
                .setItems(items.toArray(new String[0]), (d, which) -> {
                    String what = items.get(which);
                    if (which == 0) roleDialog(t);
                    else if (which == 1) logoutRole(t);
                    else if (what.startsWith("Forget")) {
                        credentials.delete(t.id);
                        Toast.makeText(this, "Saved login removed for " + t.label + ".", Toast.LENGTH_SHORT).show();
                    } else removeRole(t);
                }).show();
    }

    private void roleDialog(final Tab existing) {
        if (existing == null && !multiProfile) {
            Toast.makeText(this, "Update Android System WebView in Google Play to add more roles.", Toast.LENGTH_LONG).show();
            return;
        }
        if (existing == null && tabs.size() >= MAX_TABS) { Toast.makeText(this, "You can have up to " + MAX_TABS + " roles.", Toast.LENGTH_SHORT).show(); return; }
        final Credentials.Saved saved = existing != null ? credentials.get(existing.id) : new Credentials.Saved();

        ScrollView scroll = new ScrollView(this);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(20), dp(8), dp(20), 0);
        scroll.addView(box);
        final EditText name = new EditText(this);
        name.setHint("Role name, e.g. Reception");
        name.setSingleLine(true);
        name.setText(existing != null ? existing.label : "Role " + (tabs.size() + 1));
        final EditText page = new EditText(this);
        page.setHint("Page: / or /login or https://...");
        page.setSingleLine(true);
        page.setText(existing != null ? existing.url : "/");
        final EditText email = new EditText(this);
        email.setHint("Login email / username (optional)");
        email.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);
        email.setSingleLine(true);
        email.setText(saved.email);
        final EditText pass = new EditText(this);
        pass.setHint(saved.password.isEmpty() ? "Password (optional)" : "Password saved - leave blank to keep it");
        pass.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        pass.setSingleLine(true);
        if (Build.VERSION.SDK_INT >= 26) {
            email.setImportantForAutofill(View.IMPORTANT_FOR_AUTOFILL_NO);
            pass.setImportantForAutofill(View.IMPORTANT_FOR_AUTOFILL_NO);
        }
        TextView note = new TextView(this);
        note.setText("The login is stored encrypted on this phone only. It is filled in on this role's login page every time the app opens, and again whenever the site signs you out.");
        note.setTextSize(12);
        note.setPadding(0, dp(10), 0, dp(6));
        box.addView(name);
        box.addView(page);
        box.addView(email);
        box.addView(pass);
        box.addView(note);

        new AlertDialog.Builder(this).setTitle(existing == null ? "Add a role" : "Edit role").setView(scroll)
                .setNegativeButton("Cancel", null)
                .setPositiveButton("Save", (d, w) -> {
                    String label = name.getText().toString().trim();
                    String url = page.getText().toString().trim();
                    String em = email.getText().toString().trim();
                    String pw = pass.getText().toString();
                    if (url.isEmpty()) url = "/";
                    if (label.isEmpty() || label.length() > 30) { Toast.makeText(this, "Enter a role name (up to 30 letters).", Toast.LENGTH_LONG).show(); return; }
                    if (!(url.startsWith("/") || url.startsWith("https://") || url.startsWith("http://"))) {
                        Toast.makeText(this, "The page must start with / or https://", Toast.LENGTH_LONG).show();
                        return;
                    }
                    if (em.length() > 200 || pw.length() > 200) { Toast.makeText(this, "The login is too long.", Toast.LENGTH_LONG).show(); return; }
                    boolean loginChanged = !em.equals(saved.email) || !pw.isEmpty();
                    if (existing != null) {
                        boolean moved = !url.equals(existing.url);
                        existing.label = label;
                        existing.url = url;
                        saveTabs();
                        if (loginChanged && !credentials.save(existing.id, em, pw)) {
                            Toast.makeText(this, "The login could not be saved securely on this phone.", Toast.LENGTH_LONG).show();
                            loginChanged = false;
                        }
                        rebuildTabBar();
                        if (loadedBase != null) computeAllowed(loadedBase);
                        if ((moved || loginChanged) && existing.web != null && loadedBase != null) existing.web.loadUrl(tabUrl(existing, loadedBase));
                    } else {
                        Tab t = new Tab();
                        t.id = "r" + System.currentTimeMillis();
                        t.label = label;
                        t.url = url;
                        tabs.add(t);
                        saveTabs();
                        if ((!em.isEmpty() || !pw.isEmpty()) && !credentials.save(t.id, em, pw)) {
                            Toast.makeText(this, "The login could not be saved securely on this phone.", Toast.LENGTH_LONG).show();
                        }
                        rebuildTabBar();
                        if (loadedBase != null) computeAllowed(loadedBase);
                        selectTab(t.id);
                    }
                }).show();
    }

    private void removeRole(final Tab t) {
        if (tabs.size() <= 1) { Toast.makeText(this, "Keep at least one role.", Toast.LENGTH_SHORT).show(); return; }
        new AlertDialog.Builder(this).setTitle("Remove " + t.label + "?")
                .setMessage("Its session and saved login on this phone are deleted.")
                .setNegativeButton("Cancel", null)
                .setPositiveButton("Remove", (d, w) -> {
                    if (t.web != null) { holder.removeView(t.web); t.web.destroy(); t.web = null; }
                    if (multiProfile) { try { ProfileStore.getInstance().deleteProfile(t.profile()); } catch (Exception ignored) { } }
                    credentials.delete(t.id);
                    tabs.remove(t);
                    if (t.id.equals(activeId)) activeId = tabs.get(0).id;
                    saveTabs();
                    rebuildTabBar();
                    selectTab(activeId);
                }).show();
    }

    private CookieManager cookies(Tab t) {
        if (multiProfile) return ProfileStore.getInstance().getOrCreateProfile(t.profile()).getCookieManager();
        return CookieManager.getInstance();
    }

    private void clearSession(Tab t) {
        CookieManager cm = cookies(t);
        cm.removeAllCookies(null);
        cm.flush();
        if (multiProfile) ProfileStore.getInstance().getOrCreateProfile(t.profile()).getWebStorage().deleteAllData();
        else WebStorage.getInstance().deleteAllData();
        if (t.web != null) t.web.clearCache(true);
    }

    private void logoutRole(final Tab t) {
        new AlertDialog.Builder(this).setTitle("Log out " + t.label + "?")
                .setMessage("This signs out of the HMS site for this role only. The saved login stays and is filled in again on the login page.")
                .setNegativeButton("Cancel", null)
                .setPositiveButton("Log out", (d, w) -> {
                    clearSession(t);
                    if (t.web != null && loadedBase != null) t.web.loadUrl(tabUrl(t, loadedBase));
                }).show();
    }

    private void logoutAll() {
        new AlertDialog.Builder(this).setTitle("Log out all roles?")
                .setMessage("Every role is signed out on this phone.")
                .setNegativeButton("Cancel", null)
                .setPositiveButton("Log out all", (d, w) -> {
                    for (Tab t : tabs) {
                        if (!multiProfile && t != tabs.get(0)) continue;
                        clearSession(t);
                        if (t.web != null && loadedBase != null) t.web.loadUrl(tabUrl(t, loadedBase));
                    }
                }).show();
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
            String base = (s.baseUrl != null && !s.baseUrl.isEmpty()) ? s.baseUrl : BuildConfig.DEFAULT_URL;
            if (base == null || base.isEmpty()) { lockUi("No site address", "Your administrator has not set the site address yet.", null, false, false); return; }
            overlay.setVisibility(View.GONE);
            setNavBar(false);
            tabScroll.setVisibility(View.VISIBLE);
            holder.setVisibility(View.VISIBLE);
            if (!base.equals(loadedBase)) {
                boolean first = loadedBase == null;
                loadedBase = base;
                computeAllowed(base);
                if (!first) for (Tab t : tabs) if (t.web != null) t.web.loadUrl(tabUrl(t, base)); // site address changed
            }
            selectTab(activeId);
            maybePromptUpdate(s);
            startUpdateDownload(s);
            maybeShowNotice(s);
            pendingJoinCode = null; // already set up: a scanned code is not needed
            if (!sp.getBoolean("tipLogin", false)) {
                sp.edit().putBoolean("tipLogin", true).apply();
                Toast.makeText(this, "Tip: long-press a role tab to save its login.", Toast.LENGTH_LONG).show();
            }
            return;
        }
        // Not allowed to use HMS right now: hide every role.
        loadedBase = null;
        allowedOrigins.clear();
        tabScroll.setVisibility(View.GONE);
        holder.setVisibility(View.GONE);
        for (Tab t : tabs) if (t.web != null) t.web.loadUrl("about:blank");
        String date = s.expiresAt > 0 ? DateFormat.getDateTimeInstance().format(new Date(s.expiresAt)) : "";
        switch (s.state) {
            case "enroll":
                lockUi("Enter your access code", "Your administrator gave you a code like ABCD-EFGH. You only need to enter it once.", null, true, false);
                usePendingCode(); // opened from the QR / join link: the code is filled in and sent for the user
                break;
            case "expired":
                lockUi("Access has expired", s.expiresAt > 0 ? "Your access to HMS ended on " + date + "." : "Your access to HMS has ended.", s.message, false, true);
                break;
            case "blocked":
                lockUi("Access has been disabled", "The administrator has disabled access for this device.", s.message, false, true);
                break;
            case "stale":
                lockUi("Verification required", "HMS has not been able to reach the administration server for too long. Connect to the internet and check again.", null, false, true);
                break;
            default: // unverified: first start, no answer from the server yet
                if (err != null) lockUi("Cannot reach the server", "Check your internet connection and try again.", null, false, true);
                else lockUi("Connecting...", "Checking with the HMS server.", null, false, false);
        }
    }

    /** A build uploaded in the admin panel is announced in the signed answer: ask at every app start until it is installed, and keep a menu entry. */
    // ------------------------------------------------------------------ in-app update: download in the background, then ask to install

    private File readyApk;                    // a verified download of the newest version, ready to install
    private boolean installAfterPermission = false, updPromptShownThisStart = false, updChecking = false;
    private BroadcastReceiver dlReceiver;

    private File updateFileFor(String version) {
        File dir = getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS);
        return dir == null ? null : new File(dir, "HMS-" + version.replaceAll("[^0-9A-Za-z.]", "_") + ".apk");
    }

    private static String sha256(File f) throws Exception {
        MessageDigest md = MessageDigest.getInstance("SHA-256");
        try (FileInputStream in = new FileInputStream(f)) {
            byte[] b = new byte[65536];
            int n;
            while ((n = in.read(b)) > 0) md.update(b, 0, n);
        }
        StringBuilder sb = new StringBuilder();
        for (byte x : md.digest()) sb.append(String.format("%02x", x));
        return sb.toString();
    }

    /** Called with every check-in answer: starts the download of a newer version (once), or offers the finished one. */
    private void startUpdateDownload(final Policy.State s) {
        try {
            if (s.updateVersion == null || s.updateFile == null || s.updateSha == null) { readyApk = null; cleanOldUpdates(null); return; }
            final File f = updateFileFor(s.updateVersion);
            if (f == null) return;
            cleanOldUpdates(f.getName());
            if (dlReceiver == null) {
                dlReceiver = new BroadcastReceiver() {
                    @Override public void onReceive(Context c, Intent i) { if (lastState != null && lastState.updateFile != null) startUpdateDownload(lastState); }
                };
                registerReceiver(dlReceiver, new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE));
            }
            DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            if (dm == null) return;
            long id = sp.getLong("updDlId", -1);
            String idVer = sp.getString("updDlVer", "");
            int status = -1;
            if (id != -1 && idVer.equals(s.updateVersion)) {
                Cursor c = dm.query(new DownloadManager.Query().setFilterById(id));
                try { if (c != null && c.moveToFirst()) status = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS)); }
                finally { if (c != null) c.close(); }
            }
            if (status == DownloadManager.STATUS_RUNNING || status == DownloadManager.STATUS_PENDING || status == DownloadManager.STATUS_PAUSED) return; // already on its way
            if (f.isFile() && status != DownloadManager.STATUS_FAILED) { verifyAndOffer(f, s); return; } // finished now, or left from an earlier run
            long failAt = sp.getLong("updDlFailAt", 0);
            if (failAt != 0 && System.currentTimeMillis() - failAt < 3600000L) return; // failed lately: try again later
            if (f.exists()) f.delete();
            DownloadManager.Request r = new DownloadManager.Request(Uri.parse(s.updateFile));
            r.setTitle("HMS " + s.updateVersion);
            r.setDescription("Downloading the update");
            r.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            r.setDestinationInExternalFilesDir(this, Environment.DIRECTORY_DOWNLOADS, f.getName());
            long nid = dm.enqueue(r);
            sp.edit().putLong("updDlId", nid).putString("updDlVer", s.updateVersion).putLong("updDlFailAt", 0).apply();
            Toast.makeText(this, "Downloading HMS " + s.updateVersion + " in the background...", Toast.LENGTH_LONG).show();
        } catch (Exception e) {
            sp.edit().putLong("updDlFailAt", System.currentTimeMillis()).apply(); // never bother the user; the menu entry still opens the download page
        }
    }

    private void verifyAndOffer(final File f, final Policy.State s) {
        if (updChecking) return;
        updChecking = true;
        new Thread(() -> {
            boolean ok = false;
            try { ok = sha256(f).equalsIgnoreCase(s.updateSha); } catch (Exception ignored) { }
            final boolean good = ok;
            ui.post(() -> {
                updChecking = false;
                if (!good) { f.delete(); sp.edit().putLong("updDlFailAt", System.currentTimeMillis()).apply(); return; } // damaged or wrong file: never install it
                readyApk = f;
                if (!isFinishing() && !updPromptShownThisStart) {
                    updPromptShownThisStart = true;
                    new AlertDialog.Builder(MainActivity.this).setTitle("Update ready")
                            .setMessage("HMS " + s.updateVersion + " has been downloaded. Please install it to keep using HMS.")
                            .setPositiveButton("Install", (d, w) -> installApk(f))
                            .setNegativeButton("Later", null).show();
                }
            });
        }).start();
    }

    private void installApk(File f) {
        try {
            if (Build.VERSION.SDK_INT >= 26 && !getPackageManager().canRequestPackageInstalls()) {
                installAfterPermission = true;
                Toast.makeText(this, "Allow HMS to install updates, then come back.", Toast.LENGTH_LONG).show();
                startActivity(new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + getPackageName())));
                return;
            }
            Uri uri = FileProvider.getUriForFile(this, getPackageName() + ".updates", f);
            Intent i = new Intent(Intent.ACTION_VIEW);
            i.setDataAndType(uri, "application/vnd.android.package-archive");
            i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            startActivity(i);
        } catch (Exception e) {
            Toast.makeText(this, "Could not start the installer. Open the download page from the menu.", Toast.LENGTH_LONG).show();
            if (lastState.updateUrl != null) openUpdate(lastState.updateUrl);
        }
    }

    /** Removes older downloaded updates (keeps only the file named keep; null = remove all). */
    private void cleanOldUpdates(String keep) {
        try {
            File dir = getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS);
            File[] list = dir == null ? null : dir.listFiles();
            if (list == null) return;
            for (File x : list) if (x.getName().startsWith("HMS-") && x.getName().endsWith(".apk") && !x.getName().equals(keep)) x.delete();
        } catch (Exception ignored) { }
    }

    // ------------------------------------------------------------------ app lock, join link, offline page

    private static final long LOCK_GRACE_MS = 60000L; // back within a minute: no question asked
    private boolean unlocked = false, authenticating = false, lockCanceled = false;
    private long leftAt = 0;
    private View lockCover;
    private String pendingJoinCode;
    private final Set<WebView> offlineViews = new HashSet<>();

    private boolean appLockOn() { return sp.getBoolean("appLock", false); }

    /** With App lock on, the recent-apps preview is blank and screenshots are blocked. */
    private void applyAppLockFlags() {
        if (sp == null) sp = getSharedPreferences("hms", MODE_PRIVATE);
        if (appLockOn()) getWindow().addFlags(WindowManager.LayoutParams.FLAG_SECURE);
        else getWindow().clearFlags(WindowManager.LayoutParams.FLAG_SECURE);
    }

    private boolean deviceHasScreenLock() {
        KeyguardManager km = (KeyguardManager) getSystemService(KEYGUARD_SERVICE);
        return km != null && km.isDeviceSecure();
    }

    private void toggleAppLock() {
        if (appLockOn()) {
            sp.edit().putBoolean("appLock", false).apply();
            applyAppLockFlags();
            if (lockCover != null) lockCover.setVisibility(View.GONE);
            Toast.makeText(this, "App lock is off.", Toast.LENGTH_SHORT).show();
        } else if (!deviceHasScreenLock()) {
            Toast.makeText(this, "First set a screen lock (PIN, pattern or fingerprint) in the phone settings.", Toast.LENGTH_LONG).show();
        } else {
            sp.edit().putBoolean("appLock", true).apply();
            applyAppLockFlags();
            unlocked = true;
            leftAt = System.currentTimeMillis();
            Toast.makeText(this, "App lock is on. HMS asks for your phone PIN, pattern or fingerprint when you come back after a minute.", Toast.LENGTH_LONG).show();
        }
    }

    private void enforceAppLock() {
        if (!appLockOn()) { if (lockCover != null) lockCover.setVisibility(View.GONE); return; }
        if (authenticating) return;
        if (unlocked && System.currentTimeMillis() - leftAt < LOCK_GRACE_MS) { if (lockCover != null) lockCover.setVisibility(View.GONE); return; }
        unlocked = false;
        showLockCover();
        if (!lockCanceled) askDeviceCredential();
    }

    private void showLockCover() {
        if (lockCover == null) {
            LinearLayout box = new LinearLayout(this);
            box.setOrientation(LinearLayout.VERTICAL);
            box.setGravity(Gravity.CENTER);
            box.setBackgroundColor(Color.parseColor("#0F1420"));
            box.setClickable(true); // swallows touches so nothing underneath can be used
            TextView tv = new TextView(this);
            tv.setText("HMS is locked");
            tv.setTextColor(Color.WHITE);
            tv.setTextSize(22);
            tv.setGravity(Gravity.CENTER);
            Button b = new Button(this);
            b.setText("Unlock");
            b.setOnClickListener(v -> { lockCanceled = false; askDeviceCredential(); });
            box.addView(tv);
            box.addView(b);
            addContentView(box, new ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
            lockCover = box;
        }
        lockCover.setVisibility(View.VISIBLE);
        lockCover.bringToFront();
    }

    private void askDeviceCredential() {
        if (authenticating) return;
        KeyguardManager km = (KeyguardManager) getSystemService(KEYGUARD_SERVICE);
        Intent i = (km != null && km.isDeviceSecure()) ? km.createConfirmDeviceCredentialIntent("HMS is locked", "Confirm it is you to open HMS") : null;
        if (i == null) { // the phone's screen lock was removed: App lock cannot work, so turn it off
            sp.edit().putBoolean("appLock", false).apply();
            applyAppLockFlags();
            if (lockCover != null) lockCover.setVisibility(View.GONE);
            Toast.makeText(this, "App lock turned off: this phone has no screen lock any more.", Toast.LENGTH_LONG).show();
            return;
        }
        authenticating = true;
        try { startActivityForResult(i, REQ_UNLOCK); }
        catch (ActivityNotFoundException ex) { authenticating = false; lockCanceled = true; }
    }

    /** Opened from the QR code / join page (hms://join?code=ABCD-EFGH): the code is typed in for the user. */
    private void handleJoinIntent(Intent in) {
        if (in == null || in.getData() == null) return;
        Uri u = in.getData();
        if (!"hms".equals(u.getScheme()) || !"join".equals(u.getHost())) return;
        String c = u.getQueryParameter("code");
        in.setData(null); // used once
        if (c == null || !c.matches("[A-Za-z0-9-]{4,12}")) return;
        pendingJoinCode = c.toUpperCase();
        if ("enroll".equals(lastState.state)) usePendingCode();
        else if ("ok".equals(lastState.state)) {
            pendingJoinCode = null;
            Toast.makeText(this, "This phone is already set up.", Toast.LENGTH_LONG).show();
        }
    }

    private void usePendingCode() {
        if (pendingJoinCode == null || codeInput == null) return;
        String c = pendingJoinCode;
        pendingJoinCode = null;
        codeInput.setText(c);
        submitCode();
    }

    private static String offlineHtml(String url) {
        String u = url.replace("&", "&amp;").replace("\"", "&quot;").replace("<", "&lt;").replace(">", "&gt;");
        return "<html><head><meta name='viewport' content='width=device-width,initial-scale=1'></head>"
                + "<body style='margin:0;background:#0F1420;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center'>"
                + "<div style='padding:28px;max-width:420px'><h2 style='margin:12px 0 8px'>No internet connection</h2>"
                + "<p style='color:#B7C0D0;line-height:1.5'>HMS cannot reach the server. Check your Wi-Fi or mobile data. HMS tries again by itself every few seconds.</p>"
                + "<a href=\"" + u + "\" style='display:inline-block;margin-top:14px;padding:12px 26px;background:#3B82F6;color:#fff;border-radius:10px;text-decoration:none;font-weight:bold'>Try again</a></div></body></html>";
    }

    private String noticeShown = null; // notice text already shown in this run; shown again at the next app start while the admin keeps it

    private void maybeShowNotice(final Policy.State s) {
        if (s.notice == null || s.notice.isEmpty()) { noticeShown = null; return; }
        if (isFinishing() || s.notice.equals(noticeShown)) return;
        noticeShown = s.notice;
        new AlertDialog.Builder(this).setTitle("Message from your administrator")
                .setMessage(s.notice).setPositiveButton("OK", null).show();
    }

    private boolean updShownThisStart = false;

    private void maybePromptUpdate(final Policy.State s) {
        if (s.updateFile != null && s.updateSha != null) return; // the in-app download handles it
        if (s.updateVersion == null || s.updateUrl == null || isFinishing()) return;
        if (updShownThisStart) return; // once per app start (not on every 10-minute check), but again at every next start until installed
        updShownThisStart = true;
        String notes = (s.updateNotes != null && !s.updateNotes.isEmpty()) ? "\n\n" + s.updateNotes : "";
        new AlertDialog.Builder(this).setTitle("Update available")
                .setMessage("HMS " + s.updateVersion + " is ready to download." + notes + "\n\nInstall it over this app: your roles, saved logins and access code stay.")
                .setPositiveButton("Download", (d, w) -> openUpdate(s.updateUrl))
                .setNegativeButton("Later", null).show();
    }

    private void openUpdate(String url) {
        try { startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url))); }
        catch (ActivityNotFoundException e) { Toast.makeText(this, "No browser found to open the download.", Toast.LENGTH_LONG).show(); }
    }

    private void lockUi(String title, String text, String msg, boolean askCode, boolean showRetry) {
        titleView.setText(title);
        textView.setText(text);
        msgView.setText(msg == null ? "" : msg);
        errorView.setText("");
        codeInput.setVisibility(askCode ? View.VISIBLE : View.GONE);
        joinBtn.setVisibility(askCode ? View.VISIBLE : View.GONE);
        retryBtn.setVisibility(showRetry ? View.VISIBLE : View.GONE);
        overlay.setVisibility(View.VISIBLE);
        setNavBar(true);
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

    /** Full-screen layout: no action bar, the page runs to the screen edges and the top bar sits under the status bar. */
    private void setUpEdgeToEdge() {
        Window w = getWindow();
        w.setBackgroundDrawable(new ColorDrawable(Color.parseColor("#0F1420")));
        w.addFlags(WindowManager.LayoutParams.FLAG_DRAWS_SYSTEM_BAR_BACKGROUNDS);
        w.setStatusBarColor(Color.TRANSPARENT);
        if (Build.VERSION.SDK_INT >= 28) {
            WindowManager.LayoutParams lp = w.getAttributes();
            lp.layoutInDisplayCutoutMode = WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES; // no bars beside a camera notch in landscape
            w.setAttributes(lp);
        }
        w.getDecorView().setSystemUiVisibility(View.SYSTEM_UI_FLAG_LAYOUT_STABLE | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN);
        setNavBar(true);
    }

    /** The navigation bar blends in: white (dark icons) over the web page, dark over the lock screen. */
    private void setNavBar(boolean dark) {
        Window w = getWindow();
        if (Build.VERSION.SDK_INT < 26) return; // old phones cannot draw dark icons: keep the system bar
        w.setNavigationBarColor(dark ? Color.parseColor("#0F1420") : Color.WHITE);
        View d = w.getDecorView();
        int f = d.getSystemUiVisibility();
        d.setSystemUiVisibility(dark ? (f & ~View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR) : (f | View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR));
    }

    private int statusBarHeight() {
        int id = getResources().getIdentifier("status_bar_height", "dimen", "android");
        return id > 0 ? getResources().getDimensionPixelSize(id) : dp(24);
    }

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
        column = new LinearLayout(this);
        column.setOrientation(LinearLayout.VERTICAL);
        column.setBackgroundColor(Color.parseColor("#0F1420"));

        // Slim top bar: the roles on the left, the menu on the right. It also sits under the status bar, so nothing is wasted.
        topBar = new LinearLayout(this);
        topBar.setOrientation(LinearLayout.HORIZONTAL);
        topBar.setBackgroundColor(Color.parseColor("#151B2A"));
        tabScroll = new HorizontalScrollView(this);
        tabScroll.setHorizontalScrollBarEnabled(false);
        tabBar = new LinearLayout(this);
        tabBar.setOrientation(LinearLayout.HORIZONTAL);
        tabScroll.addView(tabBar, new ViewGroup.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, ViewGroup.LayoutParams.MATCH_PARENT));
        topBar.addView(tabScroll, new LinearLayout.LayoutParams(0, dp(42), 1f));
        TextView menuBtn = new TextView(this);
        menuBtn.setText("\u22EE");
        menuBtn.setTextSize(22);
        menuBtn.setGravity(Gravity.CENTER);
        menuBtn.setTextColor(Color.parseColor("#C5CDDF"));
        menuBtn.setOnClickListener(v -> showMenu(v));
        topBar.addView(menuBtn, new LinearLayout.LayoutParams(dp(44), dp(42)));
        topBar.setPadding(0, statusBarHeight(), 0, 0);
        column.addView(topBar, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        column.setOnApplyWindowInsetsListener((v, in) -> {
            topBar.setPadding(0, in.getSystemWindowInsetTop(), 0, 0);
            column.setPadding(in.getSystemWindowInsetLeft(), 0, in.getSystemWindowInsetRight(), 0);
            return in;
        });

        FrameLayout stack = new FrameLayout(this);
        holder = new FrameLayout(this);
        stack.addView(holder, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        overlay = new LinearLayout(this);
        overlay.setOrientation(LinearLayout.VERTICAL);
        overlay.setGravity(Gravity.CENTER);
        overlay.setBackgroundColor(Color.parseColor("#0F1420"));
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
        stack.addView(overlay, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        column.addView(stack, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        setContentView(column);
        column.requestApplyInsets();
    }

    /** One WebView per role, each in its own profile (own cookies, storage and permissions). */
    private WebView createWebView(final Tab t) {
        WebView web = new WebView(this);
        web.setBackgroundColor(Color.WHITE);
        web.setOverScrollMode(View.OVER_SCROLL_NEVER);
        if (multiProfile) {
            ProfileStore.getInstance().getOrCreateProfile(t.profile());
            WebViewCompat.setProfile(web, t.profile()); // must happen before the first load
        }
        holder.addView(web, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        s.setGeolocationEnabled(true);
        s.setSupportMultipleWindows(true);
        s.setJavaScriptCanOpenWindowsAutomatically(true);
        web.addJavascriptInterface(new HmsBridge(web), "HmsApp");
        CookieManager cm = cookies(t);
        cm.setAcceptCookie(true);
        cm.setAcceptThirdPartyCookies(web, true);

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest r) {
                if (isAllowed(r.getUrl().toString())) return false; // the HMS site stays inside the app
                try { startActivity(new Intent(Intent.ACTION_VIEW, r.getUrl())); } catch (ActivityNotFoundException ignored) { }
                return true;
            }

            // No internet / server unreachable: a friendly page that retries by itself, instead of a blank or ugly error.
            @Override
            public void onReceivedError(final WebView v, WebResourceRequest r, WebResourceError e) {
                if (!r.isForMainFrame()) return;
                int c = e.getErrorCode();
                if (c != WebViewClient.ERROR_HOST_LOOKUP && c != WebViewClient.ERROR_CONNECT && c != WebViewClient.ERROR_TIMEOUT && c != WebViewClient.ERROR_IO) return;
                final String failed = r.getUrl().toString();
                v.loadDataWithBaseURL(failed, offlineHtml(failed), "text/html", "utf-8", failed);
                offlineViews.add(v);
                ui.postDelayed(() -> { if (offlineViews.remove(v) && !isFinishing()) v.loadUrl(failed); }, 10000);
            }

            // Every page load (app start, after a log out, any redirect to the login page) fills this role's saved login.
            @Override
            public void onPageStarted(WebView v, String url, android.graphics.Bitmap icon) { injectBridge(v, url); }

            @Override
            public void onPageFinished(WebView v, String url) { injectBridge(v, url); autofill(t, v, url); }

            // The WebView's helper process died (memory, crash): without this the whole app would be killed.
            @android.annotation.TargetApi(26)
            @Override
            public boolean onRenderProcessGone(WebView v, RenderProcessGoneDetail d) {
                recordError("The page engine " + (d.didCrash() ? "crashed" : "was stopped by the system") + "; the app reloaded itself.");
                long now = System.currentTimeMillis();
                if (now - lastRenderRestart > 30000) { lastRenderRestart = now; ui.post(() -> { if (!isFinishing()) recreate(); }); }
                return true;
            }
        });

        web.setWebChromeClient(chromeClient(t));

        web.setDownloadListener(downloadListener(t, web));
        return web;
    }


    /** Chrome client shared by the role pages and by the windows they open: camera, microphone, location, file choosers, popups. */
    private WebChromeClient chromeClient(final Tab t) {
        return new WebChromeClient() {
            @Override
            public void onPermissionRequest(final PermissionRequest request) {
                runOnUiThread(() -> handleMediaRequest(request));
            }

            @Override
            public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback cb) {
                if (!trustedForPermission(origin)) { permissionRefused("location", origin); cb.invoke(origin, false, false); return; }
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

            @Override
            public boolean onCreateWindow(WebView view, boolean isDialog, boolean isUserGesture, Message resultMsg) {
                return openPopup(t, resultMsg);
            }

            @Override
            public void onCloseWindow(WebView window) { closePopup(window); }
        };
    }

    /** Downloads: normal links go to the system downloader; blob:/data: files (generated PDFs, exports) are saved by the page bridge. */
    private DownloadListener downloadListener(final Tab t, final WebView owner) {
        return (url, ua, cd, mime, len) -> {
            if (url != null && (url.startsWith("blob:") || url.startsWith("data:"))) {
                String name = URLUtil.guessFileName(url, cd, mime);
                owner.evaluateJavascript("window.__hmsSaveUrl&&window.__hmsSaveUrl(" + JSONObject.quote(url) + "," + JSONObject.quote(name) + "," + JSONObject.quote(mime == null ? "" : mime) + ")", null);
                return;
            }
            try {
                DownloadManager.Request r = new DownloadManager.Request(Uri.parse(url));
                r.setMimeType(mime);
                String cookie = cookies(t).getCookie(url);
                if (cookie != null) r.addRequestHeader("Cookie", cookie);
                r.addRequestHeader("User-Agent", ua);
                r.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                r.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, URLUtil.guessFileName(url, cd, mime));
                ((DownloadManager) getSystemService(DOWNLOAD_SERVICE)).enqueue(r);
                Toast.makeText(this, "Downloading...", Toast.LENGTH_SHORT).show();
            } catch (Exception e) {
                Toast.makeText(this, "The download could not start.", Toast.LENGTH_LONG).show();
            }
        };
    }

    // ------------------------------------------------------------------ print, save files, popups, help

    /** What the site can call from JavaScript (window.HmsApp): print, and save a generated file to Downloads. Only works on the HMS site itself. */
    private final class HmsBridge {
        private final WebView owner;
        HmsBridge(WebView owner) { this.owner = owner; }

        private boolean trusted() { String u = owner.getUrl(); return u != null && isAllowed(u); }

        @JavascriptInterface
        public void print() { runOnUiThread(() -> { if (trusted()) printWebView(owner); }); }

        @JavascriptInterface
        public void saveFile(final String name, final String mime, final String base64) {
            if (base64 == null || base64.length() > 60_000_000) return; // about 45 MB of file
            runOnUiThread(() -> { if (trusted()) new Thread(() -> saveBytes(name, mime, base64)).start(); });
        }
    }

    private String bridgeScript() {
        if (bridgeJs == null) {
            try (InputStream in = getAssets().open("hmsbridge.js")) {
                ByteArrayOutputStream out = new ByteArrayOutputStream();
                byte[] buf = new byte[4096];
                int n;
                while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
                bridgeJs = out.toString("UTF-8");
            } catch (Exception e) { return null; }
        }
        return bridgeJs;
    }

    private void injectBridge(WebView v, String url) {
        if (url == null || !isAllowed(url)) return;
        String js = bridgeScript();
        if (js != null) v.evaluateJavascript(js, null);
    }

    /** Opens Android's print screen for the page: any printer the phone knows, or "Save as PDF". */
    private void printWebView(WebView w) {
        try {
            String title = w.getTitle();
            String name = "HMS - " + (title == null || title.trim().isEmpty() ? "page" : title.trim());
            PrintManager pm = (PrintManager) getSystemService(Context.PRINT_SERVICE);
            pm.print(name, w.createPrintDocumentAdapter(name), new PrintAttributes.Builder().build());
        } catch (Exception e) {
            recordError("Print failed: " + e.getMessage());
            Toast.makeText(this, "Printing is not available on this phone.", Toast.LENGTH_LONG).show();
        }
    }

    private static String extFor(String mime) {
        if (mime == null) return "";
        String m = mime.toLowerCase();
        if (m.contains("pdf")) return ".pdf";
        if (m.contains("png")) return ".png";
        if (m.contains("jpeg") || m.contains("jpg")) return ".jpg";
        if (m.contains("csv")) return ".csv";
        if (m.contains("html")) return ".html";
        if (m.contains("plain")) return ".txt";
        if (m.contains("spreadsheetml")) return ".xlsx";
        if (m.contains("excel")) return ".xls";
        if (m.contains("wordprocessingml")) return ".docx";
        if (m.contains("json")) return ".json";
        return "";
    }

    private static File uniqueFile(File dir, String name) {
        File f = new File(dir, name);
        if (!f.exists()) return f;
        int dot = name.lastIndexOf('.');
        String base = dot > 0 ? name.substring(0, dot) : name, ext = dot > 0 ? name.substring(dot) : "";
        for (int i = 1; i < 1000; i++) {
            f = new File(dir, base + " (" + i + ")" + ext);
            if (!f.exists()) return f;
        }
        return new File(dir, base + "-" + System.currentTimeMillis() + ext);
    }

    /** Writes a file the page generated (PDF, Excel, image ...) to the Downloads folder. Needs no permission on Android 10 and newer. */
    private void saveBytes(String name, String mime, String base64) {
        try {
            byte[] data = Base64.decode(base64, Base64.DEFAULT);
            String clean = (name == null ? "" : name).replaceAll("[\\\\/:*?\"<>|\\r\\n]+", "_").trim();
            if (clean.isEmpty()) clean = "HMS-download";
            if (!clean.contains(".")) clean += extFor(mime);
            final String fileName = clean;
            final String type = (mime == null || mime.isEmpty()) ? "application/octet-stream" : mime;
            Uri saved = null;
            if (Build.VERSION.SDK_INT >= 29) {
                ContentValues v = new ContentValues();
                v.put(MediaStore.Downloads.DISPLAY_NAME, fileName);
                v.put(MediaStore.Downloads.MIME_TYPE, type);
                v.put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS);
                saved = getContentResolver().insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, v);
                if (saved == null) throw new IOException("Downloads is not available");
                try (OutputStream os = getContentResolver().openOutputStream(saved)) { os.write(data); }
            } else {
                File dir = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS);
                //noinspection ResultOfMethodCallIgnored
                dir.mkdirs();
                try (FileOutputStream os = new FileOutputStream(uniqueFile(dir, fileName))) { os.write(data); }
            }
            final Uri openUri = saved;
            runOnUiThread(() -> {
                Toast.makeText(this, "Saved to Downloads: " + fileName, Toast.LENGTH_LONG).show();
                if (openUri != null && (type.contains("pdf") || type.startsWith("image/"))) { // show a saved PDF / picture straight away
                    try {
                        Intent i = new Intent(Intent.ACTION_VIEW).setDataAndType(openUri, type).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
                        startActivity(i);
                    } catch (Exception ignored) { /* no viewer installed: the file is still in Downloads */ }
                }
            });
        } catch (Exception e) {
            recordError("Saving a file failed: " + e.getMessage());
            runOnUiThread(() -> Toast.makeText(this, "The file could not be saved.", Toast.LENGTH_LONG).show());
        }
    }

    /** window.open / target="_blank" from the HMS site (a report, a print view): shown in a full-screen window with Print and Close. */
    private boolean openPopup(final Tab t, Message resultMsg) {
        if (resultMsg == null || !(resultMsg.obj instanceof WebView.WebViewTransport)) return false;
        final WebView pop = new WebView(this);
        if (multiProfile) {
            try { WebViewCompat.setProfile(pop, t.profile()); } catch (Exception ignored) { }
        }
        WebSettings ps = pop.getSettings();
        ps.setJavaScriptEnabled(true);
        ps.setDomStorageEnabled(true);
        ps.setMediaPlaybackRequiresUserGesture(false);
        ps.setAllowFileAccess(false);
        ps.setAllowContentAccess(false);
        ps.setGeolocationEnabled(true);
        ps.setSupportMultipleWindows(true);
        ps.setJavaScriptCanOpenWindowsAutomatically(true);
        cookies(t).setAcceptThirdPartyCookies(pop, true);
        pop.addJavascriptInterface(new HmsBridge(pop), "HmsApp");
        pop.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest r) {
                String u = r.getUrl().toString();
                if (isAllowed(u) || u.startsWith("about:") || u.startsWith("blob:")) return false;
                try { startActivity(new Intent(Intent.ACTION_VIEW, r.getUrl())); } catch (ActivityNotFoundException ignored) { }
                closePopup(pop);
                return true;
            }

            @Override
            public void onPageStarted(WebView v, String url, android.graphics.Bitmap icon) { injectBridge(v, url); }

            @Override
            public void onPageFinished(WebView v, String url) { injectBridge(v, url); }
        });
        pop.setWebChromeClient(chromeClient(t));
        pop.setDownloadListener(downloadListener(t, pop));

        final Dialog d = new Dialog(this, android.R.style.Theme_Material_Light_NoActionBar);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        LinearLayout bar = new LinearLayout(this);
        bar.setOrientation(LinearLayout.HORIZONTAL);
        bar.setBackgroundColor(Color.parseColor("#0F1420"));
        bar.setPadding(12, 12, 12, 12);
        Button printBtn = new Button(this);
        printBtn.setText("Print / Save PDF");
        printBtn.setOnClickListener(v -> printWebView(pop));
        Button closeBtn = new Button(this);
        closeBtn.setText("Close");
        closeBtn.setOnClickListener(v -> closePopup(pop));
        bar.addView(printBtn, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        bar.addView(closeBtn, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        root.addView(bar, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT));
        root.addView(pop, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        d.setContentView(root);
        d.setOnKeyListener((di, key, ev) -> {
            if (key == KeyEvent.KEYCODE_BACK && ev.getAction() == KeyEvent.ACTION_UP) {
                if (pop.canGoBack()) pop.goBack(); else closePopup(pop);
                return true;
            }
            return false;
        });
        d.setOnDismissListener(di -> { popups.remove(pop); pop.destroy(); });
        popups.put(pop, d);
        d.show();
        if (d.getWindow() != null) d.getWindow().setLayout(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT);

        WebView.WebViewTransport transport = (WebView.WebViewTransport) resultMsg.obj;
        transport.setWebView(pop);
        resultMsg.sendToTarget();
        return true;
    }

    private void closePopup(final WebView w) {
        ui.post(() -> { // never destroy a WebView from inside one of its own callbacks
            Dialog d = popups.get(w);
            if (d != null) d.dismiss();
        });
    }

    private void recordError(String message) {
        if (sp == null) sp = getSharedPreferences("hms", MODE_PRIVATE);
        Policy.queueError(sp, message);
    }

    /** The Help button: the clinic's phone / WhatsApp number from the signed answer of the admin server. */
    private void helpDialog() {
        final String phone = lastState.supportPhone, wa = lastState.supportWhatsapp;
        if (phone == null && wa == null) return;
        StringBuilder msg = new StringBuilder();
        if (phone != null) msg.append("Phone: ").append(phone).append('\n');
        if (wa != null) msg.append("WhatsApp: ").append(wa);
        AlertDialog.Builder b = new AlertDialog.Builder(this).setTitle("Need help with HMS?").setMessage(msg.toString().trim()).setNeutralButton("Close", null);
        if (phone != null) b.setPositiveButton("Call", (x, y) -> {
            try { startActivity(new Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + Uri.encode(phone)))); } catch (ActivityNotFoundException ignored) { }
        });
        if (wa != null) b.setNegativeButton("WhatsApp", (x, y) -> {
            try { startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse("https://wa.me/" + wa.replaceAll("\\D", "")))); } catch (ActivityNotFoundException ignored) { }
        });
        b.show();
    }

    // ------------------------------------------------------------------ saved login autofill

    private String autofillScript() {
        if (autofillJs == null) {
            try (InputStream in = getAssets().open("autofill.js")) {
                ByteArrayOutputStream out = new ByteArrayOutputStream();
                byte[] buf = new byte[4096];
                int n;
                while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
                autofillJs = out.toString("UTF-8");
            } catch (Exception e) { return null; }
        }
        return autofillJs;
    }

    /** Fills (never submits) the saved login - only when the page is on exactly the origin of this role, over https. */
    private void autofill(Tab t, WebView v, String url) {
        if (loadedBase == null || url == null || !url.startsWith("https://") || !credentials.has(t.id)) return;
        String current = originOf(url);
        String expected = originOf(tabUrl(t, loadedBase));
        if (current == null || !current.equals(expected)) return;
        Credentials.Saved s = credentials.get(t.id);
        String js = autofillScript();
        if (!s.any() || js == null) return;
        v.evaluateJavascript(js.replace("__ORIGIN__", JSONObject.quote(expected))
                .replace("__EMAIL__", JSONObject.quote(s.email))
                .replace("__PASS__", JSONObject.quote(s.password)), null);
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
        return o != null && allowedOrigins.contains(o);
    }

    /**
     * The site may use camera, microphone and location when the page is on the HMS site. "On the HMS site" is wider than the exact
     * address typed in the app: a site that redirects (example.com -> www.example.com), a sub-domain, or the page a role is showing
     * right now all count, because the app only ever opens pages of the configured site in a role.
     */
    private boolean trustedForPermission(String origin) {
        if (origin == null) return false;
        if (isAllowed(origin)) return true;
        String o = originOf(origin);
        if (o == null) return false;
        String host = Uri.parse(o).getHost();
        for (String a : allowedOrigins) if (sameSite(host, Uri.parse(a).getHost())) return true;
        for (Tab t : tabs) {
            if (t.web == null) continue;
            String u = t.web.getUrl();
            if (u != null && o.equals(originOf(u))) return true;
        }
        return false;
    }

    private static boolean sameSite(String x, String y) {
        if (x == null || y == null) return false;
        x = x.toLowerCase().replaceFirst("^www\\.", "");
        y = y.toLowerCase().replaceFirst("^www\\.", "");
        return x.equals(y) || x.endsWith("." + y) || y.endsWith("." + x);
    }

    private void permissionRefused(String what, String origin) {
        recordError("Site " + what + " request refused: " + origin + " is not the configured site (" + allowedOrigins + ")");
        Toast.makeText(this, "HMS refused the " + what + " request from " + origin + ".", Toast.LENGTH_LONG).show();
    }

    private boolean granted(String perm) {
        return Build.VERSION.SDK_INT < 23 || checkSelfPermission(perm) == PackageManager.PERMISSION_GRANTED;
    }

    /** Camera and microphone for the site: the website asks, we make sure Android has allowed the app. */
    private void handleMediaRequest(PermissionRequest request) {
        if (!trustedForPermission(request.getOrigin().toString())) { permissionRefused("camera/microphone", request.getOrigin().toString()); request.deny(); return; }
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
        if (Build.VERSION.SDK_INT < 23 || sp.getBoolean("permsAsked2", false)) return;
        List<String> need = new ArrayList<>();
        String[] all = {Manifest.permission.CAMERA, Manifest.permission.RECORD_AUDIO, Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION};
        for (String p : all) if (!granted(p)) need.add(p);
        if (Build.VERSION.SDK_INT >= 33 && !granted("android.permission.POST_NOTIFICATIONS")) need.add("android.permission.POST_NOTIFICATIONS");
        // files: older Android needs storage access for downloads and the file picker, Android 13+ asks for photos / videos / audio
        if (Build.VERSION.SDK_INT <= 28 && !granted(Manifest.permission.WRITE_EXTERNAL_STORAGE)) need.add(Manifest.permission.WRITE_EXTERNAL_STORAGE);
        if (Build.VERSION.SDK_INT <= 32 && !granted(Manifest.permission.READ_EXTERNAL_STORAGE)) need.add(Manifest.permission.READ_EXTERNAL_STORAGE);
        if (Build.VERSION.SDK_INT >= 33) {
            for (String m : new String[]{"android.permission.READ_MEDIA_IMAGES", "android.permission.READ_MEDIA_VIDEO", "android.permission.READ_MEDIA_AUDIO"}) if (!granted(m)) need.add(m);
        }
        sp.edit().putBoolean("permsAsked2", true).apply();
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
        if (req == REQ_UNLOCK) {
            authenticating = false;
            if (res == RESULT_OK) { unlocked = true; lockCanceled = false; leftAt = System.currentTimeMillis(); if (lockCover != null) lockCover.setVisibility(View.GONE); }
            else lockCanceled = true; // cancelled: stay covered; the Unlock button asks again
            return;
        }
        if (req == REQ_FILE && fileCallback != null) {
            fileCallback.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(res, data));
            fileCallback = null;
            return;
        }
        super.onActivityResult(req, res, data);
    }

    // ------------------------------------------------------------------ menu

    private void fillMenu(Menu m) {
        if (lastState.updateVersion != null && lastState.updateUrl != null) m.add(Menu.NONE, 7, 0, "Update to " + lastState.updateVersion);
        m.add(Menu.NONE, 1, 1, "Reload");
        m.add(Menu.NONE, 2, 2, "Add a role");
        m.add(Menu.NONE, 6, 3, "Role settings & saved login");
        m.add(Menu.NONE, 3, 4, "Log out this role");
        m.add(Menu.NONE, 4, 5, "Log out all roles");
        m.add(Menu.NONE, 5, 6, "About");
        m.add(Menu.NONE, 9, 5, "Print / Save as PDF");
        if (lastState.supportPhone != null || lastState.supportWhatsapp != null) m.add(Menu.NONE, 10, 5, "Help");
        m.add(Menu.NONE, 8, 7, appLockOn() ? "App lock: ON (tap to turn off)" : "App lock: OFF (tap to turn on)");
    }

    private void showMenu(View anchor) {
        PopupMenu pm = new PopupMenu(this, anchor);
        fillMenu(pm.getMenu());
        pm.setOnMenuItemClickListener(item -> handleMenu(item.getItemId()));
        pm.show();
    }

    @Override
    public boolean onCreateOptionsMenu(Menu m) {
        fillMenu(m);
        return true;
    }

    @Override
    public boolean onOptionsItemSelected(MenuItem item) {
        return handleMenu(item.getItemId()) || super.onOptionsItemSelected(item);
    }

    private boolean handleMenu(int id) {
        Tab a = active();
        switch (id) {
            case 1:
                if (a != null && a.web != null && "ok".equals(lastState.state)) a.web.reload(); else refresh();
                return true;
            case 2:
                roleDialog(null);
                return true;
            case 3:
                if (a != null) logoutRole(a);
                return true;
            case 4:
                logoutAll();
                return true;
            case 6:
                if (a != null) roleDialog(a);
                return true;
            case 7:
                if (readyApk != null) installApk(readyApk);
                else if (lastState.updateUrl != null) openUpdate(lastState.updateUrl);
                return true;
            case 8:
                toggleAppLock();
                return true;
            case 9:
                if (a != null && a.web != null) printWebView(a.web);
                return true;
            case 10:
                helpDialog();
                return true;
            case 5:
                String until = lastState.expiresAt > 0 ? DateFormat.getDateTimeInstance().format(new Date(lastState.expiresAt)) : "no end date";
                String err = policy.getLastError();
                new AlertDialog.Builder(this).setTitle("About HMS")
                        .setMessage("Version " + BuildConfig.VERSION_NAME + "\nSite: " + (loadedBase == null ? "-" : loadedBase)
                                + "\nRoles: " + tabs.size() + (multiProfile ? " (separate logins)" : " (only one works: update WebView)")
                                + "\nAccess until: " + until + "\nDevice ID: " + policy.installId
                                + (err != null ? "\nLast server check failed: " + err : ""))
                        .setPositiveButton("OK", null).show();
                return true;
            default:
                return false;
        }
    }
}
