package com.talha.hms;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Build;
import android.util.Base64;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.security.SecureRandom;
import java.util.UUID;

/**
 * Talks to the HMS admin server. Every answer is signed (Ed25519); the app only trusts answers that
 * verify against the public key built into it, so a fake server cannot extend an expiry or push a URL.
 * check() and enroll() use the network: call them off the main thread.
 */
final class Policy {
    static final long DAY = 86400000L;

    static final class State {
        String state = "ok"; // ok | expired | blocked | stale | enroll | unverified
        String baseUrl;
        String message;
        long expiresAt = 0; // 0 = no expiry
        String updateVersion, updateUrl, updateNotes; // a newer build uploaded by the admin (null = none)
    }

    private static final class Resp { int code; String body; }

    private final SharedPreferences sp;
    private final String policyUrl;
    private final byte[] publicKey;
    final boolean active;
    final String installId;
    private long lastSeen;
    private volatile String lastError;

    Policy(Context c) {
        sp = c.getSharedPreferences("hms", Context.MODE_PRIVATE);
        policyUrl = BuildConfig.POLICY_URL;
        byte[] k = null;
        try { k = Base64.decode(BuildConfig.PUBLIC_KEY, Base64.DEFAULT); } catch (Exception ignored) { }
        publicKey = (k != null && k.length == 32) ? k : null;
        active = policyUrl != null && !policyUrl.isEmpty() && publicKey != null;
        String id = sp.getString("installId", null);
        if (id == null) {
            id = UUID.randomUUID().toString();
            sp.edit().putString("installId", id).apply();
        }
        installId = id;
        lastSeen = Math.max(sp.getLong("lastSeen", 0), System.currentTimeMillis());
    }

    String getLastError() { return lastError; }

    private long now() {
        lastSeen = Math.max(lastSeen, System.currentTimeMillis()); // a clock set backwards does not revive an expired install
        return lastSeen;
    }

    private JSONObject verify(String payload, String sig) {
        try {
            if (payload == null || sig == null) return null;
            if (!Ed25519.verify(publicKey, payload.getBytes("UTF-8"), Base64.decode(sig, Base64.DEFAULT))) return null;
            return new JSONObject(payload);
        } catch (Exception e) {
            return null;
        }
    }

    /** true when version a (like "2.3.0") is newer than b */
    static boolean versionNewer(String a, String b) {
        String[] pa = a.split("[.+-]"), pb = b.split("[.+-]");
        for (int i = 0; i < Math.max(pa.length, pb.length); i++) {
            int x = i < pa.length ? num(pa[i]) : 0, y = i < pb.length ? num(pb[i]) : 0;
            if (x != y) return x > y;
        }
        return false;
    }

    private static int num(String s) {
        try { return Integer.parseInt(s.trim()); } catch (Exception e) { return 0; }
    }

    private static String str(JSONObject o, String k) {
        return (o.isNull(k) || !o.has(k)) ? null : o.optString(k, null);
    }

    State evaluate() {
        State out = new State();
        if (!active) return out;
        JSONObject p = verify(sp.getString("payload", null), sp.getString("sig", null));
        if (p == null || !installId.equals(p.optString("installId"))) {
            out.state = sp.getBoolean("verifiedOnce", false) ? "stale" : "unverified";
            return out;
        }
        long t = now();
        out.baseUrl = str(p, "baseUrl");
        out.message = str(p, "message");
        out.expiresAt = p.isNull("expiresAt") ? 0 : p.optLong("expiresAt", 0);
        JSONObject up = p.optJSONObject("update"); // signed announcement of a newer build; compared here too so a cached answer stops showing it once updated
        if (up != null) {
            String uv = str(up, "version"), url = str(up, "url");
            if (uv != null && url != null && (url.startsWith("https://") || url.startsWith("http://")) && versionNewer(uv, BuildConfig.VERSION_NAME)) {
                out.updateVersion = uv;
                out.updateUrl = url;
                out.updateNotes = str(up, "notes");
            }
        }
        long grace = p.optLong("offlineGraceDays", 0);
        if (p.optBoolean("enrollRequired", false)) out.state = "enroll";
        else if ("blocked".equals(p.optString("status"))) out.state = "blocked";
        else if (out.expiresAt > 0 && t >= out.expiresAt) out.state = "expired";
        else if (grace > 0 && t - p.optLong("serverTime", 0) > grace * DAY) out.state = "stale";
        sp.edit().putLong("lastSeen", lastSeen).apply();
        return out;
    }

