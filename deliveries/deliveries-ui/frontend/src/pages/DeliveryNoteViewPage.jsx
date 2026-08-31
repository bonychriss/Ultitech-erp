import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import '../../../../../modules/sales/orders/frontend/src/order-view.css';
import '../delivery-note-view.css';
import { CFG } from '../config.js';
import { fetchDeliveryNoteViewInit } from '../api/deliveryNoteViewDesk.js';
import {
  downloadDeliveryNotePdf,
  formatPdfError,
} from '../utils/deliveryNotePdf.js';
import DeliveryNoteViewToolbar from '../components/DeliveryNoteViewToolbar.jsx';
import DeliveryNoteDownloadModal from '../components/DeliveryNoteDownloadModal.jsx';
import DeliveryNoteDocumentPane from '../components/DeliveryNoteDocumentPane.jsx';

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

export default function DeliveryNoteViewPage() {
  const deskCfg = useMemo(() => CFG || {}, []);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
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
      const payload = await fetchDeliveryNoteViewInit(params);
      setData(payload);
    } catch (err) {
      setError(err.message || 'Failed to load delivery note.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (!params.get('module') && deskCfg.module) {
      params.set('module', deskCfg.module);
    }
    if (!params.get('id') && deskCfg.note_id) {
      params.set('id', String(deskCfg.note_id));
    }
    loadData(params);
  }, [deskCfg.module, deskCfg.note_id, loadData]);

  useEffect(() => {
    if (!data?.document_font_family) return undefined;

    const links = [];
    if (data.font_stylesheets) {
      const template = document.createElement('template');
      template.innerHTML = data.font_stylesheets.trim();
      template.content.querySelectorAll('link[rel="stylesheet"]').forEach((node) => {
        const href = node.getAttribute('href');
        if (!href) return;
        const existing = document.querySelector(`link[data-dnv-doc-font="${href}"]`);
        if (existing) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.dataset.dnvDocFont = href;
        document.head.appendChild(link);
        links.push(link);
      });
    }

    return () => {
      links.forEach((node) => node.remove());
    };
  }, [data?.document_font_family, data?.font_stylesheets]);

  useEffect(() => {
    const number = data?.display_note_number || data?.note?.note_number;
    if (!number) return;
    document.title = `Delivery Note ${number} - ERP`;
  }, [data?.display_note_number, data?.note?.note_number]);

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

  const notifyDeliveryDocPdf = (ok, errorMessage = '') => {
    const payload = { type: 'delivery-doc-pdf', ok: !!ok, error: errorMessage || '' };
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage(payload, window.location.origin);
      }
    } catch (_) {
      /* ignore */
    }
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage(payload, window.location.origin);
      }
    } catch (_) {
      /* ignore */
    }
  };

  const note = data?.note;
  const urls = data?.urls || {};
  const flags = data?.flags || {};
  const displayNumber = note?.note_number || data?.display_note_number || '';
  const documentHtml = data?.document_html || '';
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

    const fileName = `DeliveryNote_${displayNumber || 'document'}.pdf`;
    setPdfFileName(fileName);
    setPdfState('loading');
    setPdfProgress(4);
    setPdfMessage('Preparing document...');

    try {
      await downloadDeliveryNotePdf(displayNumber, (percent, message) => {
        setPdfProgress(percent);
        if (message) setPdfMessage(message);
      });
      setPdfState('success');
      setPdfProgress(100);
      setPdfMessage('Your PDF has been saved to your downloads folder.');
      notifyDeliveryDocPdf(true, '');
    } catch (err) {
      console.error('Delivery note PDF download failed:', err);
      const reason = formatPdfError(err);
      setPdfState('error');
      setPdfMessage(reason);
      notifyDeliveryDocPdf(false, reason);
      showToast('error', reason, 5000);
    }
  };

  useEffect(() => {
    if (loading || !data?.flags?.can_download) return undefined;

    const params = new URLSearchParams(window.location.search);
    if (!params.has('download')) return undefined;

    const timer = window.setTimeout(() => {
      handleDownloadPdf();
    }, 1500);

    return () => window.clearTimeout(timer);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loading, data?.flags?.can_download]);

  const closeDesktopActions = () => {
    if (desktopDropdownRef.current) desktopDropdownRef.current.open = false;
    setDesktopActionsOpen(false);
  };

  if (loading && !data) {
    return (
      <div className="ov-boot-loading" role="status">
        <span className="ov-boot-spinner" aria-hidden="true" />
        <span>Loading delivery note...</span>
      </div>
    );
  }

  if (error && !note) {
    return (
      <div className="ov-page-root">
        <div className="ov-flash ov-flash-error" role="alert">{error}</div>
        {urls.delivery_notes_index ? (
          <div style={{ padding: '1rem 1.25rem' }}>
            <a href={urls.delivery_notes_index} className="ov-btn ov-btn-secondary">Back to delivery notes</a>
          </div>
        ) : null}
      </div>
    );
  }

  const pdfModalOpen = pdfState !== 'idle';

  return (
    <div className="ov-page-root">
      {error ? <div className="ov-flash ov-flash-error ov-no-print" role="alert">{error}</div> : null}

      <DeliveryNoteDownloadModal
        open={pdfModalOpen}
        state={pdfState}
        progress={pdfProgress}
        message={pdfMessage}
        fileName={pdfFileName}
        onClose={closeDownloadModal}
      />

      <DeliveryNoteViewToolbar
        displayNumber={displayNumber}
        pipeline={data?.pipeline}
        urls={urls}
        flags={flags}
        pdfDownloading={pdfState === 'loading'}
        desktopActionsOpen={desktopActionsOpen}
        mobileActionsOpen={mobileActionsOpen}
        desktopDropdownRef={desktopDropdownRef}
        onDesktopActionsToggle={setDesktopActionsOpen}
        onMobileActionsToggle={setMobileActionsOpen}
        onCloseDesktopActions={closeDesktopActions}
        onDownloadPdf={handleDownloadPdf}
      />

      <DeliveryNoteDocumentPane
        html={documentHtml}
        fontFamily={documentFontFamily}
      />
    </div>
  );
}
