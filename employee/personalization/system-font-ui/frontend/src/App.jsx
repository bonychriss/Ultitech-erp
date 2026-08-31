import { useEffect } from 'react'
import SystemFontPage from './pages/SystemFontPage.jsx'

export default function App() {
  useEffect(() => {
    document.title = 'System Font - ERP'
  }, [])

  return (
    <div className="sf-app">
      <SystemFontPage />
    </div>
  )
}
