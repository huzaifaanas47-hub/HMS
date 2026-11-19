# HMS Desktop

Desktop client (Windows and macOS) for the Hospital Management System. It opens your HMS website in
up to 20 tabs, each with its **own isolated session**, so you can be logged in as several
roles at once. A small PHP **admin panel** (`server/`) lets you watch every installation and
control it remotely: change the site URL, set an expiry time, block or message an install.

```
 HMS.exe (user's PC)  ──every 10 min, HTTPS──▶  server/api/checkin.php  ◀── you, via server/admin/
        ▲                                         (signed answer: URL, expiry, status)
        └── verifies the answer with a public key baked into the app
```

## What's in this repo

| Path | What it is |
|---|---|
| `main.js`, `preload.js`, `renderer/`, `src/` | The Electron app |
| `app-config.json` | `policyUrl` + `publicKey` of your admin server, fallback `defaultUrl` |
| `build/installer.nsh` | Windows installer extra: optional `/URL=` for silent installs |
| `server/` | The PHP admin panel + check-in API + public download page (deploy on your Oracle server) |
| `.github/workflows/build.yml` | Builds the installer on GitHub (no Windows PC needed) |

## 1. Deploy the admin panel (Oracle server)

Needs PHP 7.4+ (with `pdo_mysql` and `sodium`) and MySQL/MariaDB. **Use HTTPS** (e.g. certbot).

1. Create an empty database and user.
2. Upload the contents of `server/` to a web folder, e.g. `/var/www/html/hms-admin/`.
3. Block the `lib/` folder. Apache reads the included `.htaccess`; on **nginx** add:
   ```nginx
   location ^~ /hms-admin/lib/ { deny all; }
   ```
4. Open `https://YOUR-DOMAIN/hms-admin/setup.php`, enter the DB details and an admin login.
   It creates the tables, generates the signing key, writes `lib/config.php` and **deletes itself**.
5. Sign in at `https://YOUR-DOMAIN/hms-admin/admin/login.php`.
   **Settings** shows the two values for the app (`policyUrl`, `publicKey`).

Back up `lib/config.php`: it holds the private signing key. If you lose it you must ship a new
build with a new public key.

## 2. Point the app at it, build, install

Edit `app-config.json`:
```json
{ "policyUrl": "https://YOUR-DOMAIN/hms-admin/api/checkin.php",
  "publicKey": "<from Settings>",
  "defaultUrl": "https://medicglobe.fun" }
```
Push to GitHub → **Actions → Build Windows EXE → artifact `HMS-Installer`**.
Or locally: `npm install && npm run build:win` (on Windows).

The installer no longer asks for an address: the site comes from the access code. A silent install can pin one: `HMS-Setup-2.0.0.exe /S /URL=https://hospital.example.com`.

### macOS

The same workflow also builds `HMS-<version>-mac-arm64.dmg` (Apple Silicon: M1/M2/M3/M4) and `HMS-<version>-mac-x64.dmg` (Intel)
as the artifact **HMS-Installer-Mac**. Open the dmg and drag HMS to Applications.
* The app is **not notarized** (no Apple developer account), only ad-hoc signed. The first time, **right-click HMS → Open → Open**
  (or System Settings → Privacy & Security → *Open Anyway*). After that it opens normally.
* There is no installer page on Mac. On first launch the setup screen asks for the site address; if your admin panel has a
  default URL / clinic URL it is pushed to the app automatically within seconds.
* Saved passwords use the macOS Keychain. Everything else (tabs, clinics, expiry, block) works the same.
* Local build: `npm install && npm run build:mac` (on a Mac).

### Android

The workflow also builds `HMS-<version>-android.apk` (artifact **HMS-Installer-Android**): a native shell around the HMS site
with the same code entry, signed policy checks, lock screen and expiry as the desktop app. It asks for camera, microphone and
location on first launch (Android requires the user to tap Allow), supports file upload and downloads, and has a menu with
Reload, Log out and About. One site session per phone (no role tabs).