    private JSONObject deviceBody(String nonce) throws Exception {
        JSONObject b = new JSONObject();
        b.put("installId", installId);
        b.put("nonce", nonce);
        b.put("appVersion", BuildConfig.VERSION_NAME);
        b.put("platform", "android");
        b.put("device", (Build.MANUFACTURER + " " + Build.MODEL).trim());
        b.put("osRelease", "Android " + Build.VERSION.RELEASE);
        return b;
    }

    private static String newNonce() {
        byte[] r = new byte[16];
        new SecureRandom().nextBytes(r);
        StringBuilder s = new StringBuilder();
        for (byte x : r) s.append(String.format("%02x", x));
        return s.toString();
    }

    private Resp post(String url, JSONObject body) throws Exception {
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        try {
            c.setRequestMethod("POST");
            c.setConnectTimeout(10000);
            c.setReadTimeout(15000);
            c.setDoOutput(true);
            c.setRequestProperty("Content-Type", "application/json");
            byte[] data = body.toString().getBytes("UTF-8");
            try (OutputStream o = c.getOutputStream()) { o.write(data); }
            Resp r = new Resp();
            r.code = c.getResponseCode();
            InputStream in = r.code >= 400 ? c.getErrorStream() : c.getInputStream();
            ByteArrayOutputStream buf = new ByteArrayOutputStream();
            if (in != null) {
                byte[] tmp = new byte[4096];
                int n;
                while ((n = in.read(tmp)) > 0 && buf.size() < 65536) buf.write(tmp, 0, n);
                in.close();
            }
            r.body = buf.toString("UTF-8");
            return r;
        } finally {
            c.disconnect();
        }
    }

    /** Asks the server for the current policy. Returns null on success, otherwise a short reason. */
    String check() {
        if (!active) return null;
        try {
            String nonce = newNonce();
            Resp r = post(policyUrl, deviceBody(nonce));
            if (r.code != 200) throw new Exception("HTTP " + r.code);
            JSONObject env = new JSONObject(r.body);
            String payload = env.optString("payload", null);
            String sig = env.optString("sig", null);
            JSONObject p = verify(payload, sig);
            if (p == null || !installId.equals(p.optString("installId")) || !nonce.equals(p.optString("nonce"))) {
                throw new Exception("the answer failed the signature check");
            }
            lastSeen = Math.max(Math.max(lastSeen, p.optLong("serverTime", 0)), System.currentTimeMillis());
            sp.edit().putString("payload", payload).putString("sig", sig).putBoolean("verifiedOnce", true).putLong("lastSeen", lastSeen).apply();
            lastError = null;
            return null;
        } catch (Exception e) {
            lastError = e.getMessage() != null ? e.getMessage() : e.getClass().getSimpleName();
            return lastError;
        }
    }

    /** Joins a clinic with an access code. Returns null on success, otherwise a message for the user. */
    String enroll(String code) {
        if (!active) return "This app is not connected to an admin server.";
        try {
            String nonce = newNonce();
            JSONObject body = deviceBody(nonce);
            body.put("code", code);
            Resp r = post(policyUrl.replaceAll("checkin\\.php(\\?.*)?$", "enroll.php"), body);
            JSONObject env = new JSONObject(r.body);
            if (r.code != 200) return env.optString("error", "The server answered with an error (" + r.code + ").");
            JSONObject p = verify(env.optString("payload", null), env.optString("sig", null));
            if (p == null || !installId.equals(p.optString("installId")) || !nonce.equals(p.optString("nonce")) || !p.optBoolean("enrolled", false)) {
                return "The answer could not be verified. Check that you are using the correct server.";
            }
            String err = check();
            return err == null ? null : "Joined, but the check failed: " + err;
        } catch (Exception e) {
            return "Cannot reach the server. Check your internet connection and try again.";
        }
    }
}
