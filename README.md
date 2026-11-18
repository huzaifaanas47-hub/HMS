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
| `build/installer.nsh` | Adds the **"HMS Server Address"** page to the Windows installer |
| `server/` | The PHP admin panel + check-in API (deploy on your Oracle server) |
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

The installer asks for the **HMS Server Address** after the folder page and stores it next to
`HMS.exe`. Silent install: `HMS-Setup-2.0.0.exe /S /URL=https://hospital.example.com`.

### macOS

The same workflow also builds `HMS-<version>-mac-arm64.dmg` (Apple Silicon: M1/M2/M3/M4) and `HMS-<version>-mac-x64.dmg` (Intel)
as the artifact **HMS-Installer-Mac**. Open the dmg and drag HMS to Applications.
* The app is **not notarized** (no Apple developer account), only ad-hoc signed. The first time, **right-click HMS → Open → Open**
  (or System Settings → Privacy & Security → *Open Anyway*). After that it opens normally.
* There is no installer page on Mac. On first launch the setup screen asks for the site address; if your admin panel has a
  default URL / clinic URL it is pushed to the app automatically within seconds.
* Saved passwords use the macOS Keychain. Everything else (tabs, clinics, expiry, block) works the same.
* Local build: `npm install && npm run build:mac` (on a Mac).

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
