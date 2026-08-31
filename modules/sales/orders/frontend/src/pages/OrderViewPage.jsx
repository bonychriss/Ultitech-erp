import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  fetchOrderViewInit,
  getConfig,
  postOrderStatusAction,
  submitEmailWithPdf,
} from '../api/orderViewDesk.js';
import {
  buildOrderPdfDataUri,
  downloadOrderPdf,
  formatPdfError,
} from '../utils/orderPdf.js';
import OrderViewToolbar from '../components/OrderViewToolbar.jsx';
import OrderDownloadModal from '../components/OrderDownloadModal.jsx';
import OrderDocumentPane from '../components/OrderDocumentPane.jsx';
import {
  resolveInvoiceSourceLabel,
  runInvoiceTransformThenNavigate,
} from '../utils/invoiceTransform.js';

function getDeskCfg() {
  if (typeof window === 'undefined') return {};
  return window.__SALES_ORDERS_CFG__ || {};
}

function showToast(icon, title, timer = 3000) {
  if (typeof window.Swal === 'undefined') {
    window.alert(title);
    return;
  }
  window.Swal.fire({
    toast: true,
    position: 'top-end',
    icon,
    title,
    showConfirmButton: false,
    timer,
    timerProgressBar: true,
  });
}

function confirmDialog(options) {
  if (typeof window.Swal === 'undefined') {
    return Promise.resolve({ isConfirmed: window.confirm(options.text || options.title) });
  }
  return window.Swal.fire(options);
}

