package com.talha.hms;

import java.math.BigInteger;
import java.security.MessageDigest;

/** Ed25519 signature verification (RFC 8032), pure Java, so it works on every Android version. */
final class Ed25519 {
    private static final BigInteger P = BigInteger.ONE.shiftLeft(255).subtract(BigInteger.valueOf(19));
    private static final BigInteger L = BigInteger.ONE.shiftLeft(252).add(new BigInteger("27742317777372353535851937790883648493"));
    private static final BigInteger D = BigInteger.valueOf(-121665).multiply(BigInteger.valueOf(121666).modInverse(P)).mod(P);
    private static final BigInteger I = BigInteger.valueOf(2).modPow(P.subtract(BigInteger.ONE).shiftRight(2), P); // sqrt(-1)
    private static final BigInteger[] BASE;

    static {
        BigInteger y = BigInteger.valueOf(4).multiply(BigInteger.valueOf(5).modInverse(P)).mod(P);
        BASE = new BigInteger[]{recoverX(y, 0), y};
    }

    private Ed25519() {}

    private static BigInteger recoverX(BigInteger y, int sign) {
        BigInteger y2 = y.multiply(y).mod(P);
        BigInteger x2 = y2.subtract(BigInteger.ONE).multiply(D.multiply(y2).add(BigInteger.ONE).modInverse(P)).mod(P);
        BigInteger x = x2.modPow(P.add(BigInteger.valueOf(3)).shiftRight(3), P);
        if (!x.multiply(x).subtract(x2).mod(P).equals(BigInteger.ZERO)) x = x.multiply(I).mod(P);
        if (!x.multiply(x).subtract(x2).mod(P).equals(BigInteger.ZERO)) return null;
        if (x.testBit(0) != (sign == 1)) x = P.subtract(x);
        return x;
    }

    private static BigInteger[] add(BigInteger[] a, BigInteger[] b) {
        BigInteger dxy = D.multiply(a[0]).multiply(b[0]).multiply(a[1]).multiply(b[1]).mod(P);
        BigInteger x = a[0].multiply(b[1]).add(b[0].multiply(a[1])).multiply(BigInteger.ONE.add(dxy).modInverse(P)).mod(P);
        BigInteger y = a[1].multiply(b[1]).add(a[0].multiply(b[0])).multiply(BigInteger.ONE.subtract(dxy).mod(P).modInverse(P)).mod(P);
        return new BigInteger[]{x, y};
    }

    private static BigInteger[] mul(BigInteger[] pt, BigInteger k) {
        BigInteger[] r = {BigInteger.ZERO, BigInteger.ONE}; // neutral element
        BigInteger[] q = pt;
        for (int i = 0; i < k.bitLength(); i++) {
            if (k.testBit(i)) r = add(r, q);
            q = add(q, q);
        }
        return r;
    }

    private static BigInteger le(byte[] b, int off, int len) {
        byte[] r = new byte[len + 1]; // big-endian with a leading zero byte so the value stays positive
        for (int i = 0; i < len; i++) r[len - i] = b[off + i];
        return new BigInteger(r);
    }

    private static BigInteger[] decode(byte[] b, int off) {
        byte[] t = new byte[32];
        System.arraycopy(b, off, t, 0, 32);
        int sign = (t[31] >> 7) & 1;
        t[31] &= 0x7f;
        BigInteger y = le(t, 0, 32);
        if (y.compareTo(P) >= 0) return null;
        BigInteger x = recoverX(y, sign);
        if (x == null || (x.signum() == 0 && sign == 1)) return null;
        return new BigInteger[]{x, y};
    }

    static boolean verify(byte[] publicKey, byte[] message, byte[] sig) {
        try {
            if (publicKey == null || sig == null || publicKey.length != 32 || sig.length != 64) return false;
            BigInteger[] a = decode(publicKey, 0);
            BigInteger[] r = decode(sig, 0);
            if (a == null || r == null) return false;
            BigInteger s = le(sig, 32, 32);
            if (s.compareTo(L) >= 0) return false;
            MessageDigest sha = MessageDigest.getInstance("SHA-512");
            sha.update(sig, 0, 32);
            sha.update(publicKey);
            sha.update(message);
            byte[] hd = sha.digest();
            BigInteger h = le(hd, 0, 64).mod(L);
            BigInteger[] left = mul(BASE, s);
            BigInteger[] right = add(r, mul(a, h));
            return left[0].equals(right[0]) && left[1].equals(right[1]);
        } catch (Exception e) {
            return false;
        }
    }
}
