# BCUT — Background Remover

React + Vite image editor with in-browser AI background removal.

## Web

```bash
npm install
npm run dev      # http://localhost:5180
npm run build    # static dist/ for hosting
npm run preview
```

## Windows Desktop

See **[DESKTOP_APP.md](./DESKTOP_APP.md)** for full documentation.

```bash
npm run electron:dev     # development (Vite + Electron)
npm run desktop:build    # produces release/BCUT Setup.exe
```

## Android

See **[MOBILE_APP.md](./MOBILE_APP.md)** for full documentation.

Requires Android Studio + JDK 21 for APK builds.

```bash
npm run android:add          # first-time setup (creates android/)
npm run android:sync         # copy latest web build into Android project
npm run android:open         # open in Android Studio
npm run android:build        # produces release/android/BCUT.apk (install this on phones)
npm run android:build:debug  # produces release/android/BCUT-debug.apk
```
