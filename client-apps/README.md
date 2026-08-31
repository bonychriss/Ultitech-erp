# UltiTech ERP — Desktop & Android Clients

Thin native wrappers around the **existing hosted PHP ERP**. They load your live server in a WebView and do **not** modify PHP modules, React builds, or Apache routing.

| Client | Technology | Output |
|--------|------------|--------|
| Desktop (Windows) | Electron | `UltiTech-ERP-Setup-1.0.0.exe` |
| Android | Capacitor | `app-debug.apk` or signed release APK |

Production default URL: `https://ultitech.io/login.php`  
Local XAMPP URL: `http://localhost/public_html/login.php`

---

## Prerequisites

### Desktop
- [Node.js 20+](https://nodejs.org/)
- Windows 10/11 (for building the installer)

### Android
- Node.js 20+
- [Android Studio](https://developer.android.com/studio) (includes Android SDK + JDK)
- Set `ANDROID_HOME` or install SDK via Android Studio defaults

---

## Quick start — Desktop

```powershell
cd client-apps\desktop
npm install
npm start
```

Use local XAMPP instead of production:

```powershell
npm run start:local
```

Build Windows installer:

```powershell
npm run dist:win
```

Installer output: `client-apps\desktop\dist\`

---

## Quick start — Android APK

1. Edit `client-apps\android\config.json` if needed (production URL is preconfigured).

2. For **phone testing against local XAMPP** on the same Wi?Fi:
   - Find your PC LAN IP (`ipconfig`, e.g. `192.168.1.100`)
   - Set `"useLocalDev": true` and update `"localDevUrl"` to `http://YOUR_IP/public_html/login.php`
   - Ensure Apache allows connections from LAN (not only localhost)

3. Install and generate the Android project:

```powershell
cd client-apps\android
npm install
npm run sync
npx cap open android
```

4. In **Android Studio**: Build ? Build Bundle(s) / APK(s) ? Build APK(s).

   Or from the command line (after SDK is installed):

```powershell
npm run build:apk
```

APK path: `client-apps\android\android\app\build\outputs\apk\debug\app-debug.apk`

---

## Configuration

Both clients read from their own `config.json` (copied from `shared/config.example.json`).

| Key | Purpose |
|-----|---------|
| `startUrl` | Production server entry (login page) |
| `localDevUrl` | XAMPP / LAN URL for development |
| `useLocalDev` | `true` = use `localDevUrl` |
| `allowedHosts` | Hostnames allowed inside the app WebView |
| `userAgentSuffix` | Appended to browser user-agent (optional server detection) |

Environment overrides (desktop):

```powershell
$env:ULTITECH_APP_URL = "https://ultitech.io/ultimate/login"
$env:ULTITECH_USE_LOCAL_DEV = "true"
npm start
```

---

## What stays unchanged

- All PHP backend code, `.htaccess` tenant routing, and session auth
- All 45 module React frontends and their build pipelines
- Production deployment on StackCP / Apache
- Users log in and use modules exactly as in the browser

The wrappers only provide a dedicated window (desktop) or installed app icon (Android) pointing at the same URLs.

---

## Icons & signing (optional)

- **Desktop:** add `client-apps/desktop/build/icon.ico` (256×256) and reference it in `package.json` ? `build.win.icon`
- **Android:** replace launcher icons in `android/app/src/main/res/mipmap-*` after `cap add android`
- **Release APK:** configure signing in Android Studio (Build ? Generate Signed Bundle / APK)

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Blank screen on Android | Confirm `startUrl` is reachable over HTTPS; check `allowNavigation` in `capacitor.config.json` |
| Login works in browser but not app | Ensure cookies are enabled; use HTTPS in production |
| Local XAMPP unreachable from phone | Use PC LAN IP, not `localhost`; check Windows firewall for Apache |
| Downloads fail on desktop | Files save to the system Downloads folder automatically |

---

## Folder layout

```
client-apps/
??? shared/           # Shared config example + loader
??? desktop/          # Electron app
??? android/          # Capacitor app (android/ created after npm run sync)
```
