declare global {
  interface Window {
    __MAIL_API_BASE__?: string;
    __MAIL_WEB_BASE__?: string;
  }
}

/** Resolve API root: PHP inject → URL detect → Vite base. Always goes through index.php. */
function resolveApiBase(): string {
  if (typeof window !== 'undefined' && window.__MAIL_API_BASE__) {
    return window.__MAIL_API_BASE__.replace(/\/$/, '');
  }

  let base = '';
  if (typeof window !== 'undefined') {
    if (window.__MAIL_WEB_BASE__) {
      base = window.__MAIL_WEB_BASE__.replace(/\/$/, '');
    } else {
      const path = window.location.pathname;
      const web = path.match(/^(.*?\/frontend\/web)(?:\/|$)/);
      if (web) {
        base = web[1];
      } else {
        const app = path.match(/^(.*)\/app(?:\/|$)/);
        if (app) {
          base = app[1].replace(/\/$/, '');
        }
      }
    }
  }
  if (!base) {
    base = (import.meta.env.BASE_URL || '/')
      .replace(/\/?app\/?$/, '')
      .replace(/\/$/, '');
  }
  return `${base}/index.php`;
}

function apiBase(): string {
  return resolveApiBase();
}

let csrfToken = '';
let csrfParam = '_csrf-frontend';

export type Folder = {
  id: number;
  name: string;
  slug: string;
  unread_count: number;
  sort_order: number;
};

export type Account = {
  id: number;
  email: string;
  display_name: string;
  account_type?: string;
  last_synced_at: number | null;
};

export type AccountDetail = Account & {
  imap_host: string;
  imap_port: number;
  imap_encryption: string;
  imap_username: string;
  smtp_host: string;
  smtp_port: number;
  smtp_encryption: string;
  smtp_username: string;
  is_active: boolean;
  has_imap_password: boolean;
  has_smtp_password: boolean;
};

export type AccountInput = {
  email: string;
  display_name: string;
  account_type?: string;
  imap_host: string;
  imap_port: number;
  imap_encryption: string;
  imap_username: string;
  imap_password?: string;
  smtp_host: string;
  smtp_port: number;
  smtp_encryption: string;
  smtp_username: string;
  smtp_password?: string;
};

export type User = {
  id: number;
  username: string;
  email: string;
};

export type MailListItem = {
  id: number;
  folder_id: number;
  folder_slug: string | null;
  from_email: string;
  from_name: string;
  from_display: string;
  to_display: string;
  subject: string;
  snippet: string;
  is_read: boolean;
  is_starred: boolean;
  is_draft: boolean;
  has_attachments: boolean;
  attachments?: Attachment[];
  date_sent: number | null;
  date_label: string;
};

export type Attachment = {
  id: number;
  filename: string;
  mime_type: string;
  size: number;
  size_label: string;
  is_pdf: boolean;
  is_image: boolean;
};

export type MailDetail = MailListItem & {
  body_html: string;
  body_text: string | null;
  cc_display: string;
  message_id_header: string | null;
  date_full: string;
  attachments: Attachment[];
};

export type Bootstrap = {
  ok: boolean;
  csrf: string;
  csrfParam: string;
  authenticated: boolean;
  user: User | null;
  account: Account | null;
  folders?: Folder[];
  message?: string;
  is_mail_admin?: boolean;
  available_mailboxes?: number;
};

export type PoolMailbox = {
  id: number;
  email: string;
  display_name: string;
  account_type?: string;
  imap_host?: string;
  imap_port?: number;
  smtp_host?: string;
  smtp_port?: number;
  has_password?: boolean;
  created_at?: number;
};

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers || {});
  if (csrfToken) {
    headers.set('X-CSRF-Token', csrfToken);
  }
  if (!(options.body instanceof FormData) && options.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  const url = `${apiBase()}${path}`;
  const res = await fetch(url, {
    ...options,
    headers,
    credentials: 'same-origin',
  });

  const contentType = res.headers.get('content-type') || '';
  if (!contentType.includes('application/json')) {
    if (!res.ok) throw new Error(`Request failed (${res.status}) ${url}`);
    throw new Error(`Expected JSON from ${url}, got ${contentType || 'unknown'}`);
  }

  const data = await res.json();
  if (!res.ok) {
    throw new Error(data?.message || `Request failed (${res.status}) ${url}`);
  }
  return data as T;
}

