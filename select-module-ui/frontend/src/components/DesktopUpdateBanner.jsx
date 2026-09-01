import { useEffect, useState } from 'react'
import { Gift } from 'lucide-react'

function dismissStorageKey(version) {
  return `ultitech_desktop_update_dismiss_${version || 'latest'}`
}

function readDismissed(version) {
  try {
    return localStorage.getItem(dismissStorageKey(version)) === '1'
  } catch {
    return false
  }
}

function writeDismissed(version) {
  try {
    localStorage.setItem(dismissStorageKey(version), '1')
  } catch {
    /* ignore */
  }
}

export function isDesktopErpClient() {
  return Boolean(
    typeof window !== 'undefined' &&
      window.ultitechClient &&
      window.ultitechClient.platform === 'desktop'
  )
}

function getDesktopClient() {
  return isDesktopErpClient() ? window.ultitechClient : null
}

function compareVersions(a, b) {
  const pa = String(a || '0').split('.').map((n) => parseInt(n, 10) || 0)
  const pb = String(b || '0').split('.').map((n) => parseInt(n, 10) || 0)
  const len = Math.max(pa.length, pb.length)
  for (let i = 0; i < len; i += 1) {
    const da = pa[i] || 0
    const db = pb[i] || 0
    if (da > db) return 1
    if (da < db) return -1
  }
  return 0
}

export default function DesktopUpdateBanner({ desktopUpdate, desktopAppDownloadUrl, onVisibilityChange }) {
  const latestVersion = desktopUpdate?.latestVersion || ''
  const downloadUrl = desktopUpdate?.downloadUrl || desktopAppDownloadUrl || ''
  const [client, setClient] = useState(null)

  /** @type {['hidden' | 'available' | 'downloading' | 'ready' | 'uptodate', function]} */
  const [phase, setPhase] = useState('hidden')
  const [version, setVersion] = useState(latestVersion)
  const [percent, setPercent] = useState(0)

  useEffect(() => {
    let cancelled = false
    let attempts = 0

    const detectClient = () => {
      if (cancelled) return
      const detected = getDesktopClient()
      if (detected) {
        setClient(detected)
        return
      }
      attempts += 1
      if (attempts < 30) {
        window.setTimeout(detectClient, 100)
      }
    }

    detectClient()
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    onVisibilityChange?.(phase !== 'hidden' && phase !== 'uptodate')
  }, [phase, onVisibilityChange])

  useEffect(() => {
    const onDesktopEvent = (event) => {
      const detail = event?.detail || {}
      const type = detail.type

      if (type === 'available') {
        if (!getDesktopClient()) {
          return
        }
        setVersion(detail.version || latestVersion)
        setPhase('available')
        return
      }
      if (type === 'downloading') {
        setVersion(detail.version || version || latestVersion)
        if (typeof detail.percent === 'number') {
          setPercent(detail.percent)
        }
        setPhase('downloading')
        return
      }
      if (type === 'ready') {
        setVersion(detail.version || version || latestVersion)
        setPhase('ready')
        return
      }
      if (type === 'dismiss') {
        setPhase('hidden')
        return
      }
      if (type === 'up-to-date') {
        setPhase('uptodate')
        window.setTimeout(() => setPhase('hidden'), 3500)
      }
    }

    window.addEventListener('ultitech:desktop-update', onDesktopEvent)
    return () => window.removeEventListener('ultitech:desktop-update', onDesktopEvent)
  }, [latestVersion, version])

  useEffect(() => {
    if (!client) {
      return
    }

    const replayPending = () => {
      try {
        const pending = window.__ULTITECH_DESKTOP_UPDATE_PENDING__
        if (pending?.type === 'available' && !readDismissed(pending.version || latestVersion)) {
          setVersion(pending.version || latestVersion)
          setPhase('available')
        }
      } catch {
        /* ignore */
      }
    }

    replayPending()

    client.checkForUpdates?.().catch(() => {
      /* silent; banner also driven by server version + IPC events */
    })

    if (!latestVersion || readDismissed(latestVersion)) {
      return
    }

    const installed = client.version || '0'
    if (compareVersions(installed, latestVersion) < 0) {
      setVersion(latestVersion)
      setPhase('available')
    }
  }, [client, latestVersion])

  useEffect(() => {
    if (!client || !latestVersion || phase !== 'available') {
      return
    }
    const installed = client.version || '0'
    if (compareVersions(installed, latestVersion) >= 0) {
      setPhase('hidden')
    }
  }, [client, latestVersion, phase])

  if (!client) {
    return null
  }

  if (phase === 'hidden' || phase === 'uptodate') {
    if (phase === 'uptodate') {
      return (
        <div className="sm-desktop-update-banner sm-desktop-update-banner--toast" role="status">
          <div className="sm-desktop-update-left">
            <Gift className="sm-desktop-update-icon" strokeWidth={1.75} aria-hidden="true" />
            <span>You have the latest desktop app</span>
          </div>
        </div>
      )
    }
    return null
  }

  const message = (() => {
    if (phase === 'downloading') {
      return `Downloading update${percent > 0 ? `  ${Math.round(percent)}%` : ''}`
    }
    if (phase === 'ready') {
      return version ? `Update ${version} ready to install` : 'Update ready to install'
    }
    const v = version || latestVersion
    return v ? `Desktop app ${v} available` : 'Desktop app update available'
  })()

  const primaryLabel = (() => {
    if (phase === 'downloading') return 'Downloading'
    if (phase === 'ready') return 'Install Now'
    return 'Download'
  })()

  const onLater = () => {
    writeDismissed(version || latestVersion)
    setPhase('hidden')
    client?.dismissUpdate?.()
  }

  const onPrimary = () => {
    if (phase === 'ready') {
      client.installUpdate?.()
      return
    }
    if (phase === 'available') {
      setPhase('downloading')
      client.downloadUpdate?.()
    }
  }

  return (
    <div className="sm-desktop-update-banner" role="status">
      <div className="sm-desktop-update-left">
        <Gift className="sm-desktop-update-icon" strokeWidth={1.75} aria-hidden="true" />
        <span className="sm-desktop-update-text">{message}</span>
      </div>
      <div className="sm-desktop-update-actions">
        <button type="button" className="sm-desktop-update-later" onClick={onLater}>
          Later
        </button>
        <button
          type="button"
          className="sm-desktop-update-primary"
          onClick={onPrimary}
          disabled={phase === 'downloading'}
        >
          {primaryLabel}
        </button>
      </div>
    </div>
  )
}
