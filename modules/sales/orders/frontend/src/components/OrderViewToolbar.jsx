export default function OrderViewToolbar({
  displayNumber,
  pipeline,
  urls,
  flags,
  share,
  order,
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
  onEmail,
  onInvoiceNavigate,
  onRegisterPayment,
}) {
  const canConvertInvoice = Boolean(flags.can_create_invoice);
  const convertHref = urls.create_invoice || '';
  const canRegisterPayment = Boolean(flags.can_register_payment && (onRegisterPayment || urls.register_payment));

  const handleInvoiceClick = (event) => {
    if (!convertHref || !canConvertInvoice) return;
    if (onInvoiceNavigate) {
      event.preventDefault();
      onCloseDesktopActions?.();
      onInvoiceNavigate(convertHref);
    }
  };

  return (
    <header className="ov-chrome ov-no-print">
      <div className="ov-control-panel">
        <nav className="ov-breadcrumb" aria-label="Breadcrumb">
          {urls.return ? (
            <>
              <a href={urls.return}>
                <i className="fas fa-arrow-left me-1" aria-hidden="true" />
                Back
              </a>
              <span className="ov-breadcrumb-sep">/</span>
            </>
          ) : null}
          {urls.orders_index ? (
            <>
              <a href={urls.orders_index}>Orders</a>
              <span className="ov-breadcrumb-sep">/</span>
            </>
          ) : null}
          <span className="active">{displayNumber}</span>
        </nav>

        <div className="ov-pipeline" aria-label="Order status">
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
            {flags.can_mark_sent ? (
              <button
                type="button"
                className="ov-btn ov-btn-primary"
                disabled={Boolean(busyAction)}
                onClick={() => onRunStatusAction('sent')}
              >
                Mark as Sent
              </button>
            ) : null}

            {flags.can_confirm ? (
              <button
                type="button"
                className="ov-btn ov-btn-primary"
                disabled={Boolean(busyAction)}
                onClick={() => onRunStatusAction('confirm')}
              >
                Confirm Order
              </button>
            ) : null}

            {(flags.can_mark_sent || flags.can_confirm) ? (
              <span className="ov-btn-divider" aria-hidden="true" />
            ) : null}

            <div className="ov-actions-cluster">
              <details
                className="ov-actions-dropdown"
                ref={desktopDropdownRef}
                open={desktopActionsOpen}
                onToggle={(event) => onDesktopActionsToggle(event.currentTarget.open)}
                data-ov-tour="actions-desktop-wrap"
              >
                <summary className="ov-actions-summary" data-ov-tour="actions-desktop">
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
                  {urls.edit ? (
                    <a className="ov-actions-item ov-actions-item--edit" href={urls.edit} onClick={onCloseDesktopActions}>
                      <i className="fas fa-edit" aria-hidden="true" />
                      Edit Order
                    </a>
                  ) : null}
                  {urls.products ? (
                    <a className="ov-actions-item ov-actions-item--products" href={urls.products} onClick={onCloseDesktopActions}>
                      <i className="fas fa-images" aria-hidden="true" />
                      View Products
                    </a>
                  ) : null}
                  {canConvertInvoice && convertHref ? (
                    <a className="ov-actions-item ov-actions-item--pay" href={convertHref} onClick={handleInvoiceClick}>
                      <i className="fas fa-file-invoice-dollar" aria-hidden="true" />
                      Invoice
                    </a>
                  ) : null}
                  {flags.can_cancel ? (
                    <button
                      type="button"
                      className="ov-actions-item ov-actions-item--danger"
                      onClick={() => onRunStatusAction('cancel', {
                        title: 'Cancel Order?',
                        text: 'Are you sure you want to cancel this order?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#3085d6',
                        confirmButtonText: 'Yes, Cancel it!',
                      })}
                    >
                      <i className="fas fa-times-circle" aria-hidden="true" />
                      Cancel Order
                    </button>
                  ) : null}
                  <hr className="ov-actions-divider" />
                  <button
                    type="button"
                    className="ov-actions-item ov-actions-item--print"
                    data-ov-tour="print-desktop"
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
                  {order?.email && urls.send_email ? (
                    <a
                      className="ov-actions-item ov-actions-item--email"
                      href={urls.send_email}
                      onClick={(event) => {
                        onCloseDesktopActions();
                        onEmail(event, urls.send_email, order.email);
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
            <summary data-ov-tour="actions-mobile">
              <i className="fas fa-bars" aria-hidden="true" />
              {' '}
              Actions
            </summary>
            <div className="ov-mobile-panel">
              {flags.can_mark_sent ? (
                <button type="button" className="ov-mobile-item ov-mobile-item--sent" onClick={() => onRunStatusAction('sent')}>
                  <i className="fas fa-paper-plane" aria-hidden="true" />
                  Mark as Sent
                </button>
              ) : null}
              {flags.can_confirm ? (
                <button type="button" className="ov-mobile-item ov-mobile-item--confirm" onClick={() => onRunStatusAction('confirm')}>
                  <i className="fas fa-check-circle" aria-hidden="true" />
                  Confirm Order
                </button>
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
              {canConvertInvoice && convertHref ? (
                <a className="ov-mobile-item ov-mobile-item--confirm" href={convertHref} onClick={handleInvoiceClick}>
                  <i className="fas fa-file-invoice-dollar" aria-hidden="true" />
                  Invoice
                </a>
              ) : null}
              {urls.edit ? (
                <a className="ov-mobile-item" href={urls.edit}>
                  <i className="fas fa-edit" aria-hidden="true" />
                  Edit
                </a>
              ) : null}
              <button
                type="button"
                className="ov-mobile-item"
                data-ov-tour="print-mobile"
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
              {urls.products ? (
                <a className="ov-mobile-item" href={urls.products}>
                  <i className="fas fa-images" aria-hidden="true" />
                  View Products
                </a>
              ) : null}
              {share.whatsapp_url ? (
                <a className="ov-mobile-item text-success" target="_blank" rel="noreferrer" href={share.whatsapp_url}>
                  <i className="fab fa-whatsapp" aria-hidden="true" />
                  WhatsApp
                </a>
              ) : null}
              {order?.email && urls.send_email ? (
                <a
                  className="ov-mobile-item text-primary"
                  href={urls.send_email}
                  onClick={(event) => onEmail(event, urls.send_email, order.email)}
                >
                  <i className="fas fa-envelope" aria-hidden="true" />
                  Email
                </a>
              ) : null}
              {flags.can_cancel ? (
                <button
                  type="button"
                  className="ov-mobile-item text-danger"
                  onClick={() => onRunStatusAction('cancel', {
                    title: 'Cancel Order?',
                    text: 'Are you sure you want to cancel this order?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, Cancel it!',
                  })}
                >
                  <i className="fas fa-times-circle" aria-hidden="true" />
                  Cancel Order
                </button>
              ) : null}
            </div>
          </details>
        </div>
      </div>
    </header>
  );
}