An APK only installs over an older one if both are signed with the same key. Create a key once and save it as four repository
secrets (GitHub → Settings → Secrets and variables → Actions): `ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`,
`ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`. Without them every build uses a fresh temporary key, so a new version
must be installed after removing the old one.

```
keytool -genkeypair -keystore hms.keystore -alias hms -keyalg RSA -keysize 2048 -validity 10000
base64 -w0 hms.keystore      # paste the output as ANDROID_KEYSTORE_BASE64; keep the file safe
```

## Access codes

**Codes** in the admin panel puts new devices into a clinic without any manual assignment. Create a code for a clinic, set how
many devices may join and until when, and give users the download link and the code. The app asks for the code the first time it
opens (Windows, Mac and Android), then gets the clinic's site URL and access date. You can change the limit, the date, or close
the code at any time; devices already joined follow the new date at their next check-in. Deleting a device frees its place.
Turn on **Settings → Require an access code** to refuse devices that have not joined with one (devices already listed keep
working). Wrong guesses are rate-limited (10 per 15 minutes per IP).

## Giving the app to users (Downloads)

Build the installers once (GitHub **Actions → Build installers**, download `HMS-Installer` and `HMS-Installer-Mac`,
unzip them), then in the admin panel open **Downloads**, choose the computer type, enter the version and upload the file
(`.exe` for Windows; the `arm64` `.dmg` for Apple-chip Macs, the `x64` `.dmg` for Intel Macs).
Give users one link: `https://YOUR-DOMAIN/hms-admin/download.php`. It shows the right installer for their computer with
install steps; the newest upload per type is what they get. Nobody needs GitHub.

Uploads are sent in small pieces and survive network drops: a lost connection pauses and retries by itself, and a closed tab or
crashed browser can resume by choosing the same file again (unfinished uploads are kept for 3 days in `lib/releases/tmp/`).
Downloads support resuming too (HTTP Range), so a phone that loses signal continues where it stopped.

Installers are 80-120 MB. Raise `upload_max_filesize` and `post_max_size` (e.g. 300M) in php.ini, and on nginx add
`client_max_body_size 300m;`. Files are stored in `lib/releases/` (blocked from direct web access; downloads go through `get.php`).

## 3. Controlling installs: clinics

Every PC appears under **PCs** after its first check-in (device name, OS, app version, IP, last seen;
green dot = checked in within 15 min). PCs are grouped into **Clinics** (a clinic or hospital = a batch of PCs,
one per role or desk).

1. **Clinics → New clinic**: give it a name, the **site URL** and an **expiry** for the whole batch.
2. Install the app on the clinic's PCs. Each shows up under **PCs** (filter "Not in a clinic").
3. Open the clinic and tick the new PCs under *Add PCs*, or tick them on the **PCs** page and use
   *Move ticked PCs to this clinic*. Joining a clinic clears the PC's own expiry/URL so the clinic's apply.
4. From then on, change the clinic once: **+7/+30/+90/+365 days**, remove expiry, expire now, block, set a lock-screen
   message, or change the URL. Every PC in it follows at its next check-in (≤ 10 min, or on restart).

Precedence for a PC in a clinic: site URL = PC's own override, else the clinic's, else *Settings → Default site URL*.
Expiry = the **earliest** of the PC's own date, the clinic's date and the global expiry. Blocked if the PC or its clinic
is blocked. A single PC can still get its own settings on its *Edit* page. Tabs set to a path (`/login`) follow the
pushed URL; tabs set to a full `https://...` address keep theirs. When expired/blocked the app destroys its site tabs
and shows only a lock screen.

Upgrading the admin panel from the first 2.0 version: upload the new `server/` files over the old ones (keep `lib/config.php`).
The database upgrades itself the first time you sign in.

Only this is sent to the server: random install ID, PC name, OS version, app version, IP.
No passwords, no page content, no browsing data. Tell your users.

## Security notes

* Check-in answers are **Ed25519-signed**; the app rejects anything not signed by your key, and a
  nonce stops replays. A fake server or hosts-file trick cannot extend an expiry or push a URL.
