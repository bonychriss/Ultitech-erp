export default function InvoiceViewToolbar({
  displayNumber,
  pipeline,
  urls,
  flags,
  share,
  invoice,
  busyAction,
  pdfDownloading,
  desktopActionsOpen,
  mobileActionsOpen,
  desktopDropdownRef,
  onDesktopActionsToggle,
  onMobileActionsToggle,
  onCloseDesktopActions,
  onRunStatusAction,
  onPrint,
  onDownloadPdf,
  onOpenDeliveryNote,
  onEmail,
  onRegisterPayment,
}) {
  const showPrimaryDivider = flags.can_edit || flags.can_ship;
  const canRegisterPayment = Boolean(flags.can_register_payment && (onRegisterPayment || urls.register_payment));

  return (
    <header className="ov-chrome ov-no-print">
      <div className="ov-control-panel">
        <nav className="ov-breadcrumb" aria-label="Breadcrumb">
          {urls.return ? (
            <>
              <a
                href={urls.return}
                className="erp-nav-back-link"
                data-erp-nav-back={urls.return}
                title="Back to where you were"
                onClick={(event) => {
                  if (typeof window !== 'undefined' && window.erpNavBack && typeof window.erpNavBack.go === 'function') {
                    event.preventDefault();
                    // preferFallback: keep date/filter query from return= instead of a stale stack URL
                    if (!window.erpNavBack.go(urls.return, { preferFallback: true })) {
                      window.location.href = urls.return;
                    }
                  }
                }}
              >
                <i className="fas fa-arrow-left me-1" aria-hidden="true" />
                Back
              </a>
              <span className="ov-breadcrumb-sep">/</span>
            </>
          ) : null}
          {urls.invoices_index ? (
            <>
              <a href={urls.invoices_index}>Invoices</a>
              <span className="ov-breadcrumb-sep">/</span>
            </>
          ) : null}
          <span className="active">{displayNumber}</span>
        </nav>

        <div className="ov-pipeline" aria-label="Invoice status">
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
            {flags.can_edit && urls.edit ? (
              <a href={urls.edit} className="ov-btn ov-btn-primary">
                Edit Invoice
              </a>
            ) : null}

            {flags.can_ship ? (
              <button
                type="button"
                className="ov-btn ov-btn-primary"
                disabled={Boolean(busyAction)}
                onClick={() => onRunStatusAction('ship', {
                  title: 'Mark as Shipped?',
                  text: 'This will deduct stock and mark the linked order as shipped.',
                  icon: 'question',
                  showCancelButton: true,
                  confirmButtonColor: '#008784',
                  cancelButtonColor: '#d33',
                  confirmButtonText: 'Yes, ship it!',
                })}
              >
                <i className="fas fa-truck" aria-hidden="true" />
                {' '}
                Mark Shipped
              </button>
            ) : null}

            {showPrimaryDivider ? <span className="ov-btn-divider" aria-hidden="true" /> : null}

            <div className="ov-actions-cluster">
              <details
                className="ov-actions-dropdown"
                ref={desktopDropdownRef}
                open={desktopActionsOpen}
                onToggle={(event) => onDesktopActionsToggle(event.currentTarget.open)}
                data-inv-tour="actions-desktop-wrap"
              >
                <summary className="ov-actions-summary" data-inv-tour="actions-desktop">
                  <i className="fas fa-bars" aria-hidden="true" />
                  Actions
                  <i className="fas fa-caret-down" aria-hidden="true" />
                </summary>
                <div className="ov-actions-panel">
                  {canRegisterPayment ? (
                    onRegisterPayment ? (
                      <button
                        type="button"
                        className="ov-actions-item ov-actions-item--pay"
                        onClick={() => { onCloseDesktopActions(); onRegisterPayment(); }}
                      >
                        <i className="fas fa-money-bill-wave" aria-hidden="true" />
                        Pay invoice
                      </button>
                    ) : (
                      <a className="ov-actions-item ov-actions-item--pay" href={urls.register_payment} onClick={onCloseDesktopActions}>
                        <i className="fas fa-money-bill-wave" aria-hidden="true" />
                        Pay invoice
                      </a>
                    )
                  ) : null}
                  {flags.has_order && urls.order_view ? (
                    <a className="ov-actions-item ov-actions-item--order" href={urls.order_view} onClick={onCloseDesktopActions}>
                      <i className="fas fa-file-alt" aria-hidden="true" />
                      View Order
                    </a>
                  ) : null}
                  {flags.has_products && urls.products ? (
                    <a className="ov-actions-item ov-actions-item--products" href={urls.products} onClick={onCloseDesktopActions}>
                      <i className="fas fa-images" aria-hidden="true" />
                      View Products
                    </a>
                  ) : null}
                  {flags.has_order && urls.delivery_note ? (
                    <button
                      type="button"
                      className="ov-actions-item ov-actions-item--delivery"
                      disabled={pdfDownloading || Boolean(busyAction)}
                      onClick={() => { onCloseDesktopActions(); onOpenDeliveryNote?.(); }}
                    >
                      <i className="fas fa-truck" aria-hidden="true" />
                      Delivery Note
                    </button>
                  ) : null}
                  <hr className="ov-actions-divider" />
                  <button
                    type="button"
                    className="ov-actions-item ov-actions-item--print"
                    data-inv-tour="print-desktop"
                    onClick={() => {
                      onCloseDesktopActions();
                      if (typeof onPrint === 'function') onPrint();
                      else window.print();
                    }}
                  >
                    <i className="fas fa-print" aria-hidden="true" />
                    Print
                  </button>
                  <button
                    type="button"
                    className="ov-actions-item ov-actions-item--pdf"
                    disabled={pdfDownloading}
                    onClick={() => { onCloseDesktopActions(); onDownloadPdf(); }}
                  >
                    <i className={`fas ${pdfDownloading ? 'fa-spinner fa-spin' : 'fa-download'}`} aria-hidden="true" />
                    Download PDF
                  </button>
                  {share.whatsapp_url ? (
                    <a
                      className="ov-actions-item ov-actions-item--whatsapp"
                      href={share.whatsapp_url}
                      target="_blank"
                      rel="noreferrer"
                      onClick={onCloseDesktopActions}
                    >
                      <i className="fab fa-whatsapp" aria-hidden="true" />
                      WhatsApp
                    </a>
                  ) : null}
                  {invoice?.email && urls.send_email ? (
                    <a
                      className="ov-actions-item ov-actions-item--email"
                      href={urls.send_email}
                      onClick={(event) => {
                        onCloseDesktopActions();
                        onEmail(event, urls.send_email, invoice.email);
                      }}
                    >
                      <i className="fas fa-envelope" aria-hidden="true" />
                      Email
                    </a>
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
            <summary data-inv-tour="actions-mobile">
              <i className="fas fa-bars" aria-hidden="true" />
              {' '}
              Actions
            </summary>
            <div className="ov-mobile-panel">
              {flags.can_edit && urls.edit ? (
                <a className="ov-mobile-item ov-mobile-item--confirm" href={urls.edit}>
                  <i className="fas fa-edit" aria-hidden="true" />
                  Edit Invoice
                </a>
              ) : null}
              {canRegisterPayment ? (
                onRegisterPayment ? (
                  <button
                    type="button"
                    className="ov-mobile-item ov-mobile-item--confirm"
                    onClick={() => { onMobileActionsToggle(false); onRegisterPayment(); }}
                  >
                    <i className="fas fa-money-bill-wave" aria-hidden="true" />
                    Pay invoice
                  </button>
                ) : (
                  <a className="ov-mobile-item ov-mobile-item--confirm" href={urls.register_payment}>
                    <i className="fas fa-money-bill-wave" aria-hidden="true" />
                    Pay invoice
                  </a>
                )
              ) : null}
              {flags.can_ship ? (
                <button
                  type="button"
                  className="ov-mobile-item ov-mobile-item--confirm"
                  disabled={Boolean(busyAction)}
                  onClick={() => onRunStatusAction('ship', {
                    title: 'Mark as Shipped?',
                    text: 'This will deduct stock and mark the linked order as shipped.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#008784',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes, ship it!',
                  })}
                >
                  <i className="fas fa-truck" aria-hidden="true" />
                  Mark Shipped
                </button>
              ) : null}
              {flags.has_order && urls.order_view ? (
                <a className="ov-mobile-item" href={urls.order_view}>
                  <i className="fas fa-file-alt" aria-hidden="true" />
                  View Order
                </a>
              ) : null}
              {flags.has_products && urls.products ? (
                <a className="ov-mobile-item" href={urls.products}>
                  <i className="fas fa-images" aria-hidden="true" />
                  View Products
                </a>
              ) : null}
              {flags.has_order && urls.delivery_note ? (
                <button
                  type="button"
                  className="ov-mobile-item"
                  disabled={pdfDownloading || Boolean(busyAction)}
                  onClick={() => {
                    onMobileActionsToggle(false);
                    onOpenDeliveryNote?.();
                  }}
                >
                  <i className="fas fa-truck" aria-hidden="true" />
                  Delivery Note
                </button>
              ) : null}
              <button
                type="button"
                className="ov-mobile-item"
                data-inv-tour="print-mobile"
                onClick={() => {
                  onMobileActionsToggle(false);
                  if (typeof onPrint === 'function') onPrint();
                  else window.print();
                }}
              >
                <i className="fas fa-print" aria-hidden="true" />
                Print
              </button>
              <button type="button" className="ov-mobile-item" disabled={pdfDownloading} onClick={onDownloadPdf}>
                <i className={`fas ${pdfDownloading ? 'fa-spinner fa-spin' : 'fa-download'}`} aria-hidden="true" />
                Download PDF
              </button>
              {share.whatsapp_url ? (
                <a className="ov-mobile-item text-success" target="_blank" rel="noreferrer" href={share.whatsapp_url}>
                  <i className="fab fa-whatsapp" aria-hidden="true" />
                  WhatsApp
                </a>
              ) : null}
              {invoice?.email && urls.send_email ? (
                <a
                  className="ov-mobile-item text-primary"
                  href={urls.send_email}
                  onClick={(event) => onEmail(event, urls.send_email, invoice.email)}
                >
                  <i className="fas fa-envelope" aria-hidden="true" />
                  Email
                </a>
              ) : null}
            </div>
          </details>
        </div>
      </div>
    </header>
  );
}
