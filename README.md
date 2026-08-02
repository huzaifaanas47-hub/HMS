# Multi-Role Portal (Electron)

A desktop app with 7 isolated tabs — one per login role on your website.
Each tab has its **own separate session** (cookies, localStorage, cache),
so you can be logged into 7 different roles on the same site at once,
and every session is saved automatically across app restarts.

## How it works

- Each tab runs in its own Electron `BrowserView` with a unique session
  `partition` (`persist:role1` ... `persist:role7`). Electron persists
  these partitions to disk in the app's user-data folder, so logins
  survive closing/reopening the app — no extra code needed for "save session."
- On first launch, a setup screen asks you to enter a label + URL for
  each of the 7 tabs. This is saved to a local config file
  (`multirole-config.json` in the app's userData folder via `electron-store`).
- You can change the URLs any time later from **File → Edit Tab URLs**
  in the main window menu.

## Run it during development

```bash
cd multirole-app
npm install
npm start
```

On first run you'll see the setup screen — fill in your 7 role URLs and
click "Save & Continue." The main window opens with your tabs.

## Easiest way to get the .exe — GitHub Actions (no Windows PC needed)

This project includes a ready-made GitHub Actions workflow
(`.github/workflows/build.yml`) that builds the Windows installer for you
automatically in the cloud. Steps:

1. Create a free GitHub account if you don't have one, and create a new
   **empty** repository (public or private, either works).
2. Push this project folder to that repository:
   ```bash
   cd multirole-app
   git init
   git add .
   git commit -m "Initial commit"
   git branch -M main
   git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
   git push -u origin main
   ```
3. Go to your repository on GitHub → the **Actions** tab. A workflow run
   called "Build Windows EXE" will start automatically (takes ~2-3
   minutes).
4. Once it finishes (green checkmark), click into that run → scroll down
   to **Artifacts** → download **Multi-Role-Portal-Installer**. That's a
   zip containing your `.exe` installer — no Windows machine needed on
   your end at all.
5. Any time you change the code and push again, a fresh `.exe` is built
   automatically. You can also trigger a build manually from the Actions
   tab using "Run workflow" without pushing new code.

## Build a Windows .exe installer manually (alternative)

If you'd rather not use GitHub, you can build it directly on a Windows
machine (or Linux with Wine installed) instead.

On your Windows machine (with Node.js installed):

```bash
cd multirole-app
npm install
npm run build:win
```

The installer `.exe` will appear in the `dist/` folder
(e.g. `dist/Multi-Role Portal Setup 1.0.0.exe`). Anyone can run that
installer to install the app with a desktop + start menu shortcut.

### Optional: custom app icon
Put a `.ico` file at `build/icon.ico` before building, and it'll be used
as the app/installer icon (referenced in `package.json` → `build.win.icon`).

## Notes / things you may want to tweak

- **Number of tabs**: currently fixed at 7 in `defaultTabs()` in both
  `main.js` and `renderer/setup.js`. Change the loop bound there if you
  ever need more/fewer.
- **Resetting a single tab's session**: if a role ever needs a forced
  logout, you'd clear that partition's session
  (`session.fromPartition('persist:roleX').clearStorageData()`) — ask if
  you want a "Log out this tab" menu item added.
- **Config file location**: Windows → `%APPDATA%\multirole-portal\multirole-config.json`.
