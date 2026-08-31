import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  fetchPoViewInit,
  fetchPoReceiptInfo,
  getPoDeskCfg,
  submitPoActionForm,
  submitPoEmailForm,
} from '../api/poViewDesk.js';
import {
  buildOrderPdfDataUri,
  downloadOrderPdf,
  formatPdfError,
} from '../utils/orderPdf.js';
import PoViewToolbar from '../components/PoViewToolbar.jsx';
import PoInfoModal from '../components/PoInfoModal.jsx';
import OrderDownloadModal from '../components/OrderDownloadModal.jsx';
import OrderDocumentPane from '../components/OrderDocumentPane.jsx';

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

export default function PoViewPage() {
  const deskCfg = useMemo(() => getPoDeskCfg(), []);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [desktopActionsOpen, setDesktopActionsOpen] = useState(false);
  const [mobileActionsOpen, setMobileActionsOpen] = useState(false);
  const [pdfState, setPdfState] = useState('idle');
  const [pdfProgress, setPdfProgress] = useState(0);
  const [pdfMessage, setPdfMessage] = useState('Preparing document...');
  const [pdfFileName, setPdfFileName] = useState('');
  const [emailOpen, setEmailOpen] = useState(false);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [emailRecipient, setEmailRecipient] = useState('');
  const [emailSending, setEmailSending] = useState(false);
  const [poInfoOpen, setPoInfoOpen] = useState(false);
  const [poInfoLoading, setPoInfoLoading] = useState(false);
  const [poInfoError, setPoInfoError] = useState('');
  const [poInfoData, setPoInfoData] = useState(null);
  const pdfCloseTimer = useRef(null);
  const desktopDropdownRef = useRef(null);
  const uploadFormRef = useRef(null);

  const loadData = useCallback(async (params) => {
    setLoading(true);
    setError('');
    try {
      const payload = await fetchPoViewInit(params);
      setData(payload);
      setEmailRecipient(payload?.email?.default_recipient || payload?.po?.supplier_email || '');
    } catch (err) {
      setError(err.message || 'Failed to load purchase order.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (!params.get('id') && deskCfg.po_id) {
      params.set('id', String(deskCfg.po_id));
    }
    loadData(params);
  }, [deskCfg.po_id, loadData]);

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
    const number = data?.display_po_number || data?.po?.purchase_no;
    if (!number) return;
    document.title = `Purchase Order ${number} - ERP`;
  }, [data?.display_po_number, data?.po?.purchase_no]);

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

  const po = data?.po;
  const urls = data?.urls || {};
  const flags = data?.flags || {};
  const share = data?.share || {};
  const displayNumber = data?.display_po_number || po?.purchase_no || '';
  const documentHtml = data?.document_html || '';
  const alertsHtml = data?.alerts_html || '';
  const documentFontFamily = data?.document_font_family || '';

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
    const fileName = `Purchase_Order_${displayNumber || 'document'}.pdf`;
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
      const reason = formatPdfError(err);
      setPdfState('error');
      setPdfMessage(reason);
      showToast('error', reason, 5000);
    }
  };

  const handleApprove = async () => {
    const result = await confirmDialog({
      title: 'Approve Purchase Order?',
      text: 'This will lock the order and allow shipment creation.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#198754',
      cancelButtonColor: '#d33',
      confirmButtonText: 'Yes, Approve it!',
    });
    if (result.isConfirmed && urls.approve) {
      window.location.href = urls.approve;
    }
  };

  const handleSendToSupplier = async () => {
    const result = await confirmDialog({
      title: 'Send to supplier?',
      text: 'Email the supplier the quote request with portal link?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Send',
    });
    if (result.isConfirmed && po?.id) {
      submitPoActionForm(po.id, 'send_to_supplier');
    }
  };

  const handleCopyPortalLink = async () => {
    const portalUrl = share.portal_url || '';
    if (!portalUrl) return;
    try {
      await navigator.clipboard.writeText(portalUrl);
      showToast('success', 'Portal link copied');
    } catch {
      showToast('error', 'Could not copy link');
    }
  };

  const loadPoInfo = useCallback(async (poId) => {
    if (!poId) return;
    setPoInfoLoading(true);
    setPoInfoError('');
    try {
      const payload = await fetchPoReceiptInfo(poId, true);
      setPoInfoData(payload);
    } catch (err) {
      setPoInfoError(err.message || 'Failed to load PO info.');
      setPoInfoData(null);
    } finally {
      setPoInfoLoading(false);
    }
  }, []);

  const handleOpenPoInfo = () => {
    setPoInfoOpen(true);
    if (po?.id) {
      loadPoInfo(po.id);
    }
  };

  const handleRefreshPoInfo = () => {
    if (po?.id) {
      loadPoInfo(po.id);
    }
  };

  const handleSendEmail = async () => {
    if (!po?.id || !emailRecipient.trim()) return;
    setEmailSending(true);

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
      const emailBody = `Dear Supplier,

Thank you for your continued partnership. We are pleased to share our Purchase Order for your review and quotation.

Please find attached Purchase Order ${displayNumber}.

To submit your quote and invoice, kindly access our Supplier Portal using the link below:
${share.portal_url || ''}

Kind regards,
Procurement Team`;

      submitPoEmailForm({
        po_id: po.id,
        action: 'send_email',
        recipient_email: emailRecipient.trim(),
        subject: data?.email?.subject || `Purchase Order ${displayNumber}`,
        message: emailBody,
        pdf_base64: pdfData,
      });
    } catch (err) {
      setEmailSending(false);
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

  if (loading && !data) {
    return (
      <div className="ov-boot-loading" role="status">
        <span className="ov-boot-spinner" aria-hidden="true" />
        <span>Loading purchase order...</span>
      </div>
    );
  }

  if (error && !po) {
    return (
      <div className="ov-page-root">
        <div className="ov-flash ov-flash-error" role="alert">{error}</div>
        {urls.index ? (
          <div style={{ padding: '1rem 1.25rem' }}>
            <a href={urls.index} className="ov-btn ov-btn-secondary">Back to purchases</a>
          </div>
        ) : null}
      </div>
    );
  }

  const pdfModalOpen = pdfState !== 'idle';

  return (
    <div className="ov-page-root">
      {error ? <div className="ov-flash ov-flash-error ov-no-print" role="alert">{error}</div> : null}

      <OrderDownloadModal
        open={pdfModalOpen}
        state={pdfState}
        progress={pdfProgress}
        message={pdfMessage}
        fileName={pdfFileName}
        onClose={closeDownloadModal}
      />

      <PoViewToolbar
        displayNumber={displayNumber}
        pipeline={data?.pipeline}
        urls={urls}
        flags={flags}
        share={share}
        po={po}
        emailMeta={data?.email}
        pdfDownloading={pdfState === 'loading'}
        desktopActionsOpen={desktopActionsOpen}
        mobileActionsOpen={mobileActionsOpen}
        desktopDropdownRef={desktopDropdownRef}
        onDesktopActionsToggle={setDesktopActionsOpen}
        onMobileActionsToggle={setMobileActionsOpen}
        onCloseDesktopActions={closeDesktopActions}
        onApprove={handleApprove}
        onDownloadPdf={handleDownloadPdf}
        onCopyPortalLink={handleCopyPortalLink}
        onSendToSupplier={handleSendToSupplier}
        onOpenEmailModal={() => setEmailOpen(true)}
        onOpenUploadModal={() => setUploadOpen(true)}
        onOpenPoInfoModal={handleOpenPoInfo}
      />

      <PoInfoModal
        open={poInfoOpen}
        loading={poInfoLoading}
        error={poInfoError}
        data={poInfoData}
        onClose={() => setPoInfoOpen(false)}
        onRefresh={handleRefreshPoInfo}
      />

      {alertsHtml ? (
        <div className="ov-alerts-wrap ov-no-print" dangerouslySetInnerHTML={{ __html: alertsHtml }} />
      ) : null}

      <OrderDocumentPane html={documentHtml} fontFamily={documentFontFamily} />

      {emailOpen ? (
        <div className="ov-modal-backdrop ov-no-print" role="dialog" aria-modal="true">
          <div className="ov-modal-card">
            <div className="ov-modal-header">
              <h5>Send Purchase Order</h5>
              <button type="button" className="ov-modal-close" onClick={() => setEmailOpen(false)} aria-label="Close">&times;</button>
            </div>
            <div className="ov-modal-body">
              <label className="ov-form-label">Recipient Email</label>
              <input
                type="email"
                className="ov-form-input"
                value={emailRecipient}
                onChange={(event) => setEmailRecipient(event.target.value)}
                placeholder="Enter email address"
              />
            </div>
            <div className="ov-modal-footer">
              <button type="button" className="ov-btn ov-btn-secondary" onClick={() => setEmailOpen(false)}>Cancel</button>
              <button type="button" className="ov-btn ov-btn-primary" disabled={emailSending} onClick={handleSendEmail}>
                {emailSending ? 'Sending�' : 'Send'}
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {uploadOpen ? (
        <div className="ov-modal-backdrop ov-no-print" role="dialog" aria-modal="true">
          <div className="ov-modal-card">
            <form
              ref={uploadFormRef}
              method="POST"
              encType="multipart/form-data"
              action={`view_po.php?id=${encodeURIComponent(String(po?.id || ''))}`}
            >
              <input type="hidden" name="action" value="upload_invoice" />
              <div className="ov-modal-header">
                <h5>Attach Supplier Invoice</h5>
                <button type="button" className="ov-modal-close" onClick={() => setUploadOpen(false)} aria-label="Close">&times;</button>
              </div>
              <div className="ov-modal-body">
                <input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" required className="ov-form-input" />
              </div>
              <div className="ov-modal-footer">
                <button type="button" className="ov-btn ov-btn-secondary" onClick={() => setUploadOpen(false)}>Cancel</button>
                <button type="submit" className="ov-btn ov-btn-primary">Upload</button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </div>
  );
}
