# HMS Desktop

Windows desktop client for the Hospital Management System. It opens your HMS website in
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

## 3. Controlling installs

Every install appears under **Installs** after its first check-in, with device name, OS, app
version, IP and last-seen time (green dot = checked in within 15 min).

* **Site URL**: *Settings → Default site URL* is pushed to every install (it overrides what was typed in the
  installer); a per-install URL on the *Manage* page wins over it. Tabs set to a path (`/login`)
  follow the pushed URL; tabs set to a full `https://...` address keep theirs.
* **Expiry**: per install (date, +7/+30/+90 days, expire now, remove), *new installs expire after N days*,
  or a **global expiry** that ends access for everyone at once.
* **Block** an install, and show a **message** on its lock screen.
* Changes reach an app at its next check-in (≤ 10 min, or on restart). When expired/blocked the
  app destroys its site tabs and shows only a lock screen.

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
