import { useEffect, useMemo, useState } from 'react';
import { Share2 } from 'lucide-react';
import ShareLetterModal from '../components/ShareLetterModal.jsx';
import {
  composeHref,
  formatListDate,
  listInboxLetters,
  notifyLetterSentAndGoToList,
  readLetterCfg,
} from '../utils/letterStore.js';

const EMPTY_LOTTIE_FALLBACK = '/assets/animations/nothing.lottie';

function ensureDotLottiePlayer() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (customElements.get('dotlottie-wc')) return;
  if (document.getElementById('letter-dotlottie-wc')) return;
  const script = document.createElement('script');
  script.id = 'letter-dotlottie-wc';
  script.type = 'module';
  script.src = 'https://unpkg.com/@lottiefiles/dotlottie-wc@0.8.5/dist/dotlottie-wc.js';
  document.head.appendChild(script);
}

function recipientLabel(form = {}) {
  return String(form.recipientName || form.recipientCompany || '').trim() || '-';
}

function authorLabel(row, cfg) {
  return String(
    row.authorName
    || row.form?.signName
    || cfg.user?.name
    || ''
  ).trim() || '-';
}

export default function LetterInboxPage() {
  const cfg = useMemo(() => readLetterCfg(), []);
  const accent = cfg.branding?.accentColor || '#FBC51C';
  const [letters, setLetters] = useState(() => listInboxLetters(cfg));
  const [shareRow, setShareRow] = useState(null);
  const emptyAnimSrc = String(cfg.emptyAnimationUrl || EMPTY_LOTTIE_FALLBACK).trim() || EMPTY_LOTTIE_FALLBACK;

  useEffect(() => {
    ensureDotLottiePlayer();
  }, []);

  useEffect(() => {
    if (typeof window.updateLetterInboxNavDot === 'function') {
      window.updateLetterInboxNavDot();
    }
  }, [letters]);

  const handleOpen = (id) => {
    window.location.href = composeHref(cfg, id);
  };

  const handleShare = (event, row) => {
    event.stopPropagation();
    setShareRow(row);
  };

  return (
    <div className="letter-list-page" style={{ '--lh-accent': accent }}>
      <div className="letter-list-head">
        <div>
          <p>Letters shared with you appear here.</p>
        </div>
      </div>

      {letters.length === 0 ? (
        <div className="letter-list-empty letter-card">
          <div className="letter-empty-lottie" aria-hidden="true">
            <dotlottie-wc
              src={emptyAnimSrc}
              autoplay
              loop
              speed="1"
              style={{ width: '220px', height: '220px' }}
            />
          </div>
          <h2>Inbox is empty</h2>
          <p>When someone shares a letter with you, it will appear here.</p>
        </div>
      ) : (
        <div className="letter-table-wrap letter-card">
          <table className="letter-table">
            <thead>
              <tr>
                <th className="letter-col-sn">S/N</th>
                <th>Name</th>
                <th>To</th>
                <th>Date</th>
                <th className="letter-col-share" aria-label="Share" />
              </tr>
            </thead>
            <tbody>
              {letters.map((row, index) => (
                <tr key={row.id} onClick={() => handleOpen(row.id)}>
                  <td className="letter-col-sn">{index + 1}</td>
                  <td className="letter-col-name">{authorLabel(row, cfg)}</td>
                  <td>{recipientLabel(row.form)}</td>
                  <td>{formatListDate(row.updatedAt || row.createdAt)}</td>
                  <td className="letter-col-share">
                    <button
                      type="button"
                      className="letter-share-btn"
                      title="Share letter"
                      aria-label="Share letter"
                      onClick={(event) => handleShare(event, row)}
                    >
                      <Share2 size={16} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {shareRow ? (
        <ShareLetterModal
          letter={shareRow}
          onClose={() => setShareRow(null)}
          onShared={(result) => {
            if (result?.type === 'employees') {
              notifyLetterSentAndGoToList(cfg, result.count);
              return;
            }
            setLetters(listInboxLetters(cfg));
          }}
        />
      ) : null}
    </div>
  );
}
