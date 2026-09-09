# BCUT Android Application

This document explains how the Android APK version of BCUT works alongside the existing web and Windows desktop apps.

## Architecture

```
BCUT (single React codebase in src/)
        │
        ├── Web target       →  npm run dev / npm run build  →  static dist/
        ├── Desktop target   →  Electron  →  BCUT Setup.exe
        │
        └── Android target   →  Capacitor WebView  →  BCUT.apk
                │
                ├── Development →  Vite dev server + live reload (optional)
                └── Production  →  bundled dist/ inside APK
```

| Layer | Location | Role |
|-------|----------|------|
| React UI | `src/` | Same application as web/desktop |
| Capacitor | `capacitor.config.json` | Native Android shell |
| Android project | `android/` | Gradle project, launcher, permissions |
| Mobile helper | `src/utils/mobile.js` | Native save/share on Android |

Capacitor wraps your existing Vite build in an Android WebView. **The React app is not rewritten.**

## Prerequisites

To build APK files on your computer you need:

1. **Node.js** (already installed)
2. **Java JDK 21** — [Adoptium Temurin](https://adoptium.net/) (Capacitor 7 requires Java 21 for Gradle builds)
3. **Android Studio** — [developer.android.com/studio](https://developer.android.com/studio)

During Android Studio setup, install:

- Android SDK Platform 35 (or latest)
- Android SDK Build-Tools
- Android SDK Command-line Tools

Set environment variable (Windows example):

```powershell
ANDROID_HOME=C:\Users\YOUR_USER\AppData\Local\Android\Sdk
```

Add to PATH:

```
%ANDROID_HOME%\platform-tools
%ANDROID_HOME%\cmdline-tools\latest\bin
```

## Commands

### Web (unchanged)

```bash
npm run dev
npm run build
npm run preview
```

### First-time Android setup

```bash
npm install
npm run android:add
```

Run `android:add` once. It creates the `android/` native project.

### Sync web build into Android

After changing React code:

```bash
npm run android:sync
```

### Open in Android Studio

```bash
npm run android:open
```

Then use **Run ▶** on a device/emulator from Android Studio.

### Build APK from command line

Release APK (install this on phones):

```bash
npm run android:build
```

Output:

```
release/android/BCUT.apk
```

Debug APK (for development only — some phones refuse to install debug builds):

```bash
npm run android:build:debug
```

Output:

```
release/android/BCUT-debug.apk
```

**Install `BCUT.apk` on phones**, not the debug APK. Many Samsung/Xiaomi/Huawei devices show "App not installed" for debuggable APKs.

## Application identity

| Setting | Value |
|---------|-------|
| App name | BCUT |
| App ID | `com.bcut.app` |
| Min Android | API 23 (Android 6.0) |
| Target Android | API 35 |

## Changing the app icon

Replace the default Capacitor launcher icons in:

```
android/app/src/main/res/mipmap-*/
```

Recommended workflow:

1. Start from `src/assets/logo.png`
2. Generate Android mipmap icons (512×512 source) using [Android Asset Studio](https://romannurik.github.io/AndroidAssetStudio/icons-launcher.html) or Android Studio **Image Asset**
3. Replace `ic_launcher.png` and `ic_launcher_round.png` in each `mipmap-*` folder
4. Run `npm run android:sync`

## Export on Android

| Environment | Export behavior |
|-------------|-----------------|
| Web browser | Blob download |
| Windows desktop | Native Save dialog (Electron) |
| Android app | Saves to app Documents, opens **Share** sheet (Save to Files, Gallery, Drive, etc.) |

Detection in React:

```javascript
import { isMobileApp } from './utils/mobile';

if (isMobileApp()) {
  // Capacitor/Android-only path
}
```

## AI background removal on Android

The same client-side AI stack runs inside the Android WebView:

- `@imgly/background-removal`
- `onnxruntime-web`
- WASM bundled in `dist/assets/`

**First run requires internet** to download IMG.LY model files. Later runs may use cached models depending on WebView cache behavior.

`android:largeHeap="true"` is enabled to give the WebView more memory for large WASM models.

## Live reload during development (optional)

1. Find your PC's local IP (example: `192.168.1.10`)
2. Temporarily edit `capacitor.config.json`:

```json
"server": {
  "url": "http://192.168.1.10:5180",
  "cleartext": true
}
```

3. Run `npm run dev` on your PC
4. Run `npm run android:sync` and launch from Android Studio
5. **Remove the `server.url` block before production builds**

Phone and PC must be on the same Wi‑Fi network.

## Signing a release APK

Debug APKs install for testing but cannot be published to Google Play.

For release builds you need a keystore. Create one (once):

```bash
keytool -genkey -v -keystore bcut-release.keystore -alias bcut -keyalg RSA -keysize 2048 -validity 10000
```

Add to `android/gradle.properties` (do **not** commit secrets):

```properties
BCUT_RELEASE_STORE_FILE=../bcut-release.keystore
BCUT_RELEASE_STORE_PASSWORD=your-password
BCUT_RELEASE_KEY_ALIAS=bcut
BCUT_RELEASE_KEY_PASSWORD=your-password
```

Then configure signing in `android/app/build.gradle` (see Android Studio docs).

**Never commit keystore files or passwords to git.**

## Publishing

| Method | Notes |
|--------|-------|
| Direct APK | Share `BCUT.apk` / `BCUT-debug.apk` — users enable "Install unknown apps" |
| Google Play | Requires signed AAB, developer account, store listing |

Google Play prefers **AAB** (Android App Bundle), not APK. Build from Android Studio: **Build → Generate Signed Bundle/APK**.

## Automatic updates

Unlike the Windows desktop app (electron-updater), **Android has no in-app auto-update configured**.

Updates are delivered by:

- Publishing a new APK/AAB to Google Play, or
- Users manually installing a newer APK

## Troubleshooting

### "App not installed" on the phone

Try these in order:

1. **Use the release APK**, not the debug one:
   ```
   release/android/BCUT.apk
   ```
   Many phones (Samsung, Xiaomi, Huawei, etc.) block debug APKs.

2. **Uninstall any old BCUT** first:
   - Settings → Apps → search **BCUT** → Uninstall
   - Or uninstall `com.bcut.app` if a previous test install exists

3. **Transfer the APK without corrupting it**:
   - Do **not** use WhatsApp/Telegram if possible (they can corrupt APKs)
   - Use USB cable, Google Drive, email, or a direct file copy

4. **Enable unknown app installs** for the app you use to open the APK (Files, Chrome, Drive, etc.)

5. **Free storage**: ensure at least **200 MB** free (the app unpacks ~30 MB of assets)

6. **Play Protect**: if Google Play Protect blocks it, tap **Install anyway** / **More details**

7. Rebuild a fresh APK:
   ```bash
   npm run android:build
   ```

### `ANDROID_HOME not set`

Install Android Studio and set the `ANDROID_HOME` environment variable.

### Gradle build fails

Open the project in Android Studio first — it downloads missing SDK components.

### Blank screen in APK

1. Run `npm run build` — ensure `dist/` exists
2. Run `npm run android:sync`
3. Rebuild the APK

### AI removal fails on phone

1. Confirm internet on first use
2. Test on a device with 4 GB+ RAM
3. Connect phone via USB and inspect with Chrome DevTools: `chrome://inspect`

### Export/share fails

Ensure `@capacitor/filesystem` and `@capacitor/share` are installed and synced:

```bash
npm run android:sync
```

## File structure

```
capacitor.config.json     # Capacitor app config
android/                  # Native Android project (commit this)
scripts/run-android-build.cjs
src/utils/mobile.js       # Android helpers
release/android/          # Generated APK output (gitignored)
```

## Limitations

- **No iOS** in this setup (Android only)
- **Clipboard copy** may be limited in Android WebView
- **Drag & drop** is desktop-oriented; mobile uses tap-to-upload
- **Google Fonts** still load from CDN unless bundled locally
- **Fully offline AI** is not guaranteed on first run
