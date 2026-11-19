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
import android.webkit.WebViewClient;
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
        multiProfile = WebViewFeature.isFeatureSupported(WebViewFeature.MULTI_PROFILE);
        setUpEdgeToEdge();
        loadTabs();
        buildUi();
        rebuildTabBar();
        askAllPermissionsOnce();
        apply(policy.evaluate(), null);
        if (!multiProfile && !sp.getBoolean("warnedProfiles", false)) {
            sp.edit().putBoolean("warnedProfiles", true).apply();
            new AlertDialog.Builder(this).setTitle("Update Android System WebView")
                    .setMessage("This phone's WebView is too old to keep roles separate, so only one role works for now. Update \"Android System WebView\" in Google Play to use several roles at once.")
                    .setPositiveButton("OK", null).show();
        }
    }

    @Override protected void onResume() { super.onResume(); ui.post(ticker); }
    @Override protected void onPause() { super.onPause(); ui.removeCallbacks(ticker); }
    @Override protected void onDestroy() { super.onDestroy(); io.shutdownNow(); }

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
    private boolean updShownThisStart = false;

    private void maybePromptUpdate(final Policy.State s) {
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

            // Every page load (app start, after a log out, any redirect to the login page) fills this role's saved login.
            @Override
            public void onPageFinished(WebView v, String url) { autofill(t, v, url); }
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
        });
        return web;
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

    private void fillMenu(Menu m) {
        if (lastState.updateVersion != null && lastState.updateUrl != null) m.add(Menu.NONE, 7, 0, "Update to " + lastState.updateVersion);
        m.add(Menu.NONE, 1, 1, "Reload");
        m.add(Menu.NONE, 2, 2, "Add a role");
        m.add(Menu.NONE, 6, 3, "Role settings & saved login");
        m.add(Menu.NONE, 3, 4, "Log out this role");
        m.add(Menu.NONE, 4, 5, "Log out all roles");
        m.add(Menu.NONE, 5, 6, "About");
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
                if (lastState.updateUrl != null) openUpdate(lastState.updateUrl);
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
