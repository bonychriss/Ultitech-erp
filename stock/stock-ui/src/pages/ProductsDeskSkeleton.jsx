import React from 'react';

function Bone({ className = '', style }) {
  return <span className={`prod-desk-bone ${className}`.trim()} style={style} aria-hidden="true" />;
}

export default function ProductsDeskSkeleton({ rows = 8 }) {
  return (
    <div className="prod-desk-page prod-desk-skeleton" role="status" aria-live="polite" aria-busy="true">
      <span className="sr-only">Loading products…</span>

      <div className="prod-desk-page-header">
        <div className="prod-desk-page-header-search">
          <Bone className="prod-desk-bone--search" />
        </div>
        <div className="prod-desk-page-header-actions">
          <Bone className="prod-desk-bone--icon" />
          <Bone className="prod-desk-bone--btn" />
        </div>
      </div>

      <section className="prod-desk-kpi-grid" aria-hidden="true">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="prod-desk-kpi-card prod-desk-kpi-card--skeleton">
            <Bone className="prod-desk-bone--kpi-icon" />
            <div className="prod-desk-skeleton-kpi-text">
              <Bone className="prod-desk-bone--label" />
              <Bone className="prod-desk-bone--value" />
            </div>
          </div>
        ))}
      </section>

      <section className="prod-desk-results" aria-hidden="true">
        <div className="prod-desk-results-head">
          <Bone className="prod-desk-bone--count" />
        </div>
        <div className="prod-desk-table-wrap">
          <table className="prod-desk-table">
            <thead>
              <tr>
                <th style={{ width: 40 }}><Bone className="prod-desk-bone--check" /></th>
                <th><Bone className="prod-desk-bone--th" style={{ width: '40%' }} /></th>
                <th><Bone className="prod-desk-bone--th" style={{ width: '18%' }} /></th>
                <th><Bone className="prod-desk-bone--th" style={{ width: '16%' }} /></th>
                <th style={{ textAlign: 'center' }}><Bone className="prod-desk-bone--th" style={{ width: 48, margin: '0 auto' }} /></th>
                <th style={{ textAlign: 'right' }}><Bone className="prod-desk-bone--th" style={{ width: 72, marginLeft: 'auto' }} /></th>
              </tr>
            </thead>
            <tbody>
              {Array.from({ length: rows }).map((_, i) => (
                <tr key={i}>
                  <td><Bone className="prod-desk-bone--check" /></td>
                  <td>
                    <div className="prod-desk-product">
                      <Bone className="prod-desk-bone--thumb" />
                      <div className="prod-desk-skeleton-kpi-text" style={{ flex: 1 }}>
                        <Bone className="prod-desk-bone--name" />
                        <Bone className="prod-desk-bone--code" />
                      </div>
                    </div>
                  </td>
                  <td><Bone className="prod-desk-bone--cell" /></td>
                  <td>
                    <Bone className="prod-desk-bone--cell" />
                    <Bone className="prod-desk-bone--code" style={{ marginTop: 6 }} />
                  </td>
                  <td style={{ textAlign: 'center' }}>
                    <Bone className="prod-desk-bone--stock" style={{ margin: '0 auto' }} />
                  </td>
                  <td style={{ textAlign: 'right' }}>
                    <div className="prod-desk-actions" style={{ justifyContent: 'flex-end' }}>
                      <Bone className="prod-desk-bone--icon-sm" />
                      <Bone className="prod-desk-bone--icon-sm" />
                      <Bone className="prod-desk-bone--icon-sm" />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
