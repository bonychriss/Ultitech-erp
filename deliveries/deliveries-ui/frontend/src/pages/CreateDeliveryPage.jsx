import { useCallback } from 'react'
import CreateDeliveryModal from '../components/CreateDeliveryModal.jsx'
import { CFG } from '../config.js'

/**
 * Standalone create-delivery route: opens the simplified popup over the shell.
 * Cancel returns to the deliveries dashboard.
 */
export default function CreateDeliveryPage() {
  const urls = CFG.data?.urls || {}
  const createDispatch = Boolean(CFG.createDispatch || CFG.data?.createDispatch)

  const close = useCallback(() => {
    const target = createDispatch
      ? (urls.dispatchDashboard || urls.dashboard || 'index')
      : (urls.dashboard || 'index')
    window.location.href = target
  }, [createDispatch, urls.dashboard, urls.dispatchDashboard])

  return (
    <CreateDeliveryModal
      open
      onClose={close}
      createDispatch={createDispatch}
    />
  )
}
