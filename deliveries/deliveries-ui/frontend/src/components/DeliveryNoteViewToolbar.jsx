export default function DeliveryNoteViewToolbar({
  displayNumber,
  pipeline,
  urls,
  flags,
  pdfDownloading,
  desktopActionsOpen,
  mobileActionsOpen,
  desktopDropdownRef,
  onDesktopActionsToggle,
  onMobileActionsToggle,
  onCloseDesktopActions,
  onDownloadPdf,
}) {
  const hasActionLinks = flags.has_order || flags.has_invoice;

  return (
    <header className="ov-chrome ov-no-print">
      <div className="ov-control-panel">
        <nav className="ov-breadcrumb" aria-label="Breadcrumb">
          {urls.delivery_notes_index ? (
            <>
              <a href={urls.delivery_notes_index}>Delivery Notes</a>
              <span className="ov-breadcrumb-sep">/</span>
            </>
          ) : null}
          <span className="active">{displayNumber}</span>
        </nav>

        <div className="ov-pipeline" aria-label="Delivery note status">
          {(pipeline || []).map((stage) => (
            <div
              key={stage.key}
              className={`ov-pipeline-item${stage.state === 'active' ? ' active' : stage.state === 'done' ? ' done' : ''}`}
            >
              {stage.label}
            </div>
          ))}
        </div>
      </div>

      <div className="ov-action-bar">
        <div className="ov-action-inner">
          <div className="ov-btn-group ov-action-desktop">
            <div className="ov-actions-cluster">
              <details
                className="ov-actions-dropdown"
                ref={desktopDropdownRef}
                open={desktopActionsOpen}
                onToggle={(event) => onDesktopActionsToggle(event.currentTarget.open)}
              >
                <summary className="ov-actions-summary">
                  <i className="fas fa-bars" aria-hidden="true" />
                  Actions
                  <i className="fas fa-caret-down" aria-hidden="true" />
                </summary>
                <div className="ov-actions-panel">
                  {flags.has_order && urls.order_view ? (
                    <a className="ov-actions-item" href={urls.order_view} onClick={onCloseDesktopActions}>
                      <i className="fas fa-truck" aria-hidden="true" />
                      View Delivery Order
                    </a>
                  ) : null}
                  {flags.has_invoice && urls.invoice_view ? (
                    <a
                      className="ov-actions-item"
                      href={urls.invoice_view}
                      target="_blank"
                      rel="noreferrer"
                      onClick={onCloseDesktopActions}
                    >
                      <i className="fas fa-file-invoice" aria-hidden="true" />
                      View Invoice
                    </a>
                  ) : null}
                  {hasActionLinks && flags.can_download ? (
                    <hr className="ov-actions-divider" />
                  ) : null}
                  {flags.can_download ? (
                    <button
                      type="button"
                      className="ov-actions-item"
                      disabled={pdfDownloading}
                      onClick={() => { onCloseDesktopActions(); onDownloadPdf(); }}
                    >
                      <i className={`fas ${pdfDownloading ? 'fa-spinner fa-spin' : 'fa-download'}`} aria-hidden="true" />
                      Download PDF
                    </button>
                  ) : null}
                </div>
              </details>
            </div>
          </div>

          <details
            className="ov-action-bar-mobile"
            open={mobileActionsOpen}
            onToggle={(event) => onMobileActionsToggle(event.currentTarget.open)}
          >
            <summary>
              <i className="fas fa-bars" aria-hidden="true" />
              {' '}
              Actions
            </summary>
            <div className="ov-mobile-panel">
              {flags.has_order && urls.order_view ? (
                <a className="ov-mobile-item" href={urls.order_view}>
                  <i className="fas fa-truck" aria-hidden="true" />
                  View Delivery Order
                </a>
              ) : null}
              {flags.has_invoice && urls.invoice_view ? (
                <a className="ov-mobile-item" target="_blank" rel="noreferrer" href={urls.invoice_view}>
                  <i className="fas fa-file-invoice" aria-hidden="true" />
                  View Invoice
                </a>
              ) : null}
              {flags.can_download ? (
                <button type="button" className="ov-mobile-item" disabled={pdfDownloading} onClick={onDownloadPdf}>
                  <i className={`fas ${pdfDownloading ? 'fa-spinner fa-spin' : 'fa-download'}`} aria-hidden="true" />
                  Download PDF
                </button>
              ) : null}
            </div>
          </details>
        </div>
      </div>
    </header>
  );
}
