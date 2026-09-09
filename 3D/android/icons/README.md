# Android launcher icons

The Android app uses default Capacitor launcher icons until you replace them.

## Replace icons

1. Use your brand image (recommended source: `src/assets/logo.png`)
2. Generate launcher icons for all densities
3. Replace files in:

```
android/app/src/main/res/mipmap-mdpi/ic_launcher.png
android/app/src/main/res/mipmap-mdpi/ic_launcher_round.png
android/app/src/main/res/mipmap-hdpi/ic_launcher.png
...
android/app/src/main/res/mipmap-xxxhdpi/ic_launcher_round.png
```

4. Run:

```bash
npm run android:sync
```

## Tools

- [Android Asset Studio — Launcher Icon Generator](https://romannurik.github.io/AndroidAssetStudio/icons-launcher.html)
- Android Studio → **File → New → Image Asset**

Do not change the `@mipmap/ic_launcher` references in `AndroidManifest.xml` unless you rename the generated files.
