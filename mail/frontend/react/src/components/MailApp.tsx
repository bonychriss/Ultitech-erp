import { useEffect, useMemo, useRef, useState, type ComponentType } from 'react';
import {
  MdArrowBack,
  MdClose,
  MdDelete,
  MdDrafts,
  MdEdit,
  MdForward,
  MdInbox,
  MdLogout,
  MdMenu,
  MdRefresh,
  MdReply,
  MdReport,
  MdSearch,
  MdSend,
  MdStar,
  MdStarBorder,
} from 'react-icons/md';
import {
  api,
  type Account,
  type Folder,
  type MailDetail,
  type MailListItem,
  type User,
} from '../api';
import { ComposePopup, composeFromMessage, type ComposeState } from './ComposePopup';
import { EmailSettings } from './EmailSettings';
import { FileTypeIcon } from './FileTypeIcon';
import { GmailAttachments } from './GmailAttachments';
import { InlineReply } from './InlineReply';
import { MailboxLogin } from './MailboxLogin';

const FOLDER_TITLES: Record<string, string> = {
  inbox: 'Inbox',
  starred: 'Starred',
  sent: 'Sent',
  drafts: 'Drafts',
  trash: 'Trash',
  spam: 'Spam',
};

type IconComponent = ComponentType<{ className?: string; size?: number | string }>;

const FOLDER_ICONS: Record<string, IconComponent> = {
  inbox: MdInbox,
  starred: MdStar,
  sent: MdSend,
  drafts: MdDrafts,
  trash: MdDelete,
  spam: MdReport,
};

function smartRepliesFor(message: MailDetail): string[] {
  const text = `${message.subject || ''} ${message.body_text || ''} ${message.snippet || ''}`.toLowerCase();
  if (/how are you|how're you|how r you|how're u|how are u/.test(text)) {
    return ['Good and you?', 'Pretty good.', 'Glad to hear it!'];
  }
  if (/thank|thanks|thx/.test(text)) {
    return ["You're welcome!", 'Happy to help.', 'Anytime.'];
  }
  if (/meeting|schedule|call|available/.test(text)) {
    return ['Sounds good.', 'Let me confirm.', "I'm available."];
  }
  if (/hello|hi |hey|hellow/.test(text)) {
    return ['Hi!', 'Hello — how can I help?', 'Good to hear from you.'];
  }
  return ['Thanks!', 'Got it.', 'Will do.'];
}

type Props = {
  user: User;
  account: Account | null;
  initialFolders: Folder[];
  welcomeMessage?: string;
  isMailAdmin?: boolean;
  onLogout: () => void;
};

