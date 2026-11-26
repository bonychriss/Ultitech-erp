import React, { useMemo, useState } from 'react';
import { postAttendanceAction } from '../api';
import '../attendance-desk.css';
import '../desk-extras.css';

function nameInitials(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (!parts.length) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
}

function PersonDayTimeline({ items }) {
  if (!Array.isArray(items) || items.length === 0) {
    return <p className="att-logs-empty-inline">No activity yet this day.</p>;
  }
  return (
    <ol className="att-logs-timeline-h">
      {items.map((item, index) => {
        const isLast = index === items.length - 1;
        return (
          <li
            key={item.id || `${item.type}-${item.at}-${index}`}
            className={`att-logs-step is-${item.tone || 'in'}${isLast ? ' is-latest' : ''}`}
          >
            <span className="att-logs-step-dot" aria-hidden="true">
              <i className={item.icon || 'fas fa-circle'} />
            </span>
            <div className="att-logs-step-copy">
              <span className="att-logs-step-title">{item.title}</span>
              <time className="att-logs-step-time" dateTime={item.at || undefined}>
                {item.time_label || '--:--'}
              </time>
              {item.detail ? <span className="att-logs-step-detail">{item.detail}</span> : null}
            </div>
            {!isLast ? <span className="att-logs-step-connector" aria-hidden="true" /> : null}
          </li>
        );
      })}
    </ol>
  );
}

export default function AttendanceLogs({ data }) {
  const user = data.user || {};
  const [todayLogs, setTodayLogs] = useState(() => (
    data.todayLogs && typeof data.todayLogs === 'object'
      ? data.todayLogs
      : { date: '', dateFrom: '', dateTo: '', dateLabel: '', rows: [], events: [] }
  ));
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const initialDate = todayLogs.dateFrom || todayLogs.date || '';
  const [dateFrom, setDateFrom] = useState(initialDate);
  const [dateTo, setDateTo] = useState(todayLogs.dateTo || initialDate);

  const rows = Array.isArray(todayLogs.rows) ? todayLogs.rows : [];
  const isRange = Boolean(todayLogs.dateFrom && todayLogs.dateTo && todayLogs.dateFrom !== todayLogs.dateTo);

  const dayGroups = useMemo(() => {
    const map = new Map();
    rows.forEach((row) => {
      const day = row.date || todayLogs.date || '';
      if (!map.has(day)) {
        map.set(day, {
          date: day,
          dateLabel: row.date_label || day,
          rows: [],
        });
      }
      map.get(day).rows.push(row);
    });
    return Array.from(map.values());
  }, [rows, todayLogs.date]);

  async function loadLogs(nextFrom, nextTo) {
    const from = nextFrom || dateFrom;
    const to = nextTo || dateTo || nextFrom || dateFrom;
    if (!from || !to) {
      setMessage('Choose both From and To dates.');
      return;
    }
    if (from > to) {
      setMessage('From date must be on or before To date.');
      return;
    }
    setBusy(true);
    setMessage('');
    try {
      const result = await postAttendanceAction({
        action: 'today_logs',
        date_from: from,
        date_to: to,
      });
      if (!result.success) {
        setMessage(result.message || 'Could not load logs.');
        return;
      }
      if (result.data?.todayLogs) {
        setTodayLogs(result.data.todayLogs);
        if (result.data.todayLogs.dateFrom) setDateFrom(result.data.todayLogs.dateFrom);
        if (result.data.todayLogs.dateTo) setDateTo(result.data.todayLogs.dateTo);
      }
    } catch (err) {
      setMessage(err instanceof Error ? err.message : 'Could not load logs.');
    } finally {
      setBusy(false);
    }
  }

  function renderPersonCard(row, isMe = false) {
    const name = row.full_name || row.username || '-';
    const timeline = Array.isArray(row.timeline) ? row.timeline : [];
    return (
      <article
        key={`${row.date || ''}-${row.user_id}`}
        className={`att-logs-card att-logs-card--person${isMe ? ' is-me' : ''}`}
        aria-label={`${name} day log`}
      >
        <div className="att-logs-card-body att-logs-card-body--inline">
          <div className="att-logs-person-start">
            <div className="att-logs-avatar" aria-hidden="true">{nameInitials(name)}</div>
            <div className="att-logs-card-meta">
              <div className="att-logs-card-name">
                {name}
                {isMe ? <span className="att-logs-you">You</span> : null}
              </div>
            </div>
          </div>
          <span className="att-logs-person-connector" aria-hidden="true" />
          <PersonDayTimeline items={timeline} />
        </div>
      </article>
    );
  }

  function renderDayRows(dayRows) {
    const myRow = dayRows.find((row) => Number(row.user_id) === Number(user.id || 0)) || null;
    const otherRows = dayRows.filter((row) => Number(row.user_id) !== Number(user.id || 0));
    return (
      <>
        {myRow ? renderPersonCard(myRow, true) : null}
        {otherRows.map((row) => renderPersonCard(row, false))}
      </>
    );
  }

  return (
    <div className="att-shell att-page-logs">
      <div className="att-main">
        <div className="att-top-bar att-top-bar--logs">
          <div className="att-logs-toolbar">
            <div className={`att-logs-range-card${busy ? ' is-busy' : ''}`}>
              <label className="att-logs-date-field">
                <span>From</span>
                <input
                  type="date"
                  className="att-logs-date-input"
                  value={dateFrom || ''}
                  max={dateTo || undefined}
                  disabled={busy}
                  onChange={(e) => {
                    const value = e.target.value;
                    setDateFrom(value);
                    if (value && dateTo) loadLogs(value, dateTo);
                  }}
                  aria-label="From date"
                />
              </label>
              <label className="att-logs-date-field">
                <span>To</span>
                <input
                  type="date"
                  className="att-logs-date-input"
                  value={dateTo || ''}
                  min={dateFrom || undefined}
                  disabled={busy}
                  onChange={(e) => {
                    const value = e.target.value;
                    setDateTo(value);
                    if (dateFrom && value) loadLogs(dateFrom, value);
                  }}
                  aria-label="To date"
                />
              </label>
            </div>
          </div>
        </div>

        {message ? <div className="att-alert-banner is-danger">{message}</div> : null}

        {rows.length === 0 ? (
          <div className="att-desk-empty att-logs-empty">
            <div className="att-desk-empty-icon" aria-hidden="true">
              <i className="fas fa-user-clock" />
            </div>
            <p className="att-desk-empty-title">No employee logs for this range</p>
            <p className="att-desk-empty-sub">
              When people clock in, each person&apos;s day story appears here.
            </p>
          </div>
        ) : (
          <div className="att-logs-stack">
            <p className="att-logs-count">
              {rows.length} day log{rows.length === 1 ? '' : 's'}
              {isRange ? ' in this range' : ' for this day'}
            </p>
            {isRange
              ? dayGroups.map((group) => (
                <section key={group.date} className="att-logs-day-group">
                  <h3 className="att-logs-day-heading">{group.dateLabel}</h3>
                  <div className="att-logs-day-stack">
                    {renderDayRows(group.rows)}
                  </div>
                </section>
              ))
              : renderDayRows(rows)}
          </div>
        )}
      </div>
    </div>
  );
}
