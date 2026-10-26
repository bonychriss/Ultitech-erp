import { useMemo, useState } from 'react'
import { getLayout, layouts } from './layouts/index.js'
import './App.css'

export default function App() {
  const [active, setActive] = useState(layouts[0].id)
  const current = useMemo(() => getLayout(active), [active])
  const Layout = current.Component

  return (
    <div className="app">
      <aside className="library no-print">
        <div className="library-top">
          <p className="library-eyebrow">Invoice library</p>
          <h1 className="library-title">Layouts</h1>
          <p className="library-sub">Pick a template. Print exports the active page only.</p>
        </div>

        <ul className="layout-list">
          {layouts.map((item) => (
            <li key={item.id}>
              <button
                type="button"
                className={`layout-card ${active === item.id ? 'on' : ''}`}
                onClick={() => setActive(item.id)}
              >
                <span className="layout-num">Layout {item.id}</span>
                <span className="layout-name">{item.name}</span>
                <span className="layout-blurb">{item.blurb}</span>
              </button>
            </li>
          ))}
        </ul>
      </aside>

      <main className="stage">
        <div className="stage-bar no-print">
          <p>
            Viewing <strong>Layout {current.id}</strong> — {current.name}
          </p>
          <button type="button" className="print-btn" onClick={() => window.print()}>
            Print
          </button>
        </div>

        <div className="sheet-wrap">
          <Layout />
        </div>
      </main>
    </div>
  )
}
