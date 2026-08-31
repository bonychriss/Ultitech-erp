import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './App'
import './index.css'

const initial = window.__INITIAL_SETTINGS__ || {}

createRoot(document.getElementById('settings-root')).render(
  React.createElement(App, { initial })
)
