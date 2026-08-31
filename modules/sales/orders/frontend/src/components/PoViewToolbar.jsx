export default function PoViewToolbar({
  displayNumber,
  pipeline,
  urls,
  flags,
  share,
  po,
  emailMeta,
  pdfDownloading,
  desktopActionsOpen,
  mobileActionsOpen,
  desktopDropdownRef,
  onDesktopActionsToggle,
  onMobileActionsToggle,
  onCloseDesktopActions,
  onApprove,
  onDownloadPdf,
  onCopyPortalLink,
  onSendToSupplier,
  onOpenEmailModal,
  onOpenUploadModal,
  onOpenPoInfoModal,
}) {
  return (
    <header className="ov-chrome ov-no-print">
      <div className="ov-control-panel">
        <nav className="ov-breadcrumb" aria-label="Breadcrumb">
          {urls.index ? (
            <>
              <a href={urls.index}>
                <i className="fas fa-arrow-left me-1" aria-hidden="true" />
                Purchases
              </a>
              <span className="ov-breadcrumb-sep">/</span>
            </>
          ) : null}
          <span className="active">{displayNumber}</span>
        </nav>

        <div className="ov-pipeline" aria-label="Purchase order status">
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
            {flags.can_send_to_supplier ? (
              <button type="button" className="ov-btn ov-btn-primary" onClick={onSendToSupplier}>
                Send to Supplier
              </button>
            ) : null}

            {flags.can_approve ? (
              <button type="button" className="ov-btn ov-btn-primary" onClick={onApprove}>
                Approve PO
              </button>
            ) : null}

            {(flags.can_send_to_supplier || flags.can_approve) ? (
              <span className="ov-btn-divider" aria-hidden="true" />
            ) : null}

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
                  {flags.can_edit && urls.edit ? (
                    <a className="ov-actions-item" href={urls.edit} onClick={onCloseDesktopActions}>
                      <i className="fas fa-edit" aria-hidden="true" />
                      Edit PO
                    </a>
                  ) : null}
                  {flags.has_invoice_attachment && urls.invoice_view ? (
                    <a className="ov-actions-item" href={urls.invoice_view} target="_blank" rel="noreferrer" onClick={onCloseDesktopActions}>
                      <i className="fas fa-file-invoice" aria-hidden="true" />
                      View Invoice
                    </a>
                  ) : null}
                  {flags.can_upload_invoice ? (
                    <button type="button" className="ov-actions-item" onClick={() => { onCloseDesktopActions(); onOpenUploadModal(); }}>
                      <i className="fas fa-file-upload" aria-hidden="true" />
                      Attach Invoice
                    </button>
                  ) : null}
                  {flags.can_copy_portal_link ? (
                    <button type="button" className="ov-actions-item" onClick={() => { onCloseDesktopActions(); onCopyPortalLink(); }}>
                      <i className="fas fa-link" aria-hidden="true" />
                      Copy Portal Link
                    </button>
                  ) : null}
                  <button type="button" className="ov-actions-item" onClick={() => { onCloseDesktopActions(); onOpenPoInfoModal(); }}>
                    <i className="fas fa-circle-info" aria-hidden="true" />
                    PO Info
                  </button>
                  <hr className="ov-actions-divider" />
                  <button type="button" className="ov-actions-item" onClick={() => { window.print(); onCloseDesktopActions(); }}>
                    <i className="fas fa-print" aria-hidden="true" />
                    Print
                  </button>
                  <button
                    type="button"
                    className="ov-actions-item"
                    disabled={pdfDownloading}
                    onClick={() => { onCloseDesktopActions(); onDownloadPdf(); }}
                  >
                    <i className={`fas ${pdfDownloading ? 'fa-spinner fa-spin' : 'fa-download'}`} aria-hidden="true" />
                    Download PDF
                  </button>
                </div>
              </details>
            </div>

            <span className="ov-btn-divider" aria-hidden="true" />

            {share.whatsapp_url ? (
              <a href={share.whatsapp_url} target="_blank" rel="noreferrer" className="ov-btn ov-btn-secondary ov-btn-icon text-success" title="WhatsApp">
                <i className="fab fa-whatsapp" aria-hidden="true" />
              </a>
            ) : null}

            {po?.supplier_email ? (
              <button type="button" className="ov-btn ov-btn-secondary ov-btn-icon text-primary" title="Email" onClick={onOpenEmailModal}>
                <i className="fas fa-envelope" aria-hidden="true" />
              </button>
            ) : null}
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
              {flags.can_send_to_supplier ? (
                <button type="button" className="ov-mobile-item ov-mobile-item--confirm" onClick={onSendToSupplier}>
                  Send to Supplier
                </button>
              ) : null}
              {flags.can_approve ? (
                <button type="button" className="ov-mobile-item ov-mobile-item--confirm" onClick={onApprove}>
                  Approve PO
                </button>
              ) : null}
              {flags.can_edit && urls.edit ? (
                <a className="ov-mobile-item" href={urls.edit}>Edit PO</a>
              ) : null}
              <button type="button" className="ov-mobile-item" disabled={pdfDownloading} onClick={onDownloadPdf}>
                Download PDF
              </button>
              {share.whatsapp_url ? (
                <a className="ov-mobile-item text-success" target="_blank" rel="noreferrer" href={share.whatsapp_url}>WhatsApp</a>
              ) : null}
              {po?.supplier_email ? (
                <button type="button" className="ov-mobile-item text-primary" onClick={onOpenEmailModal}>Email</button>
              ) : null}
              <button type="button" className="ov-mobile-item" onClick={onOpenPoInfoModal}>PO Info</button>
            </div>
          </details>
        </div>
      </div>
    </header>
  );
}
