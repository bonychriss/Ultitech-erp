# BCUT Desktop Application

This document explains how the Windows desktop version of BCUT works alongside the existing web application.

## Architecture

```
BCUT (single React codebase in src/)
        │
        ├── Web target          →  npm run dev / npm run build  →  static dist/
        │
        └── Desktop target      →  Electron wraps the same UI
                │
                ├── Development →  Vite on http://localhost:5180 + Electron window
                └── Production  →  Electron loads dist/index.html locally
```

| Layer | Location | Role |
|-------|----------|------|
| React UI | `src/` | Unchanged application |
| Vite | `vite.config.js` | Web dev server & production build |
| Electron main | `electron/main.cjs` | Window, lifecycle, native dialogs |
| Electron preload | `electron/preload.cjs` | Safe `window.bcut` API bridge |
| Auto-updater | `electron/updater.cjs` | Checks for updates in packaged builds |
| Update config | `electron/update-config.cjs` | HTTPS URL for update files |
| Desktop helper | `src/utils/desktop.js` | Detect Electron, native save, updates |

## Commands

### Web (unchanged)

```bash
npm install
npm run dev        # http://localhost:5180
npm run build      # outputs dist/
npm run preview    # preview production build
npm run lint
```

### Desktop development

```bash
npm run electron:dev
```

This will:

1. Start the Vite dev server on port **5180**
2. Wait until the server is ready
3. Launch Electron and load `http://localhost:5180`

DevTools open automatically in development only.

### Windows installer build

```bash
npm run desktop:build
```

Or explicitly:

```bash
npm run desktop:build:win
```

This will:

1. Run `vite build` → `dist/`
2. Package with electron-builder
3. Output installer to **`release/`**

Expected output:

```
release/
└── BCUT Setup.exe
```

Installed app executable: **BCUT.exe**

## Automatic updates

Packaged BCUT checks for updates automatically using **electron-updater**.

### How it works

1. On startup (about 8 seconds after launch), the app checks your update server.
2. If a newer version exists, it downloads **BCUT Setup.exe** in the background.
3. When the download finishes, a Windows dialog asks the user to restart.
4. Restart installs the update through the existing NSIS installer.

Updates only run in **packaged** builds (`BCUT Setup.exe` / installed app).  
They do **not** run during `npm run electron:dev`.

### Configure your update server URL

Edit **`electron/update-config.cjs`**:

```javascript
updateServerUrl: 'https://your-domain.com/bcut/updates/'
```

The URL must:

- use **HTTPS**
- point to a folder (trailing `/` required)
- be publicly reachable by installed users

Or override only for one build:

```bash
set BCUT_UPDATE_URL=https://your-domain.com/bcut/updates/
npm run desktop:build
```

`npm run desktop:build` reads this URL and embeds it into the installer via electron-builder.

### Publish a new version

1. Bump the version in **`package.json`** (example: `1.0.0` → `1.0.1`).
2. Build:
   ```bash
   npm run desktop:build
   ```
3. Upload these files from **`release/`** to your update server folder:
   - `latest.yml` — required (tells the app what version is available)
   - `BCUT Setup.exe` — required
   - `BCUT Setup.exe.blockmap` — recommended (enables smaller delta downloads)
4. Keep older installer files available until most users have updated.

Example hosted layout:

```
https://your-domain.com/bcut/updates/
├── latest.yml
├── BCUT Setup.exe
└── BCUT Setup.exe.blockmap
```

### What `latest.yml` contains

electron-builder generates this automatically during `npm run desktop:build`.  
Do not edit it by hand unless you know what you are doing.

### Manual update check (optional, from React)

```javascript
import { checkDesktopForUpdates, onDesktopUpdateStatus } from './utils/desktop';

checkDesktopForUpdates();

const unsubscribe = onDesktopUpdateStatus((status) => {
  console.log(status); // checking | available | downloading | downloaded | error
});
```

Native restart dialogs are handled by Electron; no UI changes are required.

### Disable updates (testing)

Set this environment variable before launching BCUT:

