# BCUT Desktop Icons

Place Windows installer icons in this folder.

## Required for custom branding

| File | Size | Used for |
|------|------|----------|
| `icon.ico` | 256×256 (multi-size .ico recommended) | App icon, installer, shortcuts |

## How to add your icon

1. Export your BCUT logo as a **256×256** PNG.
2. Convert it to `.ico` (use [icoconvert.com](https://icoconvert.com/) or GIMP).
3. Save as `electron/icons/icon.ico`.
4. Rebuild: `npm run desktop:build`

Until `icon.ico` is present, electron-builder uses the default Electron icon.

## Optional

You can also add `icon.png` (512×512) for future macOS/Linux builds.