export function MailApp({
  user,
  account: initialAccount,
  initialFolders,
  welcomeMessage = '',
  isMailAdmin = false,
  onLogout,
}: Props) {
  const [folders, setFolders] = useState(initialFolders);
  const [account, setAccount] = useState(initialAccount);
  const [folder, setFolder] = useState('inbox');
  const [view, setView] = useState<'mail' | 'settings' | 'claim'>(
    initialAccount ? 'mail' : isMailAdmin ? 'settings' : 'claim',
  );
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  const [messages, setMessages] = useState<MailListItem[]>([]);
  const [selected, setSelected] = useState<MailDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [toast, setToast] = useState(welcomeMessage);
  const [compose, setCompose] = useState<ComposeState>({ open: false });
  const [inlineReply, setInlineReply] = useState(false);
  const [replyDraft, setReplyDraft] = useState('');
  const [selectMode, setSelectMode] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [settingsFocusId, setSettingsFocusId] = useState<number | null>(null);
  const [requirePassword, setRequirePassword] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const folderRef = useRef(folder);
  const queryRef = useRef(query);
  const viewRef = useRef(view);
  const syncingRef = useRef(false);
  const authHintShownRef = useRef(false);
  folderRef.current = folder;
  queryRef.current = query;
  viewRef.current = view;

  const title =
    view === 'settings' ? 'Email settings' : view === 'claim' ? 'Mailbox login' : FOLDER_TITLES[folder] || folder;
  const displayName = user.username;
  const hasUnread = folders.some((f) => f.unread_count > 0);
  const focusMailboxSetup = !account && (view === 'claim' || view === 'settings');

  async function refreshFolders() {
    try {
      const data = await api.folders();
      setFolders(data.folders);
      setAccount(data.account);
    } catch {
      // no account yet
      setFolders([]);
      setAccount(null);
    }
  }

  async function afterMailboxConnected() {
    await refreshFolders();
    setView('mail');
    setFolder('inbox');
    void loadMessages('inbox', '');
  }

  async function loadMessages(nextFolder = folder, nextQuery = query) {
    setLoading(true);
    try {
      const data = await api.messages(nextFolder, nextQuery);
      setMessages(data.messages);
      setSelected(null);
      setInlineReply(false);
      setReplyDraft('');
    } catch (err) {
      setToast(err instanceof Error ? err.message : 'Failed to load mail');
      setMessages([]);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    if (view !== 'mail' && !query) return;
    if (view === 'settings' && !query) return;
    void loadMessages(folder, query);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [folder, query, view]);

  useEffect(() => {
    const term = q.trim();
    const timer = window.setTimeout(() => {
      if (term === query) return;
      setQuery(term);
      if (term) {
        setView('mail');
        setSelected(null);
        setInlineReply(false);
        setSelectMode(false);
        setSelectedIds([]);
      }
    }, 320);
    return () => window.clearTimeout(timer);
  }, [q, query]);

  useEffect(() => {
    if (!toast) return;
    const ms = welcomeMessage && toast === welcomeMessage ? 8000 : 5000;
    const t = window.setTimeout(() => setToast(''), ms);
    return () => window.clearTimeout(t);
  }, [toast, welcomeMessage]);

  const listLabel = useMemo(() => {
    if (query) return `Results for “${query}”`;
    return null;
  }, [query]);

  const searching = query.trim().length > 0;
  const listTitle = searching ? 'Search' : title;

  const mailFolders = useMemo(
    () =>
      folders.filter((f) =>
        ['inbox', 'starred', 'sent', 'drafts', 'spam', 'trash'].includes(f.slug),
      ),
    [folders],
  );

  function selectFolder(slug: string) {
    setView('mail');
    setFolder(slug);
    setQ('');
    setQuery('');
    setSelected(null);
    setInlineReply(false);
    setSelectMode(false);
    setSelectedIds([]);
  }

  function openSettings(opts?: { editAccount?: boolean; requirePassword?: boolean }) {
    const needPassword = !!opts?.requirePassword;
    setRequirePassword(needPassword);
    setSettingsFocusId(
      needPassword || opts?.editAccount ? account?.id ?? null : null,
    );
    setView('settings');
    setSelected(null);
    setInlineReply(false);
    setSelectMode(false);
    setSelectedIds([]);
  }

  async function openMessage(item: MailListItem) {
    if (item.is_draft) {
      setCompose({
        open: true,
        title: 'Edit draft',
        to: item.to_display,
        subject: item.subject,
        body: '',
        draftId: item.id,
      });
      const detail = await api.message(item.id);
      setCompose({
        open: true,
        title: 'Edit draft',
        to: detail.message.to_display,
        subject: detail.message.subject,
        body: detail.message.body_text || '',
        draftId: detail.message.id,
      });
      return;
    }
    const data = await api.message(item.id);
    setSelected(data.message);
    setInlineReply(false);
    setReplyDraft('');
    setMessages((prev) =>
      prev.map((m) => (m.id === item.id ? { ...m, is_read: true } : m)),
    );
    void refreshFolders();
  }

  async function toggleStar(id: number) {
    const res = await api.star(id);
    setMessages((prev) =>
      prev.map((m) => (m.id === id ? { ...m, is_starred: res.is_starred } : m)),
    );
    if (selected?.id === id) {
      setSelected({ ...selected, is_starred: res.is_starred });
    }
  }

  async function trashMessage(id: number) {
    const res = await api.trash(id);
    setToast(res.message);
    setSelected(null);
    await loadMessages();
    await refreshFolders();
  }

  async function syncMail(opts?: { quiet?: boolean }) {
    if (syncingRef.current) return;
    syncingRef.current = true;
    setSyncing(true);
    try {
      const res = await api.sync();
      const authHint =
        typeof res.message === 'string' &&
        /imap login failed|mailbox password/i.test(res.message);

      if (!opts?.quiet) {
        setToast(res.message);
        if (authHint) {
          openSettings({ editAccount: true, requirePassword: true });
        }
      } else if ((res.imported ?? 0) > 0) {
        setToast(res.message);
      } else if (authHint && !authHintShownRef.current) {
        authHintShownRef.current = true;
        setToast(res.message);
        openSettings({ editAccount: true, requirePassword: true });
      }

      await refreshFolders();
      if (viewRef.current === 'mail') {
        // Refresh quietly — never flip the list back to "Loading…" during sync.
        try {
          const data = await api.messages(folderRef.current, queryRef.current);
          setMessages(data.messages);
        } catch {
          // keep current list
        }
      }
    } catch (err) {
      if (!opts?.quiet) {
        setToast(err instanceof Error ? err.message : 'Sync failed');
      }
    } finally {
      syncingRef.current = false;
      setSyncing(false);
    }
  }

  const syncMailRef = useRef(syncMail);
  syncMailRef.current = syncMail;

  useEffect(() => {
    if (!initialAccount && !account) return;
    void syncMailRef.current({ quiet: true });
    const timer = window.setInterval(() => {
      void syncMailRef.current({ quiet: true });
    }, 60000);
    return () => window.clearInterval(timer);
  }, [account?.id, initialAccount?.id]);

  return (
    <div
      className={`app-shell ${collapsed ? 'nav-collapsed' : ''} ${focusMailboxSetup ? 'setup-only' : ''}`}
    >
      {toast ? (
        <div className="toast" role="status">
          <span className="toast-msg">{toast}</span>
          <button
            type="button"
            className="toast-close"
            aria-label="Dismiss"
            onClick={() => setToast('')}
          >
            <MdClose size={18} aria-hidden />
          </button>
        </div>
      ) : null}

      {!focusMailboxSetup ? (
      <aside className="sidebar">
        {account ? (
          <div className="side-profile">
            <button
              type="button"
              className="side-profile-btn"
              title="Email & account settings"
              onClick={() => openSettings()}
            >
              <div className={`side-avatar ${hasUnread ? 'has-unread' : ''}`} aria-hidden>
                {(account.display_name || account.email).slice(0, 1).toUpperCase()}
                {hasUnread ? <span className="side-avatar-dot" /> : null}
              </div>
              <div className="side-profile-text">
                <div className="side-user-name">
                  {account.display_name || account.email.split('@')[0]}
                </div>
                <div className="side-user-role">{account.email}</div>
              </div>
            </button>
            <button
              type="button"
              className="side-collapse"
              aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
              onClick={() => setCollapsed((v) => !v)}
            >
              <MdMenu size={20} aria-hidden />
            </button>
          </div>
        ) : (
          <div className="side-brand">
            <button
              type="button"
              className="side-collapse"
              aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
              onClick={() => setCollapsed((v) => !v)}
            >
              <MdMenu size={20} aria-hidden />
            </button>
          </div>
        )}

        <nav className="side-nav">
          <div className="side-section">
            <div className="side-label">Main</div>
            {mailFolders.map((f) => {
              const active = view === 'mail' && folder === f.slug;
              const Icon = FOLDER_ICONS[f.slug] || FOLDER_ICONS.inbox;
              const showBadge = f.unread_count > 0;
              return (
                <button
                  key={f.id}
                  type="button"
                  className={`side-item ${active ? 'active' : ''}`}
                  title={f.name}
                  onClick={() => selectFolder(f.slug)}
                >
                  <span className="side-ico">
                    <Icon size={18} aria-hidden />
                    {collapsed && showBadge ? <span className="side-dot" /> : null}
                  </span>
                  <span className="side-item-label">{f.name}</span>
                  {showBadge ? <span className="side-badge">{f.unread_count}</span> : null}
                </button>
              );
            })}
          </div>

          <div className="side-section">
            <div className="side-label">Quick</div>
            <button
              type="button"
              className="side-item"
              title="Compose"
              onClick={() => setCompose({ open: true, title: 'New Message' })}
            >
              <span className="side-ico">
                <MdEdit size={18} aria-hidden />
              </span>
              <span className="side-item-label">Compose</span>
            </button>
          </div>
        </nav>

        <div className="side-footer">
          <button
            type="button"
            className="side-item side-signout"
            title="Sign out"
            onClick={async () => {
              await api.logout();
              onLogout();
            }}
          >
            <span className="side-ico">
              <MdLogout size={18} aria-hidden />
            </span>
            <span className="side-item-label">Sign out</span>
          </button>
        </div>
      </aside>
      ) : null}

      <div className="workspace">
        {!focusMailboxSetup ? (
        <header className="topbar">
          <form
            className="search"
            onSubmit={(e) => {
              e.preventDefault();
              const term = q.trim();
              setQuery(term);
              setView('mail');
              setSelected(null);
              setInlineReply(false);
              setSelectMode(false);
              setSelectedIds([]);
            }}
          >
            <MdSearch size={20} aria-hidden />
            <input
              value={q}
              onChange={(e) => setQ(e.target.value)}
              placeholder="Search mail..."
              aria-label="Search mail"
            />
            {q ? (
              <button
                type="button"
                className="search-clear"
                aria-label="Clear search"
                onClick={() => {
                  setQ('');
                  setQuery('');
                }}
              >
                <MdClose size={18} aria-hidden />
              </button>
            ) : null}
          </form>
          <div className="topbar-actions">
            <button
              type="button"
              className="sync-mail-btn"
              title={account ? `Sync ${account.email}` : 'Sync mail'}
              onClick={() => void syncMail()}
              disabled={syncing}
            >
              <MdRefresh size={18} aria-hidden className={syncing ? 'spin' : undefined} />
              {syncing ? 'Syncing…' : 'Sync mail'}
            </button>
            <button
              type="button"
              className="new-mail-btn"
              onClick={() => setCompose({ open: true, title: 'New Message' })}
            >
              New mail
            </button>
          </div>
        </header>
        ) : null}

        <section className={`main ${focusMailboxSetup ? 'main-setup' : ''}`}>
          {view === 'claim' && !account ? (
            <MailboxLogin
              isMailAdmin={isMailAdmin}
              onToast={setToast}
              onOpenAdmin={() => setView('settings')}
              onConnected={() => void afterMailboxConnected()}
            />
          ) : view === 'settings' || (!account && view !== 'mail') ? (
            <EmailSettings
              preferredEmail={
                user.email ||
                (typeof window !== 'undefined' &&
                window.location.hostname.includes('roadmasterspares.com')
                  ? 'sales@roadmasterspares.com'
                  : user.email)
              }
              preferredDisplayName=""
              focusAccountId={settingsFocusId}
              requirePassword={requirePassword}
              isMailAdmin={isMailAdmin}
              teamPoolMode={!account && isMailAdmin}
              onPasswordSaved={() => {
                setRequirePassword(false);
                authHintShownRef.current = false;
              }}
              onToast={setToast}
              onAccountsChanged={() => {
                void refreshFolders().then(() => {
                  if (account || isMailAdmin) {
                    // Admin creating pool may still have no personal account.
                    void api.folders()
                      .then((data) => {
                        setFolders(data.folders);
                        setAccount(data.account);
                        if (data.account) {
                          setView('mail');
                          setFolder('inbox');
                        } else {
                          setView('claim');
                        }
                      })
                      .catch(() => setView('claim'));
                  } else {
                    setView('mail');
                    setFolder('inbox');
                  }
                });
              }}
              onBackToClaim={
                !account
                  ? () => setView('claim')
                  : undefined
              }
            />
          ) : selected ? (
            <div className="read">
              <div className="read-actions">
                <button className="tool" type="button" onClick={() => { setInlineReply(false); setReplyDraft(''); setSelected(null); }}>
                  <MdArrowBack size={18} aria-hidden />
                  Back
                </button>
                <button className="tool" type="button" onClick={() => void trashMessage(selected.id)}>
                  <MdDelete size={18} aria-hidden />
                  Delete
                </button>
                <button className="tool" type="button" onClick={() => void toggleStar(selected.id)}>
                  {selected.is_starred ? (
                    <MdStar size={18} aria-hidden />
                  ) : (
                    <MdStarBorder size={18} aria-hidden />
                  )}
                  {selected.is_starred ? 'Unstar' : 'Star'}
                </button>
                <button
                  className="tool"
                  type="button"
                  onClick={() => {
                    setReplyDraft('');
                    setInlineReply(true);
                  }}
                >
                  <MdReply size={18} aria-hidden />
                  Reply
                </button>
                <button
                  className="tool"
                  type="button"
                  onClick={() => setCompose(composeFromMessage(selected, 'forward', { folder }))}
                >
                  <MdForward size={18} aria-hidden />
                  Forward
                </button>
              </div>
              <h2>{selected.subject}</h2>
              <div className="read-head">
                <div className="avatar">{selected.from_display.slice(0, 1).toUpperCase()}</div>
                <div>
                  <strong>{selected.from_display}</strong>
                  <div className="muted">&lt;{selected.from_email}&gt;</div>
                  <div className="muted">to {selected.to_display || 'me'}</div>
                </div>
                <div className="muted">{selected.date_full}</div>
              </div>
              <div
                className="read-body"
                dangerouslySetInnerHTML={{ __html: selected.body_html }}
              />
              <GmailAttachments attachments={selected.attachments} />
              {!inlineReply ? (
                <div className="read-actions-block">
                  <div className="smart-replies">
                    {smartRepliesFor(selected).map((suggestion) => (
                      <button
                        key={suggestion}
                        type="button"
                        className="smart-reply-pill"
                        onClick={() => {
                          setReplyDraft(suggestion);
                          setInlineReply(true);
                        }}
                      >
                        {suggestion}
                      </button>
                    ))}
                  </div>
                  <div className="read-pills">
                    <button
                      type="button"
                      className="gmail-pill"
                      onClick={() => {
                        setReplyDraft('');
                        setInlineReply(true);
                      }}
                    >
                      <MdReply size={18} aria-hidden />
                      Reply
                    </button>
                    <button
                      type="button"
                      className="gmail-pill"
                      onClick={() => setCompose(composeFromMessage(selected, 'forward', { folder }))}
                    >
                      <MdForward size={18} aria-hidden />
                      Forward
                    </button>
                  </div>
                </div>
              ) : null}
              {inlineReply ? (
                <InlineReply
                  message={selected}
                  userLabel={displayName}
                  folder={folder}
                  initialBody={replyDraft}
                  onClose={() => {
                    setInlineReply(false);
                    setReplyDraft('');
                  }}
                  onPopOut={(state) => setCompose(state)}
                  onSent={(msg) => {
                    setToast(msg);
                    setReplyDraft('');
                    void loadMessages();
                    void refreshFolders();
                  }}
                />
              ) : null}
            </div>
          ) : (
            <>
              <div className="toolbar">
                <h1>{listTitle}</h1>
                <button
                  type="button"
                  className={`toolbar-select ${selectMode ? 'active' : ''}`}
                  onClick={() => {
                    setSelectMode((v) => {
                      if (v) setSelectedIds([]);
                      return !v;
                    });
                  }}
                >
                  {selectMode ? 'Cancel' : 'Select'}
                </button>
                {selectMode && selectedIds.length > 0 ? (
                  <span className="toolbar-selected muted">
                    {selectedIds.length} selected
                  </span>
                ) : null}
                {listLabel ? <span className="muted">{listLabel}</span> : null}
              </div>
              <div className="mail-list">
                {loading ? (
                  <div className="empty">Loading…</div>
                ) : messages.length === 0 ? (
                  <div className="empty">
                    <h2>{searching ? 'No results' : 'No conversations'}</h2>
                    <p>
                      {searching
                        ? `Nothing matched “${query}”.`
                        : `Nothing in ${title} yet.`}
                    </p>
                  </div>
                ) : (
                  messages.map((m) => {
                    const checked = selectedIds.includes(m.id);
                    const name =
                      (!searching && (folder === 'sent' || folder === 'drafts')) ||
                      m.folder_slug === 'sent' ||
                      m.folder_slug === 'drafts'
                        ? m.to_display || '(no recipients)'
                        : m.from_display || m.from_email || '?';
                    return (
                    <div
                      key={m.id}
                      className={`mail-row ${m.is_read ? '' : 'unread'} ${checked ? 'is-checked' : ''} ${selectMode ? 'select-mode' : ''}`}
                    >
                      {selectMode ? (
                        <label className="mail-check">
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={(e) => {
                              const on = e.target.checked;
                              setSelectedIds((prev) =>
                                on ? [...prev, m.id] : prev.filter((id) => id !== m.id),
                              );
                            }}
                            aria-label={`Select ${m.subject}`}
                          />
                        </label>
                      ) : (
                        <button
                          type="button"
                          className={`star ${m.is_starred ? 'on' : ''}`}
                          onClick={() => void toggleStar(m.id)}
                          aria-label="Star"
                        >
                          ★
                        </button>
                      )}
                      <button type="button" className="mail-link" onClick={() => {
                        if (selectMode) {
                          setSelectedIds((prev) =>
                            prev.includes(m.id)
                              ? prev.filter((id) => id !== m.id)
                              : [...prev, m.id],
                          );
                          return;
                        }
                        void openMessage(m);
                      }}>
                        <span className="from">
                          <span className="from-name">{name}</span>
                          {!m.is_read ? <span className="new-badge">New</span> : null}
                        </span>
                        <span className="content">
                          <span className="line">
                            {searching && m.folder_slug ? (
                              <span className="folder-chip">
                                {FOLDER_TITLES[m.folder_slug] || m.folder_slug}
                              </span>
                            ) : null}
                            <span className="subject">{m.subject}</span>
                            <span className="snippet"> — {m.snippet}</span>
                          </span>
                          {(m.attachments?.length ?? 0) > 0 ? (
                            <span className="attach-row">
                              {m.attachments!.map((a) => {
                                const short =
                                  a.filename.length > 16
                                    ? `${a.filename.slice(0, 14)}…`
                                    : a.filename;
                                return (
                                  <span key={a.id} className="attach-chip" title={a.filename}>
                                    <FileTypeIcon attachment={a} />
                                    <span className="attach-name">{short}</span>
                                  </span>
                                );
                              })}
                            </span>
                          ) : null}
                        </span>
                        <span className="meta">
                          <span className="date">{m.date_label}</span>
                        </span>
                      </button>
                    </div>
                    );
                  })
                )}
              </div>
            </>
          )}
        </section>
      </div>

      <ComposePopup
        state={compose}
        onClose={() => setCompose({ open: false })}
        onSent={(msg) => {
          setToast(msg);
          setView('mail');
          setFolder('sent');
          setSelected(null);
          void refreshFolders().then(() => void loadMessages('sent'));
        }}
      />
    </div>
  );
}
