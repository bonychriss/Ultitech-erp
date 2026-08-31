import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App.jsx'
import './sales-reports.css'

const rootEl = document.getElementById('root')
if (!rootEl) {
  console.error('Sales Reports: #root element not found')
} else {
  ReactDOM.createRoot(rootEl).render(<App />)
}
