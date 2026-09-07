import accountingIcon from '../assets/modules/accounting-icon.png'
import salesIcon from '../assets/modules/sales-icon.png'
import stockIcon from '../assets/modules/stock-icon.png'
import payrollIcon from '../assets/modules/payroll-icon.png'
import voucherIcon from '../assets/modules/voucher-icon.png'
import deliveryIcon from '../assets/modules/delivery-icon.png'
import statementIcon from '../assets/modules/statement-icon.png'
import budgetsIcon from '../assets/modules/budgets-icon.png'

/** Clockwise from top � palette matches the interlocking ERP loop reference. */
export const ERP_MODULES = [
  {
    key: 'ops',
    label: 'Operations Control',
    detail: 'Automate workflows and cross-team processes.',
    icon: budgetsIcon,
    color: '#d946ef',
  },
  {
    key: 'hr',
    label: 'HR & Payroll',
    detail: 'Handle attendance, payroll, and staff records.',
    icon: payrollIcon,
    color: '#1e3a8a',
  },
  {
    key: 'voucher',
    label: 'Voucher Workflow',
    detail: 'Create, review, and approve expense vouchers.',
    icon: voucherIcon,
    color: '#0ea5e9',
  },
  {
    key: 'inventory',
    label: 'Inventory & Stock',
    detail: 'Track stock levels, movement, and audits.',
    icon: stockIcon,
    color: '#14b8a6',
  },
  {
    key: 'reports',
    label: 'Live Reports',
    detail: 'Monitor KPIs with real-time business visibility.',
    icon: statementIcon,
    color: '#eab308',
  },
  {
    key: 'logistics',
    label: 'Logistics & Delivery',
    detail: 'Coordinate dispatch, delivery notes, and routes.',
    icon: deliveryIcon,
    color: '#f97316',
  },
  {
    key: 'sales',
    label: 'Sales Management',
    detail: 'Control orders, quotations, and invoicing.',
    icon: salesIcon,
    color: '#ef4444',
  },
  {
    key: 'finance',
    label: 'Finance & Accounting',
    detail: 'Manage budgets, cashflow, and reconciliations.',
    icon: accountingIcon,
    color: '#fb7185',
  },
]

function polar(cx, cy, r, deg) {
  const rad = ((deg - 90) * Math.PI) / 180
  return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) }
}

function circleArc(nx, ny, r, startDeg, endDeg) {
  const start = polar(nx, ny, r, startDeg)
  const end = polar(nx, ny, r, endDeg)
  const delta = ((endDeg - startDeg) % 360 + 360) % 360
  const large = delta > 180 ? 1 : 0
  return `M ${start.x} ${start.y} A ${r} ${r} 0 ${large} 1 ${end.x} ${end.y}`
}

function RibbonArc({ d, color, width }) {
  return (
    <>
      <path d={d} fill="none" stroke="#fff" strokeWidth={width + 8} strokeLinecap="butt" />
      <path d={d} fill="none" stroke={color} strokeWidth={width} strokeLinecap="butt" />
    </>
  )
}

/**
 * Interlocking circular ERP loops (olympic-ring weave) with a slow orbit.
 * Center label stays fixed; icons/labels counter-rotate to stay upright.
 */
export default function ErpOrbitDiagram() {
  const size = 720
  const cx = size / 2
  const cy = size / 2
  const orbitR = 222
  const ringR = 96
  const strokeW = 28
  const n = ERP_MODULES.length
  const step = 360 / n
  // Slightly more than 180� so the two halves meet without a hairline gap.
  const half = 186

  const nodes = ERP_MODULES.map((mod, i) => {
    const mid = i * step
    const pos = polar(cx, cy, orbitR, mid)
    return {
      ...mod,
      mid,
      x: pos.x,
      y: pos.y,
      under: circleArc(pos.x, pos.y, ringR, mid + 180, mid + 180 + half),
      over: circleArc(pos.x, pos.y, ringR, mid, mid + half),
    }
  })

  return (
    <div className="erp-orbit" role="img" aria-label="ERP Enterprise Resource Planning modules diagram">
      <svg className="erp-orbit-svg" viewBox={`0 0 ${size} ${size}`} aria-hidden="true">
        <g className="erp-orbit-spin">
          {nodes.map((node) => (
            <RibbonArc key={`under-${node.key}`} d={node.under} color={node.color} width={strokeW} />
          ))}
          {nodes.map((node) => (
            <RibbonArc key={`over-${node.key}`} d={node.over} color={node.color} width={strokeW} />
          ))}

          {nodes.map((node) => (
            <g key={`node-${node.key}`} transform={`translate(${node.x}, ${node.y})`}>
              <g className="erp-orbit-counter">
                <image
                  href={node.icon}
                  x="-20"
                  y="-28"
                  width="40"
                  height="40"
                  preserveAspectRatio="xMidYMid meet"
                />
                <foreignObject x="-58" y="14" width="116" height="44">
                  <div xmlns="http://www.w3.org/1999/xhtml" className="erp-orbit-label">
                    {node.label}
                  </div>
                </foreignObject>
              </g>
            </g>
          ))}
        </g>
      </svg>

      <div className="erp-orbit-core">
        <strong>ERP</strong>
        <span>Enterprise Resource Planning Module</span>
      </div>
    </div>
  )
}