export default function OrderViewPage() {
  const deskCfg = useMemo(() => getDeskCfg(), []);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busyAction, setBusyAction] = useState('');
  const [desktopActionsOpen, setDesktopActionsOpen] = useState(false);
  const [mobileActionsOpen, setMobileActionsOpen] = useState(false);
  const [pdfState, setPdfState] = useState('idle');
  const [pdfProgress, setPdfProgress] = useState(0);
  const [pdfMessage, setPdfMessage] = useState('Preparing document...');
  const [pdfFileName, setPdfFileName] = useState('');
  const pdfCloseTimer = useRef(null);
  const desktopDropdownRef = useRef(null);

  const loadData = useCallback(async (params) => {
    setLoading(true);
    setError('');
    try {
      const payload = await fetchOrderViewInit(params);
      setData(payload);
    } catch (err) {
      setError(err.message || 'Failed to load order.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (!params.get('module') && deskCfg.module) {
      params.set('module', deskCfg.module);
    }
    if (!params.get('id') && deskCfg.order_id) {
      params.set('id', String(deskCfg.order_id));
    }
    loadData(params);
  }, [deskCfg.module, deskCfg.order_id, loadData]);

  useEffect(() => {
    if (!data?.document_font_family) return undefined;

    const links = [];
    if (data.font_stylesheets) {
      const template = document.createElement('template');
      template.innerHTML = data.font_stylesheets.trim();
      template.content.querySelectorAll('link[rel="stylesheet"]').forEach((node) => {
        const href = node.getAttribute('href');
        if (!href) return;
        const existing = document.querySelector(`link[data-ov-doc-font="${href}"]`);
        if (existing) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.dataset.ovDocFont = href;
        document.head.appendChild(link);
        links.push(link);
      });
    }

    return () => {
      links.forEach((node) => node.remove());
    };
  }, [data?.document_font_family, data?.font_stylesheets]);

  useEffect(() => {
    const number = data?.display_document_number
      || data?.display_order_number
      || data?.order?.display_document_number
      || data?.order?.display_order_number
      || data?.order?.order_number;
    if (!number) return;
    const label = data?.document_label
      || (['draft', 'quotation', 'sent'].includes(String(data?.order?.status || '').toLowerCase())
        ? 'Quotation'
        : (['invoiced', 'paid'].includes(String(data?.order?.status || '').toLowerCase()) || data?.flags?.has_linked_invoice
          ? 'Invoice'
          : 'Sales Order'));
    document.title = `${label} ${number} - ERP`;
  }, [
    data?.display_document_number,
    data?.display_order_number,
    data?.order?.display_document_number,
    data?.order?.display_order_number,
    data?.order?.order_number,
    data?.order?.status,
    data?.document_label,
    data?.flags?.has_linked_invoice,
  ]);

  useEffect(() => {
    const onDocClick = (event) => {
      if (!desktopDropdownRef.current?.open) return;
      if (!desktopDropdownRef.current.contains(event.target)) {
        desktopDropdownRef.current.open = false;
        setDesktopActionsOpen(false);
      }
    };
    document.addEventListener('click', onDocClick);
    return () => document.removeEventListener('click', onDocClick);
  }, []);

  useEffect(() => () => {
    if (pdfCloseTimer.current) {
      window.clearTimeout(pdfCloseTimer.current);
    }
  }, []);

  const order = data?.order;
  const urls = data?.urls || {};
  const flags = data?.flags || {};
  const share = data?.share || {};
  const displayNumber = data?.display_document_number
    || order?.display_document_number
    || order?.display_order_number
    || order?.order_number
    || '';
  const isTruck = Boolean(data?.is_truck_order);
  const documentHtml = data?.document_html || '';
  const catalogHtml = data?.catalog_html || '';
  const documentFontFamily = data?.document_font_family || '';

  const runStatusAction = async (action, confirmOptions = null) => {
    if (!order?.id || busyAction) return;

    if (confirmOptions) {
      const result = await confirmDialog(confirmOptions);
      if (!result.isConfirmed) return;
    }

    setBusyAction(action);
    try {
      const result = await postOrderStatusAction(order.id, action, data?.module || deskCfg.module);
      if (result.data) {
        setData(result.data);
      }
      showToast('success', result.message || 'Status updated');
    } catch (err) {
      showToast('error', err.message || 'Status update failed.', 5000);
    } finally {
      setBusyAction('');
      setMobileActionsOpen(false);
      setDesktopActionsOpen(false);
    }
  };

  const closeDownloadModal = (force = false) => {
    if (!force && pdfState === 'loading') return;
    if (pdfCloseTimer.current) {
      window.clearTimeout(pdfCloseTimer.current);
      pdfCloseTimer.current = null;
    }
    setPdfState('idle');
    setPdfProgress(0);
    setPdfMessage('Preparing document...');
    setPdfFileName('');
  };

  const handleDownloadPdf = async () => {
    if (pdfState === 'loading') return;

    const fileName = `Order_${displayNumber || 'document'}.pdf`;
    setPdfFileName(fileName);
    setPdfState('loading');
    setPdfProgress(4);
    setPdfMessage('Preparing document...');

    try {
      await downloadOrderPdf(displayNumber, (percent, message) => {
        setPdfProgress(percent);
        if (message) setPdfMessage(message);
      });
      setPdfState('success');
      setPdfProgress(100);
      setPdfMessage('Your PDF has been saved to your downloads folder.');
    } catch (err) {
      console.error('Order PDF download failed:', err);
      const reason = formatPdfError(err);
      setPdfState('error');
      setPdfMessage(reason);
      showToast('error', reason, 5000);
    }
  };

  const handleEmail = async (event, emailUrl, email) => {
    event.preventDefault();
    const result = await confirmDialog({
      title: 'Send Email?',
      text: `Send this document to ${email} via email?`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#008784',
      cancelButtonColor: '#d33',
      confirmButtonText: 'Yes, send it!',
    });
    if (!result.isConfirmed) return;

    if (typeof window.Swal !== 'undefined') {
      window.Swal.fire({
        title: 'Generating PDF...',
        text: 'Please wait while we prepare the attachment.',
        allowOutsideClick: false,
        didOpen: () => { window.Swal.showLoading(); },
      });
    }

    try {
      const pdfData = await buildOrderPdfDataUri();
      submitEmailWithPdf(emailUrl, pdfData);
    } catch (err) {
      console.error('Order PDF email attachment failed:', err);
      const reason = formatPdfError(err);
      if (typeof window.Swal !== 'undefined') {
        window.Swal.fire('Error', reason, 'error');
      } else {
        window.alert(reason);
      }
    }
  };

  const closeDesktopActions = () => {
    if (desktopDropdownRef.current) desktopDropdownRef.current.open = false;
    setDesktopActionsOpen(false);
  };

  const handleInvoiceNavigate = (href) => {
    if (!flags.can_create_invoice) {
      return;
    }
    runInvoiceTransformThenNavigate(href, {
      sourceLabel: resolveInvoiceSourceLabel(order?.status),
      documentNumber: displayNumber,
      existingInvoice: false,
      orderId: order?.id,
      module: data?.module || deskCfg.module || 'sales',
    });
  };

  if (loading && !data) {
    return (
      <div className="ov-boot-loading" role="status">
        <span className="ov-boot-spinner" aria-hidden="true" />
        <span>Loading order...</span>
      </div>
    );
  }

  if (error && !order) {
    return (
      <div className="ov-page-root">
        <div className="ov-flash ov-flash-error" role="alert">{error}</div>
        {urls.orders_index ? (
          <div style={{ padding: '1rem 1.25rem' }}>
            <a href={urls.orders_index} className="ov-btn ov-btn-secondary">Back to orders</a>
          </div>
        ) : null}
      </div>
    );
  }

  const pdfModalOpen = pdfState !== 'idle';

  return (
    <div className={`ov-page-root${isTruck ? ' ov-page-root--truck' : ''}`}>
      {error ? <div className="ov-flash ov-flash-error ov-no-print" role="alert">{error}</div> : null}

      <OrderDownloadModal
        open={pdfModalOpen}
        state={pdfState}
        progress={pdfProgress}
        message={pdfMessage}
        fileName={pdfFileName}
        onClose={closeDownloadModal}
      />

      <OrderViewToolbar
        displayNumber={displayNumber}
        pipeline={data?.pipeline}
        urls={urls}
        flags={flags}
        share={share}
        order={order}
        busyAction={busyAction}
        pdfDownloading={pdfState === 'loading'}
        desktopActionsOpen={desktopActionsOpen}
        mobileActionsOpen={mobileActionsOpen}
        desktopDropdownRef={desktopDropdownRef}
        onDesktopActionsToggle={setDesktopActionsOpen}
        onMobileActionsToggle={setMobileActionsOpen}
        onCloseDesktopActions={closeDesktopActions}
        onRunStatusAction={runStatusAction}
        onDownloadPdf={handleDownloadPdf}
        onEmail={handleEmail}
        onInvoiceNavigate={handleInvoiceNavigate}
      />

      <OrderDocumentPane
        html={documentHtml}
        fontFamily={documentFontFamily}
      />

      {catalogHtml ? (
        <OrderDocumentPane
          html={catalogHtml}
          fontFamily={documentFontFamily}
          className="ov-catalog-pane"
        />
      ) : null}
    </div>
  );
}