export const api = {
  async bootstrap() {
    const data = await request<Bootstrap>('/api/bootstrap');
    csrfToken = data.csrf;
    csrfParam = data.csrfParam;
    return data;
  },

  async login(username: string, password: string, rememberMe = true) {
    const body = new URLSearchParams();
    body.set('username', username);
    body.set('password', password);
    body.set('rememberMe', rememberMe ? '1' : '0');
    if (csrfToken) body.set(csrfParam, csrfToken);

    const data = await request<Bootstrap>('/api/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    csrfToken = data.csrf;
    return data;
  },

  async signup(payload: {
    username: string;
    email: string;
    password: string;
    password_confirm: string;
  }) {
    const data = await request<Bootstrap & { message?: string }>('/api/signup', {
      method: 'POST',
      body: JSON.stringify({
        ...payload,
        [csrfParam]: csrfToken,
      }),
    });
    csrfToken = data.csrf;
    return data;
  },

  logout() {
    return request<{ ok: boolean }>('/api/logout', { method: 'POST' });
  },

  signOutMailbox() {
    return request<{ ok: boolean; message?: string; mailboxes?: PoolMailbox[] }>(
      '/api/sign-out-mailbox',
      { method: 'POST' },
    );
  },

  folders() {
    return request<{ ok: boolean; folders: Folder[]; account: Account }>('/api/folders');
  },

  messages(folder: string, q = '') {
    const params = new URLSearchParams({ folder });
    if (q) params.set('q', q);
    return request<{ ok: boolean; folder: string; messages: MailListItem[] }>(
      `/api/messages?${params.toString()}`,
    );
  },

  message(id: number) {
    return request<{ ok: boolean; message: MailDetail }>(`/api/messages/${id}`);
  },

  star(id: number) {
    return request<{ ok: boolean; is_starred: boolean }>(`/api/star/${id}`, { method: 'POST' });
  },

  trash(id: number) {
    return request<{ ok: boolean; message: string }>(`/api/trash/${id}`, { method: 'POST' });
  },

  sync() {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 25000);
    return request<{ ok: boolean; message: string; imported?: number }>('/api/sync', {
      method: 'POST',
      signal: controller.signal,
    }).finally(() => window.clearTimeout(timer));
  },

  availableMailboxes() {
    return request<{ ok: boolean; mailboxes: PoolMailbox[] }>('/api/available-mailboxes');
  },

  claimMailbox(payload: { id?: number; email: string; password: string }) {
    return request<{
      ok: boolean;
      message: string;
      account: Account;
      folders: Folder[];
    }>('/api/claim-mailbox', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  poolAccounts() {
    return request<{ ok: boolean; mailboxes: PoolMailbox[] }>('/api/pool-accounts');
  },

  createPoolAccount(payload: {
    email: string;
    display_name?: string;
    password: string;
    imap_host?: string;
    imap_port?: number;
    smtp_host?: string;
    smtp_port?: number;
  }) {
    return request<{ ok: boolean; message: string; mailbox: PoolMailbox }>('/api/pool-accounts', {
      method: 'POST',
      body: JSON.stringify({
        ...payload,
        imap_password: payload.password,
        smtp_password: payload.password,
      }),
    });
  },

  deletePoolAccount(id: number) {
    return request<{ ok: boolean; message: string }>(`/api/pool-accounts/${id}`, {
      method: 'DELETE',
    });
  },

  send(form: FormData) {
    if (csrfToken) form.set(csrfParam, csrfToken);
    return request<{ ok: boolean; message: string; id?: number }>('/api/send', {
      method: 'POST',
      body: form,
    });
  },

  draft(form: FormData) {
    if (csrfToken) form.set(csrfParam, csrfToken);
    return request<{ ok: boolean; message: string; id?: number }>('/api/draft', {
      method: 'POST',
      body: form,
    });
  },

  attachmentUrl(id: number, mode: 'view' | 'download' = 'download') {
    return `${apiBase()}/api/attachments/${id}?mode=${mode}`;
  },

  accounts() {
    return request<{ ok: boolean; accounts: AccountDetail[] }>('/api/accounts');
  },

  createAccount(payload: AccountInput) {
    return request<{ ok: boolean; message: string; account: AccountDetail }>('/api/accounts', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  updateAccount(id: number, payload: AccountInput) {
    return request<{ ok: boolean; message: string; account: AccountDetail }>(`/api/accounts/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },

  deleteAccount(id: number) {
    return request<{ ok: boolean; message: string }>(`/api/accounts/${id}`, {
      method: 'DELETE',
    });
  },
};