```bash
set BCUT_DISABLE_UPDATES=1
```

### Update limitations

| Topic | Notes |
|-------|-------|
| HTTPS required | HTTP update servers are rejected in production |
| Code signing | Unsigned builds still update, but Windows SmartScreen may warn |
| First install | Users must install manually once; updates apply after that |
| Dev mode | No update checks in `electron:dev` |
| Offline | Update check fails silently on startup if offline |

## Application identity

| Setting | Value |
|---------|-------|
| Product name | BCUT |
| App ID | `com.bcut.desktop` |
| Executable | `BCUT.exe` |
| Installer | `BCUT Setup.exe` |

## Changing the application icon

1. Create a multi-size Windows `.ico` file (256×256 recommended).
2. Save it as: `electron/icons/icon.ico`
3. Rebuild: `npm run desktop:build`

See `electron/icons/README.md` for details.

Until you add `icon.ico`, electron-builder uses the default Electron icon.

## Native Save dialog (desktop only)

In Electron, **Export → Download** opens the Windows **Save As** dialog instead of a browser download.

| Environment | Export behavior |
|-------------|-----------------|
| Web browser | `<a download>` blob download |
| Electron | `window.bcut.saveFile()` via preload → `dialog.showSaveDialog` |

Detection in React:

```javascript
import { isDesktopApp } from './utils/desktop';

if (isDesktopApp()) {
  // Electron-only path
}
```

The web version never defines `window.bcut` and continues to work normally.

## Security

Electron is configured with:

- `contextIsolation: true`
- `nodeIntegration: false`
- `sandbox: true`
- Preload exposes only `window.bcut` (save file, version, updates, isDesktop flag)

React code does **not** have access to Node.js APIs.

## AI background removal

The app uses `@imgly/background-removal` with `onnxruntime-web` entirely in the renderer.

On first use, the library downloads ONNX/WASM model files from the **IMG.LY CDN**.

### Online

AI background removal works when internet is available (first run downloads models; later runs use browser/Electron cache).

### Offline

**Not fully offline by default.** After models are cached, removal may work offline, but this depends on IMG.LY cache behavior and is not guaranteed without bundling models locally.

Bundling models locally is a future enhancement and is not required for the initial desktop release.

## External dependencies

| Resource | Used for | Desktop note |
|----------|----------|--------------|
| IMG.LY CDN | AI ONNX/WASM models | Requires network on first run |
| Google Fonts CDN | DM Sans font | Requires network unless fonts are bundled later |

## Environment variables

The legacy `.env.example` keys (`OPENAI_API_KEY`, `REPLICATE_API_TOKEN`) are **not used** by the current application and are **not** included in the desktop build.

## Theme persistence

`localStorage` key `bcut-theme` works in both web and Electron.

## Troubleshooting

### Electron shows "Dev server not running"

Run `npm run electron:dev` (not `electron .` alone). Or start `npm run dev` first, then `electron .`.

### Blank window in production

Run `npm run build` before packaging. Electron loads `dist/index.html`.

### `BCUT Setup.exe` not created

1. Ensure `npm run build` succeeds
2. Run `npm run desktop:build`
3. Check console output for electron-builder errors
4. On Windows, code signing warnings are normal for unsigned builds

### AI removal fails in packaged app

1. Confirm internet access on first run
2. Open DevTools in dev mode and check Network tab for IMG.LY requests
3. Check console for ONNX/WASM errors

### Port 5180 already in use

Stop other processes using port 5180, or change the port in `vite.config.js` and update `DEV_SERVER_URL` in `electron/main.cjs`.

## File structure

```
electron/
├── main.cjs              # Main process
├── preload.cjs           # contextBridge API
├── updater.cjs           # Auto-update logic
├── update-config.cjs     # Update server URL (edit this)
├── run-desktop-build.cjs # Build script (reads update URL)
└── icons/
    ├── README.md
    └── icon.ico          # Add your icon here

src/utils/desktop.js   # Web-safe Electron detection
release/               # Generated installers (gitignored)
```
