package com.talha.hms;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;

import org.json.JSONObject;

import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import java.util.Arrays;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

/**
 * Saved logins, one per role (tab). Every record is encrypted with an AES-256-GCM key that lives in the
 * Android Keystore (it never leaves the secure hardware / keystore process and is not part of any backup).
 * The role id is bound into the ciphertext, so a record cannot be copied onto another role.
 * If encryption is not possible the login is simply NOT saved - there is no plaintext fallback.
 */
final class Credentials {
    static final class Saved {
        String email = "", password = "";
        boolean any() { return !email.isEmpty() || !password.isEmpty(); }
    }

    private static final String ALIAS = "hms_saved_logins_v1";
    private static final String PROVIDER = "AndroidKeyStore";
    private static final int IV_LEN = 12, TAG_BITS = 128;

    private final SharedPreferences sp;

    Credentials(Context c) {
        sp = c.getSharedPreferences("hms_logins", Context.MODE_PRIVATE);
    }

    private static String k(String tabId) { return "login." + tabId; }

    private static SecretKey key() throws Exception {
        KeyStore ks = KeyStore.getInstance(PROVIDER);
        ks.load(null);
        if (!ks.containsAlias(ALIAS)) {
            KeyGenerator g = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, PROVIDER);
            g.init(new KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT)
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .setKeySize(256)
                    .build());
            return g.generateKey();
        }
        return (SecretKey) ks.getKey(ALIAS, null);
    }

    boolean has(String tabId) { return sp.contains(k(tabId)); }

    /** An empty password keeps the password that is already saved for this role. Returns false if it could not be stored securely. */
    boolean save(String tabId, String email, String password) {
        try {
            Saved old = get(tabId);
            String pw = (password == null || password.isEmpty()) ? old.password : password;
            String em = email == null ? "" : email;
            if (em.isEmpty() && pw.isEmpty()) { delete(tabId); return true; }

            byte[] plain = new JSONObject().put("e", em).put("p", pw).toString().getBytes(StandardCharsets.UTF_8);
            Cipher c = Cipher.getInstance("AES/GCM/NoPadding");
            c.init(Cipher.ENCRYPT_MODE, key());
            c.updateAAD(tabId.getBytes(StandardCharsets.UTF_8));
            byte[] iv = c.getIV();
            byte[] ct = c.doFinal(plain);
            Arrays.fill(plain, (byte) 0);
            byte[] out = new byte[iv.length + ct.length];
            System.arraycopy(iv, 0, out, 0, iv.length);
            System.arraycopy(ct, 0, out, iv.length, ct.length);
            sp.edit().putString(k(tabId), Base64.encodeToString(out, Base64.NO_WRAP)).apply();
            return true;
        } catch (Exception e) {
            return false;
        }
    }

    Saved get(String tabId) {
        Saved s = new Saved();
        String b64 = sp.getString(k(tabId), null);
        if (b64 == null) return s;
        try {
            byte[] in = Base64.decode(b64, Base64.NO_WRAP);
            if (in.length <= IV_LEN) return s;
            Cipher c = Cipher.getInstance("AES/GCM/NoPadding");
            c.init(Cipher.DECRYPT_MODE, key(), new GCMParameterSpec(TAG_BITS, in, 0, IV_LEN));
            c.updateAAD(tabId.getBytes(StandardCharsets.UTF_8));
            byte[] plain = c.doFinal(in, IV_LEN, in.length - IV_LEN);
            JSONObject o = new JSONObject(new String(plain, StandardCharsets.UTF_8));
            Arrays.fill(plain, (byte) 0);
            s.email = o.optString("e", "");
            s.password = o.optString("p", "");
        } catch (Exception e) {
            // key was reset (e.g. the phone's lock screen was removed) or data damaged: treat as not saved
            delete(tabId);
        }
        return s;
    }

    void delete(String tabId) { sp.edit().remove(k(tabId)).apply(); }
}
