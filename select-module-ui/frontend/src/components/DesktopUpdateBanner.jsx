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

function getDesktopClient() {
  if (typeof window !== 'undefined' && window.ultitechClient && window.ultitechClient.platform === 'desktop') {
    return window.ultitechClient
  }
  return null
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

export default function DesktopUpdateBanner({ desktopUpdate, desktopAppDownloadUrl }) {
  const latestVersion = desktopUpdate?.latestVersion || ''
  const downloadUrl = desktopUpdate?.downloadUrl || desktopAppDownloadUrl || ''
  const client = getDesktopClient()

  /** @type {['hidden' | 'available' | 'downloading' | 'ready' | 'uptodate', function]} */
  const [phase, setPhase] = useState('hidden')
  const [version, setVersion] = useState(latestVersion)
  const [percent, setPercent] = useState(0)

  useEffect(() => {
    const onDesktopEvent = (event) => {
      const detail = event?.detail || {}
      const type = detail.type

      if (type === 'available') {
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

    if (client) {
      client.checkForUpdates?.().catch(() => {
        /* handled via events or silent */
      })
    } else if (latestVersion && downloadUrl && !readDismissed(latestVersion)) {
      setVersion(latestVersion)
      setPhase('available')
    }

    return () => window.removeEventListener('ultitech:desktop-update', onDesktopEvent)
  }, [client, latestVersion, downloadUrl])

  useEffect(() => {
    if (!client || !latestVersion || phase === 'hidden') {
      return
    }
    const installed = client.version || '0'
    if (compareVersions(installed, latestVersion) >= 0 && phase === 'available') {
      setPhase('hidden')
    }
  }, [client, latestVersion, phase])

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
      return `Downloading update${percent > 0 ? ` ${Math.round(percent)}%` : ''}`
    }
    if (phase === 'ready') {
      return version ? `Update ${version} ready to install` : 'Update ready to install'
    }
    return client ? 'New update available' : `Desktop app ${version || latestVersion} available`
  })()

  const primaryLabel = (() => {
    if (phase === 'downloading') return 'Downloading'
    if (phase === 'ready') return 'Install Now'
    return client ? 'Install Now' : 'Download'
  })()

  const onLater = () => {
    writeDismissed(version || latestVersion)
    setPhase('hidden')
    client?.dismissUpdate?.()
  }

  const onPrimary = () => {
    if (client) {
      if (phase === 'ready') {
        client.installUpdate?.()
        return
      }
      if (phase === 'available') {
        setPhase('downloading')
        client.downloadUpdate?.()
      }
      return
    }
    if (downloadUrl) {
      window.location.href = downloadUrl
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
        {client || downloadUrl ? (
          <button
            type="button"
            className="sm-desktop-update-primary"
            onClick={onPrimary}
            disabled={phase === 'downloading'}
          >
            {primaryLabel}
          </button>
        ) : null}
      </div>
    </div>
  )
}