* Expiry is enforced offline too (absolute timestamp). The app remembers the latest time it has
  seen, so winding the PC clock back does not revive an expired install. A deleted/edited local
  cache locks the app until it can verify again, and the app locks if it can't reach the server for
  *N days* (Settings, default 14; 0 = never).
* **Limit:** this is a desktop app on the user's PC, so a determined admin-level user could still
  patch the app itself. It stops ordinary users and makes bypassing deliberate, not impossible.
* Saved passwords are stored only encrypted by the OS keystore (Windows DPAPI). **No plaintext
  fallback**: if secure storage is unavailable the password isn't saved; old plaintext records are
  re-encrypted or deleted on upgrade.
* **Origin checks**: saved logins are auto-filled only on exactly the tab's own origin over
  https (or localhost/LAN http); in-app navigation/redirects/popups are limited to the configured
  site origins (others open in the system browser); only the app's own pages may call IPC; web
  permissions are limited to the configured origins.
* Admin panel: bcrypt passwords, CSRF tokens, login throttling, strict session cookies,
  prepared statements, escaped output, security headers.

## Upgrading from v1

* Existing installs keep their tabs, sessions and saved logins (config is migrated from
  `multirole-config.json` to `hms-config.json`; the `appId` is unchanged so the installer upgrades in place).
* Tab URLs on the site's own origin become relative paths automatically, so they follow the pushed URL.

## Per-tab logout

Right-click a tab, or use **⏻ Log Out Tab** / *File → Log Out This Tab / All Tabs*. It clears only
that tab's cookies and site data, then reloads it. Right-click also offers **Forget Saved Login**.

## Development

```bash
npm install
npm start          # runs the app
npm run check      # syntax-checks the JS
```
Without `policyUrl`/`publicKey` in `app-config.json` the app runs standalone (no admin control).

## Releasing updates (auto-update) and signing

Installed desktop apps check GitHub Releases on start and every 4 hours, download in the background, and ask to restart.

1. Bump `version` in `package.json` (e.g. `2.1.1`), commit and push.
2. `git tag v2.1.1 && git push origin v2.1.1` — the workflow builds Windows + macOS + Android and publishes them as a Release.

Signing is automatic when these repository secrets exist (Settings → Secrets and variables → Actions); without them builds still work, unsigned:

| Platform | Secrets |
|---|---|
| Windows | `WIN_CERT_P12_BASE64` (base64 of the .pfx), `WIN_CERT_PASSWORD` |
| macOS (needed for auto-update there) | `MAC_CERT_P12_BASE64` (Developer ID Application .p12), `MAC_CERT_PASSWORD`, `APPLE_ID`, `APPLE_APP_SPECIFIC_PASSWORD`, `APPLE_TEAM_ID` |
| Android | the four `ANDROID_*` secrets above |

The first version with the updater must still be installed by hand once; every release after that updates itself.

## Which download for which computer

| Computer | File |
|---|---|
| Windows 10/11 | `HMS-Setup-<version>.exe` |
| Mac with an Intel chip (all 2018 Macs, and anything before late 2020) | `HMS-<version>-mac-x64.dmg` |
| Mac with Apple Silicon (M1 and newer) | `HMS-<version>-mac-arm64.dmg` |
| Linux, Debian / Ubuntu / Mint | `HMS-<version>-linux-amd64.deb` (Intel/AMD) or `-linux-arm64.deb` (ARM) |
| Linux, any other distro | `HMS-<version>-linux-x86_64.AppImage` or `-linux-arm64.AppImage` |
| Android | `HMS-<version>-android.apk` |

* **Mac requirement:** HMS runs on macOS 13 (Ventura) or newer. A 2018 MacBook Pro, Mac mini or iMac can be updated that far; a 2018 MacBook Air stops at Ventura, which is enough.
* **Linux .deb:** `sudo apt install ./HMS-<version>-linux-amd64.deb`.
* **Linux AppImage:** `chmod +x HMS-*.AppImage && ./HMS-*.AppImage`. On Ubuntu 23.10+ the system blocks Electron's sandbox for AppImages; if it does not open, run it once as
  `./HMS-*.AppImage --no-sandbox`, or install the .deb instead (recommended there).
